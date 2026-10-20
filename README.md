# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack or any Notification channel

[![License](https://badgen.net/github/license/commercekings/module-app-health)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-app-health)](https://github.com/commercekings/module-app-health/releases) [![Checks](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml)

**Hear about a broken cron, a stuck indexer or emails that don't go out before your customers do.**

- **Eight checks, every 5 minutes.** Cron, indexers, caches, maintenance mode, email settings, exception spikes, queue
  backlogs, and sales emails that don't go out.
- **Only news.** A message when something breaks and one when it is back ("recovered"); a check still failing is
  repeated every 6 hours, never every 5 minutes.
- **The right channel.** Checks for the whole shop go to one channel; each website's own (its email settings, its
  unsent order emails, its daily summary) to its own.
- **A daily summary.** Cron runs and errors, exceptions by class, and what had problems – at the time you choose.
- **Light.** Admin, cron and Notification's queue only – nothing on the storefront or in checkout. Indexed, bounded
  queries; only the new bytes of the exception log; a 5-second budget per run.
- **Private.** Only check names, counts and class names are sent – never a log line, an address or customer data.
- **When the server itself is down.** An optional heartbeat lets an outside service alert you when the pings stop.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or any installed channel.

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; jobs ending with an error (warning); cron was down (warning, when it is back) | all websites |
| Indexers | an indexer invalid or stuck working for over 60 min | all websites |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | all websites |
| Maintenance mode | on for over 15 min | all websites |
| Exceptions | 20 or more new `exception.log` entries in 15 min, with the top 3 exception classes | all websites |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | all websites |
| Sending | a mail transport error in `exception.log`; 3 or more CommerceKings notifications failing | all websites |
| Email sending | *Disable Email Communications* on for any of the website's store views | the website |
| Sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min | the website |

**All websites** checks alert once, to the default scope's recipient. **The website** checks, and the daily summary,
go to the website's recipient (the default one unless the website sets its own).

```
App health - Moj Planer (all websites)
FAIL Cron: no job has run since 14:05
RECOVERED Indexers
OK Caches, Maintenance mode, Exceptions and Sending
```

```
Daily summary - Moj Planer - 20 Oct
Cron: 1,437 runs, 2 errors (sitemap_generate x2)
Exceptions: 14 exceptions (Magento\Framework\Exception\NoSuchEntityException x9, ...)
OK all day: Indexers, Caches, Maintenance mode, Sending, Email sending and Sales emails
```

## Installation

```sh
composer require commercekings/module-app-health:^0.1 commercekings/module-notification-slack:^0.1
bin/magento setup:upgrade
```

Messages go through Notification's queue: run the consumer `commerceKingsNotificationSend` (Magento's cron runs it
when `cron_consumers_runner` is on), or a Slack outage delays the alerts instead of retrying them.

## Settings

**Stores > Configuration > CommerceKings > App health**:

| Group | Fields (default) |
|---|---|
| Alerts | Enabled (No); Channel (slack); Recipient (e.g. a Slack channel ID); Daily summary (Yes) at 08:00; Repeat a failing check after (6 hours); Heartbeat URL (empty); *Send test alert* |
| Per check | Enabled, and its thresholds (see above); Queues off by default (it connects to the broker on every run) |

Enabled, Channel, Recipient and the summary are per website: in the **default scope** they are the shop-wide checks'
alerts; a **website** inherits them unless it sets its own. Send a test alert from each scope after saving.

## Before going live

- Set the recipient in the default scope (and per website if each has its own channel), then *Send test alert*.
- Keep `commerceKingsNotificationSend` running – the same consumer as every CommerceKings notification.
- **Add a heartbeat and outside checks.** Magento can't report its own death: when the server, Docker, PHP, the database
  or cron is down, no check runs. Create a heartbeat check in an uptime service (e.g. Healthchecks.io, Better Stack or
  UptimeRobot) expecting a ping every 5 minutes, connect that service's **Slack integration**, and paste its URL into
  *Heartbeat URL*. In the same service add **HTTP checks of your homepage and your checkout** (e.g.
  `/checkout/cart/`): they catch a site that is up for cron but down for shoppers.

## Extending

A check of your own: implement `Api\CheckInterface` (shop-wide) or `Api\WebsiteCheckInterface` (per website) and add
it to `Model\CheckPool`'s "checks" in your `di.xml`. Return an `Api\Data\CheckResult` – ok, warn or fail with a short
detail of names and numbers, and optional counts for the daily summary. Its switch is
`commercekings_app_health/<code>/enabled` (on when not set). Details: [how it works](docs/how-it-works.md).

## Limitations

- Checks run inside Magento's cron: a cron that is down entirely is reported when it comes back – use the heartbeat for
  the rest.
- Sales emails count only entities created in the last 24 hours; queues read the MySQL queue and RabbitMQ (AMQP)
  connections, not others (e.g. ActiveMQ).
- Messages are in English.

## Tested (as of 0.1.0)

- **Automated tests:** 89 unit tests (on every push), PHPStan level 5 and the Magento2 coding standard.
- **Not tested yet:** a real Slack workspace (the end-to-end suite uses a simulated Slack API).

## More

[How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These checks watch what Magento itself can see. They don't replace server monitoring, backups or an uptime service –
add those for production.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
