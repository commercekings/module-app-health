# Changelog

## 0.1.0 - 2026-10-20

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`) and
`commercekings/module-notification ^0.1`.

- **Checks:** Cron, Indexers, Caches, Maintenance mode, system.log, exception.log (new errors, spikes with the top 3
  classes), Queues (MySQL queue and RabbitMQ, off by default), Mail delivery errors (mail transport errors, failing
  CommerceKings notifications), Sensitive data in logs, Email communications disabled and Unsent sales emails (per
  website), Server status – every 5 minutes within a 5-second budget; each with its own switch and thresholds.
- **Alerts:** change-only – a problem at once, *Resolved* at the first OK run (after 2 in a row for system.log,
  exception.log, Queues, Mail delivery errors and Server status); warnings in the hourly status and the daily summary; a failing check repeated after
  *Repeat a failing check after* (6 hours). Shop-wide checks alert the global recipient once, a website's own
  checks its recipient. New critical log entries and sensitive data at once (at most one message per check every 30
  minutes), log errors in the hourly status, warnings in the daily summary.
- **Hourly status:** the problems, warnings and the hour's log errors, through the same queue every hour – the proof
  that alerts can go out; posted when there is news (a new problem, warning or error) and once a day as "All checks
  OK", else delivered quietly.
  When it is posted: `Api\StatusPolicyInterface` (`Model\Status\DefaultPolicy`).
- **Server status:** a script on the server (`docs/examples/host-status.sh`, every minute by cron or a systemd timer)
  writes its containers, disk, memory, load, services, backup age and certificates to
  `var/app-health/status-<server>.json`; the `external` check alerts on its `fail` and `warn` lines, on a server
  without a fresh status (15 minutes) and on a file that breaks a rule (strict format, fresh, never an old copy,
  labels and details masked). Magento creates the folder and `status.json.dist` (the format) on its first run.
- **Containers:** the script reports each container (`CK_CONTAINERS` or a Compose project) with its CPU, memory
  (used/limit), network and block I/O and restarts (optional `metrics` per line, strictly checked); down, restarting
  or unhealthy fails, memory over 85 % or CPU over 90 % warns; *Send status now* and the daily summary show a table per
  server. Ready setups in `docs/examples`: systemd (a host), Docker Compose and Warden (a sidecar in a pre-built,
  pinned image behind HAProxy that passes only the container list and stats - not a container's environment, files or
  logs; restart counts only on a host). Docker that cannot be read, a project without containers or a listed container
  that is missing is a failure of its own, never an empty, healthy-looking report.
- **Daily summary** at its time: a global one and one per *Website channels* row (in the website's time zone) –
  counts of the last 24 hours, what had problems, what was ok all day.
- **Sending** through Notification's queue and consumer as service messages (no cooldown, stop list or quiet hours),
  once per period through its delivery state, retried until the channel takes it. Channel "slack" by default; another
  Notification channel that sends text (not email or WhatsApp, which send templates) gets plain text, or its module's
  format (`Api\TeamMessageRendererInterface` in `Model\Message\RendererPool`).
- **Staging and copies stay silent:** the shop's domain is stored when App health is first switched on (or by its
  first run); a copy at another domain sends nothing and pings no heartbeat, and the admin says so, with *Use
  <domain>* after a real move.
- **Heartbeat:** an encrypted URL pinged after every run, held while the hourly status has been due for 10 minutes and
  is not out, while a message fails to go out, or while App health is on with nowhere to send.
- **Admin:** *Stores > Configuration > CommerceKings > App health*, with *Send test alert* and *Send status now* (the
  checks run now, the result posted to the global recipient; nothing else changes).
- **Stable extension points (`@api`):** every interface and data class in `Api/` – among them `Api\CheckInterface`
  (`run($websiteIds)` returns results by scope), `Api\Data\CheckResult`, `Api\StatusPolicyInterface`, the log source
  and parser interfaces, `Api\SensitiveDataValidatorInterface` and `Api\TeamMessageRendererInterface` with
  `Api\Data\TeamMessageContent` – and the pools' di.xml arguments ("checks", "sources", "validators", "renderers"),
  where the item name is the code.
