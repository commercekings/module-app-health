# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack, or another text channel

[![License](https://badgen.net/github/license/commercekings/module-app-health)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-app-health)](https://github.com/commercekings/module-app-health/releases) [![Checks](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml)

**Hear about a broken cron, a stuck indexer or emails that don't go out before your customers do.**

- **Twelve checks, every 5 minutes:** cron, indexers, caches, maintenance mode, email settings, unsent sales emails,
  mail delivery errors, queue backlogs, `system.log` and `exception.log` (new errors, with file:line), sensitive data
  in logs, and your servers' own status (containers, disk, backups – optional script).
- **Only news:** a problem at once, *Resolved* once it is OK, a failure still going on every 6 hours; warnings and
  ordinary log errors in an hourly status, the rest in a daily summary.
- **Sensitive data in logs** (card numbers, secrets, personal data): a security alert at once – the rule and where,
  never the value.
- **Proof that alerts can go out:** the hourly status goes through the same queue; with a heartbeat URL, an outside
  service tells you when the server, cron or the queue is down.
- **Silent on staging:** a copy of the database at another domain sends nothing.
- **Private and light:** safe labels and counts only, never a log line as it is; admin, cron and the queue only –
  nothing on the storefront; a 5-second budget per run.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or another channel that sends
plain text (e.g. SMS; its module can add a format: [Extending](docs/extending.md)). Not email or WhatsApp: Notification
sends those from templates.

## Quick start

1. **Install:** `composer require commercekings/module-app-health:^0.1 commercekings/module-notification-slack:^0.1`,
   then `bin/magento setup:upgrade`.
2. **Slack:** *Create New App > From a manifest* with
   [the manifest](https://github.com/commercekings/module-notification-slack/blob/main/docs/slack-app-manifest.json),
   *Install to Workspace*, copy the *Bot User OAuth Token* (`xoxb-…`). Private channel: `/invite @Magento` in it.
3. **Connect:** *Stores > Configuration > CommerceKings > Notifications > Slack*: the token and a channel ID (the
   channel's details, at the bottom: `C0123456789`); save, *Send test message*.
4. **Switch on:** *CommerceKings > App health > Alerts*: Enabled *Yes*, Channel `slack` (the default), Recipient the
   alerts channel's ID; save, then *Send test alert* and *Send status now*.
5. **Run the queue consumer `commerceKingsNotificationSend`** – every message goes through it. Magento's cron starts
   it when `cron_consumers_runner > cron_run` is true in `app/etc/env.php` (and, if that lists `consumers`, this one
   is on the list); or run it under supervisor. The admin warns when the hourly status is overdue.
6. **Heartbeat:** a check in an uptime service (e.g. Healthchecks.io: a ping every 5 minutes, its Slack integration
   on), its ping URL in *Heartbeat URL* – the only alarm when the whole server, cron or the queue is down
   ([when it pings](#before-going-live)).
7. **Staging:** `bin/magento config:set commercekings_app_health/general/enabled 0 --lock-env`
   ([why](#before-going-live)).
8. *Optional:* your servers' status – containers, disks, memory, backups:
   [a script on the server](#system-status-from-your-server).

## When you hear about what

Every header names the shop and its domain: *Problem: Cron stopped · Acme Shop · shop.example.com*. "Global" is the
*Alerts* Channel and Recipient.

| What | When | Where |
|---|---|---|
| A check fails (*Problem*) | at once; again every 6 h while it fails; *Resolved* at the first OK run – system.log, exception.log, Queues, Mail delivery errors and Server status after 2 OK runs in a row; a problem back within 30 min of its *Resolved* waits until then | global; a website's own checks: its *Website channels* row |
| A check warns | not alone: in the hourly status and the daily summary | as above |
| New CRITICAL log entry or exception, sensitive data (*Security*), cron back after 15+ min down (*Cron was down*) | at once (type, count, time, file:line; the masked message only with *Add the masked log message*); then at most one message per check every 30 min, the rest in the next | global (Security: always) |
| ERROR log entries | the hourly status ("New errors this hour", top 5); a file in *Errors at once from*: at once, like CRITICAL | global |
| WARNING log entries | the daily summary | global |
| First hour after install | new CRITICAL entries in one "First hour: …" digest (or "First hour over: no error seen…"); one seen 10+ times at once | global |
| Hourly status (every 60 min, 15–180) | delivered through the queue each time – the heartbeat's proof; **posted** only when there is news since the last posted one (a new problem or warning, a new error, a known one 10+ times; "Still open: …" names what it told before) or as "All checks OK" once a day from *Daily summary at*; otherwise delivered quietly | global, all websites |
| *Send status now* | on click (at most every 30 s; refused on a copy): the checks run now and post as "Status now"; nothing is stored, alerts and the hourly status keep their timing | global |
| Daily summary | at *Daily summary at*, in the default store's time zone (a website channel's in the website's) | global, and one per *Website channels* row |

```
*Hourly status · Acme Shop · shop.example.com*

1 problem: Cron
Warnings: Queues (commercekings.notification.send: 120 messages, growing for 15 min)

New errors this hour: 14
• Unable to send (9×, new)
• NoSuchEntityException (5×)
```

A quiet delivery proves the queue and its consumer, not the channel; the alerts and the daily "All checks OK" post
prove the channel. Every message, and why each check fails: [Messages](docs/messages.md).

### Where messages go

- Shared checks, security findings, the hourly status, *Send test alert* and *Send status now*: the global channel.
- A website's own checks (*Email communications disabled*, *Unsent sales emails*) and its daily summary: its *Website
  channels* row, else the global channel (the header then names the website's store and domain; the global summary
  "Email communications disabled (EU Store)").
- *Also post shared problems to website channels* (No): shared alerts also to each website channel; security never.
- Slack posts a website's messages with that website's bot token: a website with its own Slack app or workspace needs
  a recipient channel in that workspace.

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; a job ending with an error (warning, until it succeeds again); 10+ jobs missed in an hour (warning); cron down 15+ min (a notice when it is back) | global |
| Indexers | an indexer invalid or stuck working for over 60 min | global |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | global |
| Maintenance mode | on for over 15 min | global |
| system.log | a CRITICAL entry never seen before, with its file:line (again after 6 h); ERROR entries in the hourly status; 20 or more errors in 5 min | global |
| exception.log | an exception never seen before (NEW); 20 or more new entries in 15 min, with the top 3 exception classes | global |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | global |
| Mail delivery errors | a mail transport error in `system.log` or `exception.log` – failing until a mail is seen going out, or 60 min without a new error; 3 or more CommerceKings notifications failing | global |
| Sensitive data in logs | a card number, a secret or personal data (IBANs, emails or phones in bulk) in any log entry (every level): a security alert at once | global |
| Email communications disabled | *Disable Email Communications* on for any of the website's store views | the website's channel |
| Unsent sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min (cancelled and closed orders left out) | the website's channel |
| Server status | a line a server reported as `fail` (a container down, a disk 90% full…), `warn` lines as a warning; no report from a server for 15 min. Silent until a server reports ([how](docs/server-status.md)) | global |

## Before going live

- **Staging and copies stay silent.** App health stores the shop's domain (the default store view's secure base URL)
  when it is first switched on. A database copy at another domain sends nothing, pings no heartbeat and drops the
  queued messages it copied; the admin says so, and *Use <domain>* accepts a real move. A copy switched on for the
  first time on staging stores staging's domain, so lock staging off: `bin/magento config:set
  commercekings_app_health/general/enabled 0 --lock-env`. Deploying `config.php`:
  [Copies of the shop](docs/how-it-works.md#copies-of-the-shop).
- **Heartbeat.** Magento can't report its own death. A run pings *Heartbeat URL* every 5 minutes, but not while the
  hourly status has been due for 10 minutes and is not out, while a message fails to go out (a revoked token, a
  recipient the channel refuses), or while App health is on with no channel or recipient – the uptime service then
  tells you. Also add **HTTP checks of your homepage and `/checkout/cart/`** there: up for cron is not up for shoppers.

## Settings

*Stores > Configuration > CommerceKings > App health* – every setting is global (default scope):

| Group | Fields (default) |
|---|---|
| Alerts | Enabled (No; needs Channel and Recipient) · Shop domain (stored when first switched on; *Use <domain>* after a move) · Channel (`slack`) · Recipient (e.g. a Slack channel ID) · Website channels (none) · Also post shared problems to website channels (No) · Daily summary (Yes) at (08:00) · Repeat a failing check after (6 h) · Resolved after (2 OK runs) · New log errors at most every (30 min) · Hourly status (Yes) every (60 min; 15–180) · Add the masked log message (No) · Heartbeat URL (empty; stored encrypted) · *Send test alert* · *Send status now* (ACL *Send status now*) |
| System log | Enabled · Alert at (20 errors) within (5 min) · Read at most (1024 KB per run and file) · Log file (system.log) · Extra log files (at most 5; never debug.log) · Errors at once from (system.log, exception.log or an extra file; none) |
| Exceptions | Enabled · Alert at (20 entries) within (15 min) · Read at most (1024 KB) · Log file (exception.log) |
| Sensitive data in logs | Enabled · Rule settings · Custom rules ([below](#sensitive-data-in-logs)) |
| Every other check | Enabled and its thresholds (defaults in *What is checked*); Queues is off by default (a broker connection every run) |

## System status from your server

A script on the server – run by the server, not by Magento – reports what Magento can't: containers, disks, memory,
load and, when set, services, the last backup and certificate expiry. It writes `var/app-health/status-<server>.json`
every minute; *Server status* alerts on its failing lines, on a server without a fresh status, and on a file that breaks
the format. Setups for a server (systemd), Docker Compose and Warden, the file format and the limits:
[System status from your server](docs/server-status.md).

## Sensitive data in logs

Every new log entry, at every level, is scanned. Rule sets and codes: `pci` (card_number, card_cvv, card_track),
`credentials` (private_key, jwt, auth_header, password_field, api_key), `personal` (iban, bulk_emails, bulk_phones).
Turn them on or off and add your own in *Rule settings* / *Custom rules*. Only safe labels, counts and masked text
leave the server: [Sensitive data and masking](docs/privacy.md). Checks, log sources, rules, renderers and the
status policy are `@api` extension points: [Extending](docs/extending.md).

## Limitations

- Checks run inside Magento's cron: a cron that is down entirely is reported when it comes back – the heartbeat covers
  the rest. Nothing is sent from cron directly: every message goes through Notification's queue.
- Sales emails count only entities created in the last 24 hours; queues read the MySQL queue and RabbitMQ (AMQP), not
  others (e.g. ActiveMQ). Server status needs a script on the server (not on managed hosting without access to it).
- Messages are in English. Tested: [Compatibility](COMPATIBILITY.md).

## Uninstall

Remove the module, then delete its state and settings:
`DELETE FROM flag WHERE flag_code LIKE 'commercekings\_app\_health\_%'`,
`DELETE FROM core_config_data WHERE path LIKE 'commercekings_app_health/%'` (and a `--lock-env` entry in env.php);
optionally the `app_health_*` rows of `ck_notification_delivery_state`.

## More

[Messages](docs/messages.md) · [System status from your server](docs/server-status.md) · [Extending](docs/extending.md) ·
[Sensitive data and masking](docs/privacy.md) · [How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) ·
[Changes](CHANGELOG.md). These checks watch what Magento itself can see: they don't replace server monitoring, backups
or an uptime service.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
