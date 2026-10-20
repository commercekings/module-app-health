#!/usr/bin/env bash
# The server's status for App health's "external" check: containers (state, health, restarts, CPU, memory, network and
# block I/O), disk, load, memory and - when set - services,
# backup age and certificate expiry, written as {"report": {...}} to Magento's var/app-health/status-<source>.json
# (a temporary file in the same folder, then renamed: Magento never reads half a file). Magento is not started; its
# cron reads the file every 5 minutes. Run this every minute on the server (cron or a systemd timer, see the README).
# Only names and numbers go into the file; the script never reads or writes env files, passwords or tokens.
#
# Settings, in /etc/ck-host-status.env (or the file in CK_ENV_FILE), as VAR=value lines:
#   CK_STATUS_DIR    required: Magento's var/app-health as this server sees it, e.g.
#                    /opt/magento/shared/var/app-health (Docker: the host folder mounted as var/)
#   CK_SOURCE        this server's name in the alerts (default: the host name), [A-Za-z0-9._-], at most 40
#   CK_CONTAINERS    containers that must be running, empty for none (default: mariadb php cron rabbitmq opensearch cache session
#                    varnish nginx); or CK_COMPOSE_PROJECT: every container of that Compose project (its label)
#   CK_CONTAINER_MEM_WARN / CK_CONTAINER_CPU_WARN  a container's memory % of its limit / CPU % (one core = 100) above
#                    which it warns (default: 85 / 90); down, restarting or unhealthy fails
#   DOCKER_HOST      (the environment) another Docker endpoint, e.g. tcp://docker-socket-proxy:2375 in a container
#   CK_DISKS         mount points (default: /); CK_DISK_WARN / CK_DISK_FAIL percent used (default: 80 / 90)
#   CK_MEM_WARN / CK_MEM_FAIL  percent of memory available below which it warns / fails (default: 10 / 5)
#   CK_LOAD_WARN / CK_LOAD_FAIL  1-minute load per CPU (default: 2 / 4)
#   CK_SERVICES      systemd units that must be active (default: none, e.g. "docker")
#   CK_BACKUP_FILE   a file your backup touches when it succeeds; CK_BACKUP_WARN_HOURS / _FAIL_HOURS (default 26 / 50)
#   CK_SSL_HOSTS     host names whose certificate expiry is checked on port 443; CK_SSL_WARN_DAYS / _FAIL_DAYS
#                    (default 14 / 7)
# Options: --dry-run prints the JSON instead of writing it.
#
# Needs bash, jq, docker, setpriv (util-linux) when run as root; openssl for CK_SSL_HOSTS; systemctl for CK_SERVICES.
set -euo pipefail
umask 077

env_file="${CK_ENV_FILE:-/etc/ck-host-status.env}"
if [[ -f "$env_file" ]]; then
    # read VAR=value lines for our CK_ settings only; nothing else of the file is used
    while IFS='=' read -r key value; do
        if [[ "$key" =~ ^CK_[A-Z_]+$ ]]; then
            value="${value%\"}"
            export "$key=${value#\"}"
        fi
    done < "$env_file"
fi

source_name="${CK_SOURCE:-$(hostname -s)}"
dry_run=0
[[ "${1:-}" == "--dry-run" ]] && dry_run=1
if [[ ! "$source_name" =~ ^[A-Za-z0-9._-]{1,40}$ ]]; then
    echo "host-status: CK_SOURCE must be 1-40 characters of A-Z a-z 0-9 . _ -" >&2
    exit 1
fi
if (( ! dry_run )) && [[ -z "${CK_STATUS_DIR:-}" ]]; then
    echo "host-status: set CK_STATUS_DIR (Magento's var/app-health) in $env_file" >&2
    exit 1
fi

checks='[]'
# add CODE LABEL STATUS VALUE UNIT DETAIL [METRICS] (VALUE may be "" for none; METRICS a JSON object of numbers)
add() {
    # $a_* names: "label" is a jq keyword (jq 1.5+ can't take $label)
    checks=$(jq -c --arg a_code "$1" --arg a_label "$2" --arg a_status "$3" --arg a_value "$4" --arg a_unit "$5" \
        --arg a_detail "$6" --argjson a_metrics "${7:-null}" '. + [{code: $a_code, label: $a_label, status: $a_status,
            value: (if $a_value == "" then null else ($a_value | tonumber) end), unit: $a_unit, detail: $a_detail}
            + (if $a_metrics == null or $a_metrics == {} then {} else {metrics: $a_metrics} end)]' \
        <<< "$checks")
}
# code: PREFIX and a name as lower case letters, digits and "_", at most 40 in all; a longer one keeps its start and
# gets a short hash of the whole, so two long names stay two codes
code() {
    local full
    full="$2$(tr 'A-Z' 'a-z' <<< "$1" | tr -c 'a-z0-9_\n' '_')"
    if (( ${#full} > 40 )); then
        full="${full:0:33}_$(printf '%s' "$full" | sha1sum | cut -c1-6)"
    fi
    printf '%s\n' "$full"
}
# status of VALUE against WARN and FAIL limits; "up" = higher is worse, "down" = lower is worse
level() {
    awk -v v="$1" -v w="$2" -v f="$3" -v d="$4" 'BEGIN {
        if (d == "up") s = (v >= f) ? "fail" : (v >= w) ? "warn" : "ok";
        else s = (v <= f) ? "fail" : (v <= w) ? "warn" : "ok";
        print s }'
}

# containers: state, health and restarts (docker inspect), and while running CPU, memory, network and block I/O
# (one docker stats for all) - through the Docker CLI, so DOCKER_HOST (e.g. a socket proxy) works too
if [[ -n "${CK_COMPOSE_PROJECT:-}" ]]; then
    containers=$(docker ps -a --filter "label=com.docker.compose.project=$CK_COMPOSE_PROJECT" --format '{{.Names}}' \
        | sort)
else
    containers=${CK_CONTAINERS-mariadb php cron rabbitmq opensearch cache session varnish nginx}
fi
running=()
for name in $containers; do
    if docker inspect --format '{{.State.Status}}' "$name" 2>/dev/null | grep -qx running; then
        running+=("$name")
    fi
done
stats='{}'
if (( ${#running[@]} )); then
    # "12.34%", "512MiB / 2GiB", "1.2kB / 3.4MB": numbers and bytes, by name
    stats=$(docker stats --no-stream --format '{{json .}}' "${running[@]}" 2>/dev/null | jq -c -s '
        def bytes: (capture("^(?<n>[0-9.]+)\\s*(?<u>[A-Za-z]*)$") // {n: "0", u: "B"}) as $c
            | ($c.n | tonumber) * ({"": 1, "B": 1, "kB": 1e3, "KB": 1e3, "MB": 1e6, "GB": 1e9, "TB": 1e12,
                "KiB": 1024, "MiB": 1048576, "GiB": 1073741824, "TiB": 1099511627776}[$c.u] // 1) | floor;
        def pair: split(" / ") | map(bytes);
        map({key: .Name, value: {cpu_percent: (.CPUPerc | rtrimstr("%") | tonumber? // 0),
            mem_percent: (.MemPerc | rtrimstr("%") | tonumber? // 0),
            mem: (.MemUsage | pair), net: (.NetIO | pair), blk: (.BlockIO | pair)}}) | from_entries' 2>/dev/null \
        || echo '{}')
fi
for name in $containers; do
    code="$(code "$name" container_)"
    if ! state=$(docker inspect --format '{{.State.Status}} {{.RestartCount}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null); then
        add "$code" "Container $name" fail "" "" "not found"
        continue
    fi
    read -r running_state restarts health <<< "$state"
    detail="$running_state"
    [[ "$health" != none ]] && detail="$detail, $health"
    metrics=$(jq -c --arg n "$name" --argjson r "$restarts" '.[$n] // {} | if . == {} then {restarts: $r} else
        {cpu_percent: (.cpu_percent * 100 | round / 100), mem_used_bytes: .mem[0], mem_limit_bytes: .mem[1],
            net_rx_bytes: .net[0], net_tx_bytes: .net[1], blk_read_bytes: .blk[0], blk_write_bytes: .blk[1],
            restarts: $r} end' <<< "$stats")
    cpu=$(jq -r '.cpu_percent // empty' <<< "$metrics")
    mem=$(jq -r --arg n "$name" '.[$n].mem_percent // empty' <<< "$stats")
    status=ok
    if [[ "$running_state" != running || "$health" == unhealthy ]]; then
        status=fail # down, restarting or unhealthy
    elif [[ "$health" == starting ]] \
        || [[ -n "$mem" && "$(level "$mem" "${CK_CONTAINER_MEM_WARN:-85}" 1000 up)" != ok ]] \
        || [[ -n "$cpu" && "$(level "$cpu" "${CK_CONTAINER_CPU_WARN:-90}" 1000000 up)" != ok ]]; then
        status=warn
    fi
    [[ -n "$cpu" ]] && detail="$detail, CPU ${cpu}%"
    add "$code" "Container $name" "$status" "${mem:+$(printf '%.1f' "$mem")}" "${mem:+%}" "$detail" "$metrics"
done

# disks: percent used
for mount in ${CK_DISKS:-/}; do
    used=$(df -P "$mount" | awk 'NR == 2 { sub("%", "", $5); print $5 }')
    free=$(df -Ph "$mount" | awk 'NR == 2 { print $4 }')
    name="${mount#/}"
    add "$(code "${name:-root}" disk_)" "Disk $mount" "$(level "$used" "${CK_DISK_WARN:-80}" "${CK_DISK_FAIL:-90}" up)" \
        "$used" "%" "$free free"
done

# memory: percent available
available=$(awk '/^MemTotal:/ { t = $2 } /^MemAvailable:/ { a = $2 } END { printf "%d", a * 100 / t }' /proc/meminfo)
add memory "Memory" "$(level "$available" "${CK_MEM_WARN:-10}" "${CK_MEM_FAIL:-5}" down)" "$available" "%" "available"

# load: 1-minute load per CPU
cpus=$(nproc)
load=$(awk -v c="$cpus" '{ printf "%.2f", $1 / c }' /proc/loadavg)
add load "Load per CPU" "$(level "$load" "${CK_LOAD_WARN:-2}" "${CK_LOAD_FAIL:-4}" up)" "$load" "" "$cpus CPUs"

# services (optional)
for unit in ${CK_SERVICES:-}; do
    state=$(systemctl is-active "$unit" 2>/dev/null || true)
    add "$(code "$unit" service_)" "Service $unit" "$([[ "$state" == active ]] && echo ok || echo fail)" "" "" "$state"
done

# backup age (optional): hours since the backup last touched the file
if [[ -n "${CK_BACKUP_FILE:-}" ]]; then
    if [[ -f "$CK_BACKUP_FILE" ]]; then
        hours=$(( ($(date +%s) - $(stat -c %Y "$CK_BACKUP_FILE")) / 3600 ))
        add backup "Backup" "$(level "$hours" "${CK_BACKUP_WARN_HOURS:-26}" "${CK_BACKUP_FAIL_HOURS:-50}" up)" \
            "$hours" "h" "since the last successful backup"
    else
        add backup "Backup" fail "" "" "no backup yet (its file is missing)"
    fi
fi

# certificates (optional): days until expiry
for host in ${CK_SSL_HOSTS:-}; do
    end=$(echo | timeout 10 openssl s_client -connect "$host:443" -servername "$host" 2>/dev/null \
        | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || true)
    if [[ -z "$end" ]]; then
        add "$(code "$host" ssl_)" "Certificate $host" fail "" "" "could not read the certificate"
        continue
    fi
    days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
    add "$(code "$host" ssl_)" "Certificate $host" \
        "$(level "$days" "${CK_SSL_WARN_DAYS:-14}" "${CK_SSL_FAIL_DAYS:-7}" down)" "$days" "days" "until it expires"
done

report=$(jq -c --arg source "$source_name" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson checks "$checks" \
    -n '{report: {version: 1, source: $source, generated_at: $at, checks: $checks[:50]}}')

if (( dry_run )); then
    jq . <<< "$report"
    exit 0
fi
# Root only collects. The file is written by the owner of Magento's var/app-health (the web server's user): that
# folder is the web user's to write, so as root a temporary file in it could be swapped for a link to any file, and
# root would write or chown through it. As the owner nothing is gained. Numeric ids: under Docker's user namespaces
# the owner has no name on the host. The folder - or var/, when Magento has not made the folder yet and the owner
# creates it there - must not be root's.
dir="${CK_STATUS_DIR%/}"
owner_of="$dir"
[[ -d "$owner_of" ]] || owner_of="$(dirname "$dir")"
if [[ ! -d "$owner_of" ]]; then
    echo "host-status: $owner_of does not exist (CK_STATUS_DIR is Magento's var/app-health)" >&2
    exit 1
fi
if [[ "$(stat -c %u "$owner_of")" == 0 ]]; then
    echo "host-status: $owner_of is owned by root; it must be the web server's user's (Magento's var/)" >&2
    exit 1
fi
uid=$(stat -c %u "$owner_of")
gid=$(stat -c %g "$owner_of")
# as the owner: the folder if missing, a temporary file, then renamed into place (Magento reads it whole or not at all)
write='umask 022; mkdir -p "$1" && t=$(mktemp "$1/.status-$2.json.XXXXXX") && { cat > "$t" && chmod 0644 "$t" && mv -f "$t" "$1/status-$2.json" || { rm -f "$t"; exit 1; }; }'
if [[ "$(id -u)" == "$uid" ]]; then
    bash -c "$write" host-status "$dir" "$source_name" <<< "$report"
elif [[ "$(id -u)" == 0 ]]; then
    setpriv --reuid="$uid" --regid="$gid" --clear-groups --no-new-privs \
        bash -c "$write" host-status "$dir" "$source_name" <<< "$report"
else
    echo "host-status: run as root or as the owner of $owner_of (uid $uid)" >&2
    exit 1
fi
