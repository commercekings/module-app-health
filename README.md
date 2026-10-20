# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack or any Notification channel

[![License](https://badgen.net/github/license/commercekings/module-app-health)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-app-health)](https://github.com/commercekings/module-app-health/releases) [![Checks](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml)

**Hear about a broken cron, a stuck indexer or emails that don't go out before your customers do.**

- **Sensitive data in your logs.** A card number, a secret or personal data in bulk written into a log is a high
  alert at once – the rule and where, never the value.
- **Ten checks, every 5 minutes.** Cron, indexers, caches, maintenance mode, email settings, `system.log` and
  `exception.log` (new errors, with where they happened), queue backlogs, and sales emails that don't go out.
- **Only news.** A message when something breaks and one when it is back ("recovered"); a check still failing is
  repeated every 6 hours, never every 5 minutes.
- **The right channel.** Checks for the whole shop go to one channel; each website's own (its email settings, its
  unsent order emails, its daily summary) to its own.
- **A daily summary.** Cron runs and errors, exceptions by class, and what had problems – at the time you choose.
- **Light.** Admin, cron and Notification's queue only – nothing on the storefront or in checkout. Indexed, bounded
  queries; only the new bytes of the exception log; a 5-second budget per run.
- **Private.** Log errors are sent as a safe label (the exception class, or the message's first words with every
  variable part as `***`), file:line and a fingerprint – never the line itself. Optionally the message, masked.
- **When the server itself is down.** An optional heartbeat lets an outside service alert you when the pings stop.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or any installed channel.

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; a job ending with an error (warning, until it succeeds again); cron was down (warning, when it is back) | all websites |
| Indexers | an indexer invalid or stuck working for over 60 min | all websites |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | all websites |
| Maintenance mode | on for over 15 min | all websites |
| system.log | an error never seen before (NEW, with its file:line); a CRITICAL entry (again after 6 h); 20 or more errors in 5 min | all websites |
| exception.log | an exception never seen before (NEW); 20 or more new entries in 15 min, with the top 3 exception classes | all websites |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | all websites |
| Sending | a mail transport error in `system.log` or `exception.log` – failing until a mail is seen going out, or 60 min without a new error; 3 or more CommerceKings notifications failing | all websites |
| Sensitive data in logs | a card number, a secret or personal data in bulk in any log entry (every level): a high alert at once | all websites |
| Email sending | *Disable Email Communications* on for any of the website's store views | the website |
| Sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min | the website |

### Why it fails

Each alert says why, as far as Magento's own data shows it, and where to look (`> Check: …`):

| Check | Causes |
|---|---|
| Cron | nothing scheduled at all (cron not started: the container or crontab); jobs scheduled but none starting; a job stuck "running" and how many jobs of its group wait; the same job failing 3+ times in 2 h, with its latest error masked; 10+ jobs missed in an hour (cron too rare or too slow) |
| Indexers | which indexer, invalid or stuck working since when, its mode (on save or by schedule); when `indexer_reindex_all_invalid` and `indexer_update_all_views` last ran |
| Caches | which type: disabled, or invalidated since when |
| Maintenance mode | on since when; "likely left on after a deployment" after 30 min |
| Queues | which queue and its consumer; whether cron starts the consumers (`cron_consumers_runner` in env.php) and when `consumers_runner` last ran |
| Sending | the kind of mail transport failure – refused, timed out, login refused, TLS, host not found (DNS) – with the port and the latest error (masked); the failing CommerceKings notifications |
| Email sending | the scope that disables it (default, website, store view, or env.php) |
| Sales emails | how many are unsent and the oldest; asynchronous sending or refused at checkout |

```
🔴 *Cron jobs stuck – Main Website Store (all websites)*
> Job indexer_reindex_all_invalid running for 3 h (since 08:12).
> 12 other jobs in its group are waiting.
> Check: The process may have crashed: restart cron, then clear the stuck job (status "running" in cron_schedule).
```

A check of another module gives its own causes the same way (`Api\Data\Cause` on its `CheckResult`).

**All websites** checks alert once, to the default scope's recipient. **The website** checks, and the daily summary,
go to the website's recipient (the default one unless the website sets its own).

One message per problem; its first line is what a phone notification shows:

```
🔴 *Cron stopped – Main Website Store (all websites)*
No job has run since 10:39 (65 min).
Check: Is the cron container (or crontab) running? bin/magento cron:run by hand shows errors.
```

```
✅ *Recovered: Cron – Main Website Store (all websites)*
OK again since 11:52.
```

```
🔴 *New errors: system.log – Main Website Store (all websites)*
🆕 🔴 PaymentException: Card *** declined for order *** ×3 · 14:02 · app/code/Vendor/Pay/Model/Gateway.php:120 · #a1b2c3d4
Check: var/log/system.log: look for the fingerprint's error around that time.
```

```
*Daily summary – Main Website Store – 20 Oct*

*Problems (1)*
🔴 Cron: no job has run since 10:39 (65 min)

*Logs (24 h)*
system.log: 1 critical, 14 errors, 52 warnings
exception.log: 14
🔴 *Critical*
🆕 PaymentException: Card *** declined for order *** ×3 · 14:02 · app/code/Vendor/Pay/Model/Gateway.php:120 · #a1b2c3d4
🟠 *Errors*
Unable to send the order *** ×9 · 13:40 · app/code/Vendor/Mail/Model/Sender.php:84 · #5e6f7a8b
NoSuchEntityException: No such entity with *** ×5 · 11:02 · #0c1d2e3f
🟡 *Warnings*
Slow response from *** ×52 · 14:10 · #9a8b7c6d

*Email*
Sales emails unsent: 0 · Mail errors: 0

*OK all day*
Indexers · Caches · Maintenance · Queue · Sending · Email sending
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
| Alerts | Enabled (No); Channel (slack); Recipient (e.g. a Slack channel ID); Daily summary (Yes) at 08:00; Repeat a failing check after (6 hours); Add the masked log message (Yes); Heartbeat URL (empty); *Send test alert* |
| System log, Exceptions | Log file (system.log, exception.log); Extra log files (more var/log files, e.g. payment.log; never debug.log) |
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

- **A check:** implement `Api\CheckInterface` (shop-wide) or `Api\WebsiteCheckInterface` (per website) and add it to
  `Model\CheckPool`'s "checks" in your `di.xml`. Return an `Api\Data\CheckResult` – ok, warn or fail, a title, a
  short detail of names and numbers, optional counts, gauges and notices – and give it a `getHint()`. Its switch is
  `commercekings_app_health/<code>/enabled` (on when not set).
- **A log source:** implement `Api\LogSourceInterface` (new data since a cursor) and `Api\LogParserInterface` (data to
  `Api\Data\LogEntry`), and add both to `Model\Log\LogSourcePool`'s "sources" for the `system_log` or `exceptions`
  check. App health makes every entry safe itself – fingerprint, label, masking, file:line – whoever parsed it.

Details and examples: [how it works](docs/how-it-works.md).

## Sensitive data in logs

A log that holds card data, secrets or personal data is a security problem of its own (for card data, a PCI DSS
breach). Every new log entry – every level, every log source – is scanned before anything is masked, and a finding is
a high alert at once, with the rule, how often, the last time and where – never the value:

```
🚨 *Sensitive data in logs – Main Website Store (all websites)*
> 🚨 Possible PCI: payment card number in system.log (×3, last 14:02) at app/code/Vendor/Pay/Model/Gateway.php:120 · #a1b2c3d4
> Check: Stop logging it at the source, then rotate or remove the log: card data in logs breaks PCI DSS.
```

| Rule set (severity) | Rules |
|---|---|
| `pci` – PCI (critical) | `card_number` 13–19 digits with a card scheme's prefix and a valid Luhn check digit; `card_cvv` cvv/cvc/cid fields; `card_track` magnetic stripe track data |
| `credentials` – Credentials (critical) | `private_key` PEM private keys; `jwt` JSON web tokens; `auth_header` Bearer/Basic authorization headers; `password_field` password=… / "password": …; `api_key` Stripe live keys, Slack tokens, AWS access keys, Google API keys, GitHub tokens |
| `personal` – Personal data (warning) | `iban` IBANs with a valid checksum; `bulk_emails` 5+ email addresses in one entry; `bulk_phones` 5+ phone numbers in one entry |

Country-specific ID formats and other rules can come as rule-set modules.

- **Add a rule set from a module:** one file, no PHP – `etc/app_health_rules.xml`, merged with ours by code. The same
  file overrides a shipped rule (by its code) or turns a rule or a set off:

  ```xml
  <config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:noNamespaceSchemaLocation="urn:magento:module:CommerceKings_AppHealth:etc/app_health_rules.xsd">
      <ruleset code="loyalty" label="Loyalty" severity="critical">
          <hint>Log the customer id instead of the loyalty card number.</hint>
          <rule code="loyalty_card" label="loyalty card number">
              <pattern><![CDATA[/\bLC-\d{8}\b/]]></pattern>
          </rule>
      </ruleset>
      <ruleset code="pci"><rule code="card_cvv" severity="warning"/></ruleset>
      <ruleset code="personal" enabled="false"/>
  </config>
  ```

- **Add a validator** (a check a pattern can't do, like Luhn): a class implementing
  `Api\SensitiveDataValidatorInterface` (`getCode()`, `getPattern()` for its candidates, `isValid($match)`) and one item
  in `Model\Sensitive\ValidatorPool`'s "validators"; then `validator="your_code"` (and `type="validator"` to use its
  pattern) in any rule set.
- **In the admin** (*Sensitive data in logs*): *Rule settings* turn a set or a rule (`pci`, `pci/card_cvv`) on or off
  or change its severity; *Custom rules* add regex rules to a shipped set or a new one, checked on save (they compile,
  at most 300 characters, no nested quantifiers, fast on long lines).

## What is masked

A log line never leaves the server as it is. An error is shown by file:line (relative to the Magento root), an
8-character fingerprint and:

- **The masked message (default):** its first 160 characters, one line, with **emails, phone numbers, numbers of 4
  digits or more, tokens, URLs and quoted values** as `***` (an exception's class first). PHP's function prefixes go
  first, and a long message keeps its trailing reason ("… (Connection refused)"). **Not masked:** names and
  other free text, e.g. "Customer jane doe not found" or SQL. The masked message is also kept in the module's table
  for the summary (the latest per error, at most 500 errors).
- **The label only** (*Add the masked log message* = No): the exception class, else the message's first 8 words with
  URLs, emails, quoted values, hex strings, numbers and paths after their first segment as `***` – and any capitalized
  word after the first (often a name). Use it when your logs may contain names.

## Limitations

- Checks run inside Magento's cron: a cron that is down entirely is reported when it comes back – use the heartbeat for
  the rest.
- Sales emails count only entities created in the last 24 hours; queues read the MySQL queue and RabbitMQ (AMQP)
  connections, not others (e.g. ActiveMQ).
- Messages are in English.

## Tested (as of 0.1.0)

- **Automated tests:** 137 unit tests (on every push), PHPStan level 5 and the Magento2 coding standard.
- **End to end on Magento 2.4.9:** cron, indexers, caches, maintenance, email, exceptions, queues and sales emails
  triggered for real against a simulated Slack API – one alert, then one "recovered", the daily summary, the test
  button, the heartbeat, Slack's error modes, no private data in any message – and live to a real Slack channel.
- **Not tested end to end yet:** the system.log and sensitive-data checks (unit-tested).
- **Cost:** a run with every check on takes about 47 ms and 11 queries; with a new 1 MB backlog in both system.log and
  exception.log, about 5 MB of memory (sensitive-data rules: about 13 ms per MB of log).

## More

[How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These checks watch what Magento itself can see. They don't replace server monitoring, backups or an uptime service –
add those for production.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
