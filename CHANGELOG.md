# Changelog

## 0.1.0 - unreleased

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`) and
`commercekings/module-notification ^0.1`.

- **Checks:** cron, indexers, caches, maintenance mode, exception spikes (top 3 classes), queue backlogs (MySQL queue and
  RabbitMQ, off by default), sending (mail transport errors, failing CommerceKings notifications), email sending per
  website and unsent sales emails per website, every 5 minutes within a 5-second budget; each with its own switch and
  thresholds.
- **Alerts:** change-only – a problem at once, "resolved" at the first OK run (after 2 in a row for the log, queue
  and sending checks); warnings in the hourly status and the daily summary; a failing check repeated after
  *Repeat a failing check after* (6 hours). Shop-wide checks alert the default scope's recipient once, a website's own
  checks its recipient. New critical log entries and sensitive data at once (at most one message per check every 30
  minutes), log errors in the hourly status, warnings in the daily summary.
- **Hourly status:** the problems, warnings and the hour's log errors, through the same queue every hour – the proof
  that alerts can go out; posted when something is not OK and once a day as "All checks OK", else delivered quietly.
  When it is posted: `Api\StatusPolicyInterface` (`Model\Status\DefaultPolicy`).
- **Server status:** a script on the server (`docs/examples/host-status.sh`, every minute by cron or a systemd timer)
  writes its containers, disk, memory, load, services, backup age and certificates to
  `var/app-health/status-<server>.json`; the `external` check alerts on its `fail` and `warn` lines, on a server
  without a fresh status (15 minutes) and on a file that breaks a rule (strict format, fresh, never an old copy,
  labels and details masked). Magento creates the folder and `status.json.dist` (the format) on its first run.
- **Daily summary** per website at its time: counts of the last 24 hours, what had problems, what was ok all day.
- **Sending** through Notification's queue and consumer as service messages (no cooldown, stop list or quiet hours),
  once per period through its delivery state, retried until the channel takes it. Channel "slack" by default; any
  Notification channel works.
- **Heartbeat:** an encrypted URL pinged after every run while the hourly status goes out (not when it has been due
  for 10 minutes and is not delivered).
- **Admin:** *Stores > Configuration > CommerceKings > App health*, with *Send test alert* and *Send status now* (the
  checks run now, the result posted to the global recipient; nothing else changes).
- **Stable extension points (`@api`):** `Api\CheckInterface` (one interface: `run($websiteIds)` returns results by
  scope), `Api\Data\CheckResult` (hints on its causes, `withHint()` for a general one) and the pools' di.xml arguments
  ("checks", "sources", "validators"), where the item name is the code.

No public release has been tagged or published yet.
