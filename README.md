# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack, or another text channel

[![License](https://badgen.net/github/license/commercekings/module-app-health)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-app-health)](https://github.com/commercekings/module-app-health/releases) [![Checks](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml)

**Hear about a broken cron, a stuck indexer or emails that don't go out before your customers do.**

- **Sensitive data in your logs.** A card number, a secret or personal data in bulk written into a log is a high
  alert at once – the rule and where, never the value.
- **Twelve checks, every 5 minutes.** Cron, indexers, caches, maintenance mode, email settings, `system.log` and
  `exception.log` (new errors, with where they happened), sensitive data in logs, mail sending, queue backlogs, sales
  emails that don't go out, and your servers' own status (disk, services, ... – *Server status*).
- **Only news, at the right pace.** A problem at once, "resolved" once it is OK (for the log, queue and sending checks
  two runs in a row); a check still failing is repeated every 6 hours; a check's warning in the hourly status.
  Critical log errors and sensitive data at once, log errors in the hourly status, log warnings in the daily summary.
- **Proof that alerts can go out.** An hourly status through the same queue: posted when something is not OK, once a
  day as "All checks OK", delivered quietly otherwise – when it stops going out, so does the heartbeat.
- **One global control, the right channel.** Every setting is global (the default scope): one channel for the shared
  checks, security and the hourly status; optionally a channel per website for its own messages (its email settings,
  its unsent order emails, its daily summary).
- **A daily summary.** Cron runs and errors, exceptions by class, and what had problems – at the time you choose.
- **Light.** Admin, cron and Notification's queue only – nothing on the storefront or in checkout. Indexed, bounded
  queries; only the new bytes of the exception log; a 5-second budget per run.
- **Private.** Log errors are sent as a safe label (the exception class, or the message's first words with every
  variable part as `***`), in alerts with file:line and – only if you switch it on – the message masked; never the
  line itself.
- **When the server itself is down.** A heartbeat lets an outside service alert you when the pings stop – the server,
  cron, or the sending path down.
- **The server's status too.** A script on the server writes its containers, disk, memory, load, backups and
  certificates to a file every minute; what it reports as failing alerts like any check, and a server that stops
  reporting is a problem.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or another installed channel
that sends plain text (e.g. SMS; plain text unless its module adds a format: [Extending](docs/extending.md)). Not
email or WhatsApp: Notification sends those from templates, and App health's messages are text.

## Quick start

1. **Install** both modules: `composer require commercekings/module-app-health:^0.1
   commercekings/module-notification-slack:^0.1`, then `bin/magento setup:upgrade`.
2. **Create the Slack app:** in Slack, *Create New App > From a manifest* with
   [module-notification-slack's manifest](https://github.com/commercekings/module-notification-slack/blob/main/docs/slack-app-manifest.json),
   *Install to Workspace*, copy the *Bot User OAuth Token* (`xoxb-…`).
3. **Connect Magento to Slack:** *Stores > Configuration > CommerceKings > Notifications > Slack*: paste the token,
   enter a channel ID (the channel's details, at the bottom: `C0123456789`), save, *Send test message*.
4. **Invite the bot** to the alerts channel if it is private: `/invite @Magento` in the channel.
5. **Switch App health on:** *Stores > Configuration > CommerceKings > App health > Alerts*: Enabled *Yes*, Channel
   `slack`, Recipient the channel ID; save, then *Send test alert* and *Send status now*.
6. **Keep the queue consumer running:** `commerceKingsNotificationSend` – Magento's cron starts it when
   `cron_consumers_runner` is on in `app/etc/env.php`. Without it no alert goes out (the admin warns when the hourly
   status is overdue).
7. **Add a heartbeat:** a check in an uptime service (e.g. Healthchecks.io, every 5 minutes, with its Slack
   integration), its ping URL in *Heartbeat URL* – the one thing that tells you when the whole server is down.
8. **Optional – the server's status:** containers, disks, memory, backups – [a script on the
   server](#system-status-from-your-server), every minute.
9. **On staging, lock it off:** `bin/magento config:set commercekings_app_health/general/enabled 0 --lock-env`. A copy
   of the production database stays silent anyway ([why](#before-going-live)).

## When you hear about what

The logs are read every 5 minutes; what you hear, and when:

| What | When | Where |
|---|---|---|
| A check going to *Problem* (cron, indexers, caches, ...) | at once; again every 6 h while it fails; *Resolved* at the first OK run – for system.log, exception.log, Queues and Sending after 2 OK runs in a row | its recipient |
| Cron back after it was down | at once: "Cron was down from 23:10 to 06:40 (7 h)" – not "Cron stopped" | global |
| A check's *Warning* | the hourly status ("Warnings: Queue (…)") and the daily summary | global (a website's own: its summary) |
| Sensitive data in a log (*Security*) | at once – then at most one message per check every 30 min, what comes in between in the next | global |
| A new CRITICAL log entry (and exceptions) | at once – the same 30-minute limit: type, count, time and file:line (the masked message only with *Add the masked log message* on) | global |
| New CRITICAL log entries in the first hour after install | one digest when the hour is over ("First hour: N errors seen for the first time: …"); one coming 10+ times in that hour at once | global |
| ERROR log entries (other files) | the hourly status: "New errors this hour: N" and the 5 most frequent | global |
| ERROR entries of a file in *Errors at once from* (e.g. payment.log) | at once, like CRITICAL (not in the hourly status) | global |
| WARNING log entries | the daily summary | global |
| The hourly status | queued and delivered every hour (the heartbeat's proof); **posted** only with news since the last posted one – a new problem or warning, a new error, or a known one 10+ times in the hour (a "Still open: …" line names what it told before) – and "All checks OK" once a day, at the first status from *Daily summary at* | global – for all websites |
| *Send status now* (admin) | when you press it: the checks run now and what they find is posted as "Status now" (problems, warnings, what is not set up, the log errors since the last hourly status) – nothing else changes: no alert is marked told, the hourly status keeps its time | global |
| The daily summary | once a day at *Daily summary at*: a global one (the shared checks, and websites without a channel of their own, named) and one per website channel (its own items, in its time zone) | global, each website channel |

```
*Hourly status · Acme Shop · shop.example.com*

1 problem: Cron
Warnings: Queue (commercekings.notification.send: 120 messages)

New errors this hour: 14
• Unable to send (9×, new)
• NoSuchEntityException (5×)
```

The hourly status covers **all websites**: their problems and warnings go to the global recipient. An hour with
nothing to say is delivered quietly – recorded as sent through the queue and its consumer, without calling the channel
– so the heartbeat still proves that the queue works, but not the channel itself: the daily "All checks OK" post and
the alerts do.

## Where messages go

Every header names the shop and its domain: *Problem: Cron stopped · Acme Shop · shop.example.com*. Every
setting is global (*Stores > Configuration > CommerceKings > App health*, default scope):

| Messages | Go to |
|---|---|
| The shared checks (cron, indexers, caches, maintenance mode, logs, queues, mail delivery errors, server status), the hourly status, *Send test alert*, *Send status now* | the global channel and recipient |
| Security findings (sensitive data in logs) | the global channel – always, never a website channel |
| A website's own checks (*Email communications disabled*, *Unsent sales emails*) | its *Website channels* row – else the global channel, the website named ("Email communications disabled (EU Store)") |
| The daily summary | a global one; and one per *Website channels* row with only that website's items |
| The shared problems, also to website channels | only with *Also post shared problems to website channels* (default No); security findings never |

A website channel row is checked on save: a website once, a channel and a recipient each, the recipient as the channel's
normalizer takes it.

Examples of every message, and why each check fails: [Messages](docs/messages.md).

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; a job ending with an error (warning, until it succeeds again); cron was down (a notice when it is back) | global |
| Indexers | an indexer invalid or stuck working for over 60 min | global |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | global |
| Maintenance mode | on for over 15 min | global |
| system.log | a CRITICAL entry never seen before, with its file:line (again after 6 h); ERROR entries in the hourly status; 20 or more errors in 5 min | global |
| exception.log | an exception never seen before (NEW); 20 or more new entries in 15 min, with the top 3 exception classes | global |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | global |
| Mail delivery errors | a mail transport error in `system.log` or `exception.log` – failing until a mail is seen going out, or 60 min without a new error; 3 or more CommerceKings notifications failing | global |
| Sensitive data in logs | a card number, a secret or personal data in bulk in any log entry (every level): a high alert at once | global |
| Email communications disabled | *Disable Email Communications* on for any of the website's store views | the website's channel |
| Unsent sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min (cancelled and closed orders left out) | the website's channel |
| Server status | a line a server reported as `fail` (a container down, a disk 90% full…), `warn` lines as a warning; no report from a server for 15 min. Silent until a server reports ([how](docs/server-status.md)) | global |

## Before going live

- Set the channel and recipient (and *Website channels* where a website has its own), save, then *Send test alert*.
- Keep `commerceKingsNotificationSend` running – the same consumer as every CommerceKings notification.
- **Staging and copies stay silent.** App health stores the shop's domain when it is first switched on (*Shop
  domain*). A copy of the database at another domain – staging, a developer's machine – sends no message, no summary
  and no heartbeat ping (a copy never pings production's heartbeat), messages a copied queue still holds are dropped
  by its consumer, and the admin says so; after a real move, *Use <domain>* makes the new domain the shop's. To keep a
  staging site off for good, lock it there: `bin/magento config:set commercekings_app_health/general/enabled 0
  --lock-env` (written to its `app/etc/env.php`, so a fresh copy of the production database can't switch it on).
  Switched on without the admin (`bin/magento config:set`, `env.php` or `config.php`), App health's first run stores
  the domain instead. With `app:config:dump` or a `config.php` you deploy, include
  `commercekings_app_health/general/domain` (production's), or let the first run store it: a staging copy with that
  `config.php` is then still told apart. A copy switched on for the first time on staging stores staging's domain and
  is not silent – there, the lock above is the way.
- **Add a heartbeat and outside checks.** Magento can't report its own death: when the server, Docker, PHP, the database
  or cron is down, no check runs. Create a heartbeat check in an uptime service (e.g. Healthchecks.io, Better Stack or
  UptimeRobot) expecting a ping every 5 minutes, connect that service's **Slack integration**, and paste its URL into
  *Heartbeat URL*. With the hourly status on, a run pings only while the hourly status goes out: due and still not
  delivered after 10 minutes (each run tries it again), the pings stop – the queue or its consumer is down – and the
  service tells you. The pings also stop while App health is on but has no channel or recipient, and while a message
  fails to go out (a revoked token, a recipient the channel no longer takes); the hourly status is then posted for
  real, never quietly, until a post goes through again. A quiet status proves the queue, not the channel: the daily "All checks OK" post and the alerts
  show that the channel works. In the same service add **HTTP checks of your homepage and your checkout** (e.g.
  `/checkout/cart/`): they catch a site that is up for cron but down for shoppers.

## Settings

**Stores > Configuration > CommerceKings > App health**:

| Group | Fields (default) |
|---|---|
| Alerts | Enabled (No); Shop domain (stored when first switched on; *Use <domain>* after a move); Channel (a channel, e.g. slack); Recipient (e.g. for slack a Slack channel ID); Website channels (website, channel, recipient; none); Also post shared problems to website channels (No); Daily summary (Yes) at 08:00 (the default store's time zone; a website channel's in the website's); Repeat a failing check after (6 hours); Resolved after (2 OK runs in a row; window checks); New log errors at most every (30 minutes); Hourly status (Yes) every (60 minutes); Add the masked log message (No); Heartbeat URL (empty); *Send test alert*; *Send status now* (its own ACL resource, *Send status now*; off until app health is on with a channel and recipient) |
| System log, Exceptions | Log file (system.log, exception.log); Extra log files (more var/log files, e.g. payment.log, at most 5; never debug.log); Errors at once from (system.log, exception.log or an extra file; none) |
| Per check | Enabled, and its thresholds (see above); Queues off by default (it connects to the broker on every run) |

All settings are global (default scope); a website gets its own channel through *Website channels* only.

## System status from your server

A script on the server – run by the server, not by Magento – reports what Magento can't: containers (state, health,
CPU, memory), disks, memory, load and, when set, services, the last backup and certificate expiry. It writes
`var/app-health/status-<server>.json`; the *Server status* check reads it every 5 minutes and alerts on its failing
lines, on a server without a fresh status, and on a file that breaks the format. Ready setups for a server (systemd),
Docker Compose and Warden are in [docs/examples](docs/examples); a container setup reads Docker through a proxy that
passes only the container list and stats. How it works, the setups, the file format and the thresholds:
[System status from your server](docs/server-status.md).

## Extending

Checks, log sources, sensitive-data rules and validators are added in `di.xml` or XML, without PHP for the
rules; the stable interfaces (`@api`) and examples: [Extending](docs/extending.md).

## Privacy

Only safe labels, counts and masked text leave the server: never a log line as it is, an address or a value a
rule found. The sensitive-data rules and what is masked: [Sensitive data and masking](docs/privacy.md).

## Limitations

- Checks run inside Magento's cron: a cron that is down entirely is reported when it comes back – use the heartbeat for
  the rest. Nothing is sent from cron directly: every message goes through Notification's queue.
- Sales emails count only entities created in the last 24 hours; queues read the MySQL queue and RabbitMQ (AMQP)
  connections, not others (e.g. ActiveMQ).
- Server status is one check for all servers: a line failing while another already fails is told once ("Also
  failing: …"); an alert names the first 4. At most 20 servers, 50 lines each; needs a script run by the server
  (not possible on managed hosting without access to it). While no server reports, *Server status* is left out of
  the daily summary's lists, and the hourly status says "Server status: not set up (no status file from a server
  yet)".
- Messages are in English.

## Tested (as of 0.1.0)

- **Automated tests:** 208 unit tests (on every push); integration tests that run the generated SQL on MySQL/MariaDB
  (`Test/Integration`, on copies of the tables); PHPStan level 5 and the Magento2 coding standard.
- **End to end on Magento 2.4.9:** cron, indexers, caches, maintenance, email, exceptions, queues and sales emails
  triggered for real against a simulated Slack API – one alert, then one "recovered", the daily summary, the test
  button, the heartbeat, Slack's error modes, no private data in any message – and live to a real Slack channel.
- **Not tested end to end yet:** the system.log and sensitive-data checks (unit-tested). Server status: unit-tested
  (Magento's own file classes on a real folder); the example script, run on Debian, writes a file the check takes.
- **Cost:** a run with every check on takes about 47 ms and 11 queries; with a new 1 MB backlog in both system.log and
  exception.log, about 5 MB of memory (sensitive-data rules: about 13 ms per MB of log).

## Uninstall

No table of its own: the state lives in about 10 rows of Magento's `flag` table. **Uninstall:** remove the module, then
delete those rows (`DELETE FROM flag WHERE flag_code LIKE 'commercekings\_app\_health\_%'`) and, if you like, the
delivery state rows of the types `app_health_*` in `ck_notification_delivery_state`.

## More

[Messages](docs/messages.md) · [System status from your server](docs/server-status.md) · [Extending](docs/extending.md) ·
[Sensitive data and masking](docs/privacy.md) · [How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) ·
[Changes](CHANGELOG.md)

## Disclaimer

These checks watch what Magento itself can see. They don't replace server monitoring, backups or an uptime service –
add those for production.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
