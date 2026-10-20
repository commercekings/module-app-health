# System status from your server

Start with the [README](../README.md#system-status-from-your-server).

Magento sees only itself. A script on the server – run by the server, not by Magento – reports what Magento can't:
its containers, disks, memory, load and, when set, services, the last backup and certificate expiry. It writes the
report to Magento's `var/app-health/status-<server>.json`; the *Server status* check reads it in its normal 5-minute
run. No token, port, URL or Magento command: nothing is exposed and Magento is never started for it.

**Each run of the script** (every minute recommended):

| Does | Never |
|---|---|
| Asks Docker for each container's state, restarts and health; `df` for disks, `/proc` for memory and load; optionally `systemctl`, a backup's marker file and `openssl` for certificates | Starts Magento or PHP |
| Writes `status-<server>.json` as the folder's owner (the web server's user) – a temporary file in the same folder, then renamed: Magento reads a whole file or none | Reads env files (only its own `CK_` settings), passwords, tokens or Magento's data; writes in `var/` as root |
| Puts names and numbers only into the file (`"Disk /", 87 %, "13G free"`) | Sends anything over the network |

**What App health does with it:**

| When | Then |
|---|---|
| A line is `fail` (a container down, a disk 90% full…) | *Problem: host-1 · Container php* at once, *Resolved* when it is `ok` again; repeated every 6 h while it fails |
| A line is `warn` | a warning in the hourly status and the daily summary |
| No new status for 15 min (the script, its cron or the server stopped), or the file is gone | *Problem: No status from host-1* – "since 14:02"; a server whose file is gone is forgotten a day later |
| A file breaks a rule (below) | a warning naming the file and the rule, never its content; the last good status still counts |
| The whole server is down | nothing can run on it – the heartbeat and an outside uptime check tell you (see *Before going live*) |

**Set up** – one of three, with ready files in [docs/examples](examples):

| Setup | Runs | Docker access | Files |
|---|---|---|---|
| A server you run (VM, bare metal, Docker host) | on the host, every minute (systemd timer or cron) | the host's own Docker CLI, as root | [systemd/](examples/systemd) – service, timer, env example |
| A Docker Compose stack | a sidecar container next to Magento's | through a proxy passing only the container list and stats | [docker-compose/](examples/docker-compose) |
| Warden (development) | a sidecar in the project's `.warden/warden-env.yml`, opt-in profile `host-status` | through a proxy passing only the container list and stats | [warden/](examples/warden) |

On a host, root only collects the readings (Docker needs it); the file is written as the owner of `var/app-health` –
the web server's user, by its numeric id, also under Docker's user namespaces. That folder is the web user's to write,
so root never works in it: a temporary file there could be swapped for a link to any file on the server, and root
would write through it. The script refuses when the folder (or `var/`, before the folder exists) is owned by root. A
sidecar runs as the web server's user from the start.

**Why a proxy in a container, and which:** Docker's socket – even mounted `:ro` – is the whole Docker API, so whoever
holds it can start a privileged container: root on the host. And even a read-only API leaks: a container's
`/containers/<id>/json` has its environment (database and queue passwords), `/archive` any file in it, `/logs` and
`/export` the rest. A proxy that allows "every GET under /containers" lets all of that through. The examples use
HAProxy with [an exact list](examples/docker-socket-proxy/haproxy.cfg) instead: `GET /containers/json` (the
list: names, states, health), `GET /containers/<id>/stats` and the Docker CLI's `/_ping` and `/version` – everything
else, any other path or method, gets 403. The list still shows each container's command line, labels and mounts:
keep secrets out of them (environment variables or files, not arguments). Restart counts would need
`/containers/<id>/json`, so behind the proxy the table shows them as "-" (`CK_CONTAINER_RESTARTS=0`); on a host the
script reads them. Proxy and script share an internal network that nothing else reaches, and neither reaches the
internet: the script's tools are built into [its image](examples/host-status-image/Dockerfile), and both images
are pinned.

1. Copy [docs/examples/host-status.sh](examples/host-status.sh) (mode 0755); on a host it needs `bash`, `jq`,
   `setpriv` (util-linux) and `docker`; the sidecars install theirs.
2. Its settings – `/etc/ck-host-status.env` (mode 0600) on a host, `environment:` in a sidecar: `CK_STATUS_DIR`
   (Magento's `var/app-health`, as the script sees it), and as needed the server's name, the containers
   (`CK_CONTAINERS`, or every container of a Compose project: `CK_COMPOSE_PROJECT`), disks, a backup marker file,
   the host names whose certificates to watch – the script's header lists them.
3. Try it: `host-status.sh --dry-run` prints the report; `host-status.sh` writes the file.
4. Schedule it – on a host the [timer](examples/systemd) (a run is never started twice; its output in
   `journalctl -u ck-host-status`), or cron with `flock`:
   `* * * * * root flock -n /run/ck-host-status.lock /usr/local/bin/host-status.sh`; a sidecar loops by itself.

| Hosting | `CK_STATUS_DIR` and permissions |
|---|---|
| Docker Compose (e.g. m2_infra), Warden | on the host: the folder mounted as Magento's `var/`, e.g. `<APP_PATH>/shared/var/app-health` (read-only containers are fine: the shared `var/` is writable); in a sidecar: that folder or volume mounted at `/status` |
| A server or VM without Docker | `<Magento root>/var/app-health`; set `CK_CONTAINERS=` (empty) and list the services instead (`CK_SERVICES="nginx php8.3-fpm mariadb"`) |
| Several nodes | each node runs the script with its own `CK_SOURCE` into the `var/` that Magento's cron reads (a shared `var/`); one file per node, at most 20 |
| Managed hosting without access to the server | not possible – the checks inside Magento and the heartbeat still work |

**Containers.** Each container is a line of its own with its measurements: down, restarting or unhealthy fails (a
*Problem* at once, *Resolved* when it is back), and so does a listed container that is not there (*not found*). When
the script cannot list the containers at all – Docker not running, no access to its socket, the socket proxy refusing,
no `docker` command – one line *Docker* fails with the reason instead (`docker_unavailable`), never an empty,
healthy-looking report; a `CK_COMPOSE_PROJECT` with no container (a wrong project name, e.g. Compose's default
`shop` in the example sidecar) fails as *Compose project <name>* (`docker compose ls` shows the names). With
`CK_CONTAINERS=` empty and no project, the script does not use Docker at all; memory over 85 % of its limit (`CK_CONTAINER_MEM_WARN`) or CPU over 90 %
(`CK_CONTAINER_CPU_WARN`, one core = 100 %) warns (hourly status and summary). *Send status now* and the daily summary
show them as a table per server, at most 20 rows (with a Compose project, the names leave out its `<project>-` prefix;
a container without a memory limit shows the host's memory as its limit – on Docker Desktop the VM's – and MEM% of
it):

```
*Containers · shop-1*
NAME          CPU         MEM  MEM%  RESTARTS
php          12.3%  512M / 2G   25%         0
mariadb       4.1%  2.8G / 4G   70%         0
opensearch    1.8%  1.1G / 2G   55%         2
```

On its first run the check creates `var/app-health` (with Magento's permissions for `var/`, so the script can write
there) and `status.json.dist` – the format with every field explained, to copy from. Only `status-*.json` files count;
`.dist` and the script's temporary files never do.

**The file** – the same JSON whatever writes it:

```json
{"report": {"version": 1, "source": "shop-1", "generated_at": "2026-10-20T14:05:00Z", "checks": [
  {"code": "disk_root", "label": "Disk /", "status": "warn", "value": 87, "unit": "%", "detail": "13G free"}
]}}
```

- **Rules:** named `status-<source>.json` with the same `source` (1–40 of `A-Z a-z 0-9 . _ -`); `version` 1; at
  most 50 checks; `code` 1–40 of `a-z 0-9 _`, each once; `label` up to 60 characters, `detail` up to 160, `unit` up
  to 10; `status` `ok`, `warn` or `fail`; `value` a number or null; `label` a name (letters, digits, spaces and
  `. _ / : ( ) % # + -`); optional `metrics` – `cpu_percent` (up to 100000), `mem_used_bytes`, `mem_limit_bytes`,
  `net_rx_bytes`, `net_tx_bytes`, `blk_read_bytes`, `blk_write_bytes` (whole bytes) and `restarts`, nothing else;
  at most 32 KB; no other keys.
- **Fresh, never replayed:** `generated_at` (ISO-8601 with a time zone) at most 2 minutes ahead of Magento's clock,
  within *No status for*, and newer than the status read before – an old copy put back is refused.
- **Masked:** details are masked like log messages (secrets after key words, names, emails, numbers, URLs); a
  label can only be a name – the script writes names and numbers only anyway.
- **Light:** a file is read only when it changed (its hash); all servers' last statuses are one state entry.

*Server status* settings: Enabled (Yes); No status for (15 minutes).
