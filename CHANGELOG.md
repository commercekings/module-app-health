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
- **Daily summary** per website at its time: counts of the last 24 hours, what had problems, what was ok all day.
- **Sending** through Notification's queue and consumer as service messages (no cooldown, stop list or quiet hours),
  once per period through its delivery state, retried until the channel takes it. Channel "slack" by default; any
  Notification channel works.
- **Heartbeat:** an encrypted URL pinged after every run while the hourly status goes out (not when it has been due
  for 10 minutes and is not delivered).
- **Admin:** *Stores > Configuration > CommerceKings > App health*, with *Send test alert*.
- **Stable extension points (`@api`):** `Api\CheckInterface`, `Api\WebsiteCheckInterface`, `Api\Data\CheckResult` and
  `Model\CheckPool`'s "checks" argument.

No public release has been tagged or published yet.
