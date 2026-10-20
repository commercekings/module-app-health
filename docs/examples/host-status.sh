#!/usr/bin/env bash
# The server's status for App health's "external" check: containers, disk, load, memory and - when set - services,
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
#                    varnish nginx)
#   CK_DISKS         mount points (default: /); CK_DISK_WARN / CK_DISK_FAIL percent used (default: 80 / 90)
#   CK_MEM_WARN / CK_MEM_FAIL  percent of memory available below which it warns / fails (default: 10 / 5)
#   CK_LOAD_WARN / CK_LOAD_FAIL  1-minute load per CPU (default: 2 / 4)
#   CK_SERVICES      systemd units that must be active (default: none, e.g. "docker")
#   CK_BACKUP_FILE   a file your backup touches when it succeeds; CK_BACKUP_WARN_HOURS / _FAIL_HOURS (default 26 / 50)
#   CK_SSL_HOSTS     host names whose certificate expiry is checked on port 443; CK_SSL_WARN_DAYS / _FAIL_DAYS
#                    (default 14 / 7)
# Options: --dry-run prints the JSON instead of writing it.
#
# Needs bash, jq, docker; openssl for CK_SSL_HOSTS; systemctl for CK_SERVICES.
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
# add CODE LABEL STATUS VALUE UNIT DETAIL (VALUE may be "" for none)
add() {
    # $a_* names: "label" is a jq keyword (jq 1.5+ can't take $label)
    checks=$(jq -c --arg a_code "$1" --arg a_label "$2" --arg a_status "$3" --arg a_value "$4" --arg a_unit "$5" \
        --arg a_detail "$6" '. + [{code: $a_code, label: $a_label, status: $a_status,
            value: (if $a_value == "" then null else ($a_value | tonumber) end), unit: $a_unit, detail: $a_detail}]' \
        <<< "$checks")
}
# code: lower case letters, digits and "_", at most 40
code() { tr 'A-Z' 'a-z' <<< "$1" | tr -c 'a-z0-9_\n' '_' | cut -c1-40; }
# status of VALUE against WARN and FAIL limits; "up" = higher is worse, "down" = lower is worse
level() {
    awk -v v="$1" -v w="$2" -v f="$3" -v d="$4" 'BEGIN {
        if (d == "up") s = (v >= f) ? "fail" : (v >= w) ? "warn" : "ok";
        else s = (v <= f) ? "fail" : (v <= w) ? "warn" : "ok";
        print s }'
}

# containers: running, restarts, health
for name in ${CK_CONTAINERS-mariadb php cron rabbitmq opensearch cache session varnish nginx}; do
    if ! state=$(docker inspect --format '{{.State.Status}} {{.RestartCount}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null); then
        add "container_$(code "$name")" "Container $name" fail "" "" "not found"
        continue
    fi
    read -r running restarts health <<< "$state"
    status=ok
    detail="$running"
    [[ "$health" != none ]] && detail="$detail, $health"
    if [[ "$running" != running || "$health" == unhealthy ]]; then
        status=fail
    elif [[ "$running" == restarting || "$health" == starting ]]; then
        status=warn
    fi
    add "container_$(code "$name")" "Container $name" "$status" "$restarts" "restarts" "$detail"
done

# disks: percent used
for mount in ${CK_DISKS:-/}; do
    used=$(df -P "$mount" | awk 'NR == 2 { sub("%", "", $5); print $5 }')
    free=$(df -Ph "$mount" | awk 'NR == 2 { print $4 }')
    name="${mount#/}"
    add "disk_$(code "${name:-root}")" "Disk $mount" "$(level "$used" "${CK_DISK_WARN:-80}" "${CK_DISK_FAIL:-90}" up)" \
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
    add "service_$(code "$unit")" "Service $unit" "$([[ "$state" == active ]] && echo ok || echo fail)" "" "" "$state"
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
        add "ssl_$(code "$host")" "Certificate $host" fail "" "" "could not read the certificate"
        continue
    fi
    days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
    add "ssl_$(code "$host")" "Certificate $host" \
        "$(level "$days" "${CK_SSL_WARN_DAYS:-14}" "${CK_SSL_FAIL_DAYS:-7}" down)" "$days" "days" "until it expires"
done

report=$(jq -c --arg source "$source_name" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson checks "$checks" \
    -n '{report: {version: 1, source: $source, generated_at: $at, checks: $checks[:50]}}')

if (( dry_run )); then
    jq . <<< "$report"
    exit 0
fi
# Magento creates the folder on its first check run; when the script runs first, it creates it owned like var/
if [[ ! -d "$CK_STATUS_DIR" ]]; then
    mkdir -p -m 0775 "$CK_STATUS_DIR"
    chown --reference="$(dirname "$CK_STATUS_DIR")" "$CK_STATUS_DIR" 2>/dev/null || true
fi
tmp=$(mktemp "$CK_STATUS_DIR/.status-$source_name.json.XXXXXX")
trap 'rm -f "$tmp"' EXIT
printf '%s\n' "$report" > "$tmp"
chmod 0644 "$tmp"
# owned like the folder (the web server's user, also under Docker's user namespaces), so Magento can manage it
chown --reference="$CK_STATUS_DIR" "$tmp" 2>/dev/null || true
mv -f "$tmp" "$CK_STATUS_DIR/status-$source_name.json"
trap - EXIT
