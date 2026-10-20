# Changelog

## 0.1.0 - unreleased

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`) and
`commercekings/module-notification ^0.1`.

- **Checks:** cron, indexers, caches, maintenance mode, exception spikes (top 3 classes), queue backlogs (MySQL queue and
  RabbitMQ, off by default), sending (mail transport errors, failing CommerceKings notifications), email sending per
  website and unsent sales emails per website, every 5 minutes within a 5-second budget; each with its own switch and
  thresholds.
- **Alerts:** change-only – a problem, then "recovered"; a failing check repeated after *Repeat a failing check after*
  (6 hours). Shop-wide checks alert the default scope's recipient once, a website's own checks its recipient.
- **Daily summary** per website at its time: counts of the last 24 hours, what had problems, what was ok all day.
- **Sending** through Notification's queue and consumer as service messages (no cooldown, stop list or quiet hours),
  once per period through its delivery state, retried until the channel takes it. Channel "slack" by default; any
  Notification channel works.
- **Heartbeat:** an optional, encrypted URL pinged after every complete run.
- **Admin:** *Stores > Configuration > CommerceKings > App health*, with *Send test alert*.
- **Stable extension points (`@api`):** `Api\CheckInterface`, `Api\WebsiteCheckInterface`, `Api\Data\CheckResult` and
  `Model\CheckPool`'s "checks" argument.

No public release has been tagged or published yet.
