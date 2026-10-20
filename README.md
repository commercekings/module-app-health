# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack or any Notification channel

[![License](https://badgen.net/github/license/commercekings/module-app-health)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-app-health)](https://github.com/commercekings/module-app-health/releases) [![Checks](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-app-health/actions/workflows/checks.yml)

**Hear about a broken cron, a stuck indexer or emails that don't go out before your customers do.**

- **Sensitive data in your logs.** A card number, a secret or personal data in bulk written into a log is a high
  alert at once – the rule and where, never the value.
- **Ten checks, every 5 minutes.** Cron, indexers, caches, maintenance mode, email settings, `system.log` and
  `exception.log` (new errors, with where they happened), queue backlogs, and sales emails that don't go out.
- **Only news, at the right pace.** A problem at once, "resolved" once it is OK (for the log, queue and sending checks
  two runs in a row); a check still failing is repeated every 6 hours; a check's warning in the hourly status.
  Critical log errors and sensitive data at once, log errors in the hourly status, log warnings in the daily summary.
- **Proof that alerts can go out.** An hourly status ("All checks OK", or the problems) through the same queue and
  channel: when it stops coming, so does the heartbeat.
- **The right channel.** Checks for the whole shop go to one channel; each website's own (its email settings, its
  unsent order emails, its daily summary) to its own.
- **A daily summary.** Cron runs and errors, exceptions by class, and what had problems – at the time you choose.
- **Light.** Admin, cron and Notification's queue only – nothing on the storefront or in checkout. Indexed, bounded
  queries; only the new bytes of the exception log; a 5-second budget per run.
- **Private.** Log errors are sent as a safe label (the exception class, or the message's first words with every
  variable part as `***`), in alerts with file:line and the message masked (on by default) – never the line itself.
- **When the server itself is down.** A heartbeat lets an outside service alert you when the pings stop – the server,
  cron, or the sending path down.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or any installed channel.

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; a job ending with an error (warning, until it succeeds again); cron was down (warning, when it is back) | all websites |
| Indexers | an indexer invalid or stuck working for over 60 min | all websites |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | all websites |
| Maintenance mode | on for over 15 min | all websites |
| system.log | a CRITICAL entry never seen before, with its file:line (again after 6 h); ERROR entries in the hourly status; 20 or more errors in 5 min | all websites |
| exception.log | an exception never seen before (NEW); 20 or more new entries in 15 min, with the top 3 exception classes | all websites |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | all websites |
| Sending | a mail transport error in `system.log` or `exception.log` – failing until a mail is seen going out, or 60 min without a new error; 3 or more CommerceKings notifications failing | all websites |
| Sensitive data in logs | a card number, a secret or personal data in bulk in any log entry (every level): a high alert at once | all websites |
| Email sending | *Disable Email Communications* on for any of the website's store views | the website |
| Sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min | the website |

### Why it fails

Each alert says why, as far as Magento's own data shows it, and what to do (`What to do: …`):

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
*Problem: Cron jobs stuck · Main Website Store (all websites)*

Job indexer_reindex_all_invalid running for 3 h (since 08:12).
12 other jobs in its group are waiting.

What to do: The process may have crashed: restart cron, then clear the stuck job (status "running" in cron_schedule).
```

A check of another module gives its own causes the same way (`Api\Data\Cause` on its `CheckResult`).

### When you hear about what

The logs are read every 5 minutes; what you hear, and when:

| What | When | Where |
|---|---|---|
| A check going to *Problem* (cron, indexers, caches, ...) | at once; again every 6 h while it fails; *Resolved* at the first OK run – for system.log, exception.log, Queues and Sending after 2 OK runs in a row | its recipient |
| A check's *Warning* | the hourly status ("Warnings: Queue (…)") and the daily summary | default recipient, each website's |
| Sensitive data in a log (*Security*) | at once – then at most one message per check every 30 min, what comes in between in the next | default recipient |
| A new CRITICAL log entry (and exceptions) | at once – the same 30-minute limit | default recipient |
| ERROR log entries | the hourly status: "New errors this hour: N" and the 5 most frequent | default recipient |
| WARNING log entries | the daily summary | each website's recipient |
| The hourly status | every hour, always – "All checks OK" or the problems: the proof that alerts can go out | default recipient |
| The daily summary | once a day at *Daily summary at* | each website's recipient |

```
*Hourly status · Main Website Store (all websites)*

1 problem: Cron
Warnings: Queue (commercekings.notification.send: 120 messages)

New errors this hour: 14
• Unable to send (9×, new)
• NoSuchEntityException (5×)
```

**All websites** checks alert once, to the default scope's recipient. **The website** checks, and the daily summary,
go to the website's recipient (the default one unless the website sets its own).

One message per problem, in plain Slack text without emoji: a bold first line that says what and where (what a phone
notification shows; *Problem*, *Warning*, *Resolved*, *New error*, *Security*), then one fact per line and what to do:

```
*Problem: Cron stopped · Main Website Store (all websites)*

No job has run since 10:39 (65 min).

What to do: Is the cron container (or crontab) running? bin/magento cron:run by hand shows errors.
```

A failing check told again after the repeat interval ends with "Still failing since 19 Oct 09:00.".

```
*Resolved: Cron · Main Website Store (all websites)*

OK again since 11:52.
```

```
*New error in system.log · Main Website Store (all websites)*

Critical: PaymentException (3×, 14:02)
Where: app/code/Vendor/Pay/Model/Gateway.php:120
Message: Card *** declined for order ***

What to do: var/log/system.log: look for the error around that time, in the file it names.
```

The daily summary leaves out file paths and messages, and any section without content:

```
*Main Website Store · daily summary · 20 Oct*

*Needs attention*
• Cron: no job has run since 10:39 (65 min)
• Caches: warned earlier, OK now

*Errors, last 24 h*
• Critical: PaymentException (3×, 14:02, new)
• Error: Unable to send the order *** (9×, 13:40)
• Error: NoSuchEntityException (5×, 11:02)
• Warning: Slow response from *** (52×, 14:10)

*Email*
• 2 order emails not sent, oldest 09:12
• 3 mail errors, last 09:40

*All OK*
• Indexers, maintenance, queue, sending, email sending
```

At most 5 critical, 10 errors and 3 warnings, the latest first, then "• …and N more". With nothing unsent and no mail
errors, *Email* is "• All order emails sent, no mail errors".

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
| Alerts | Enabled (No); Channel (slack); Recipient (e.g. a Slack channel ID); Daily summary (Yes) at 08:00; Repeat a failing check after (6 hours); Recovered after (2 OK runs in a row); New log errors at most every (30 minutes); Hourly status (Yes) every (60 minutes); Add the masked log message (Yes); Heartbeat URL (empty); *Send test alert* |
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
  *Heartbeat URL*. With the hourly status on, a run pings only while the last hourly status went out (within its hour
  and 10 minutes): when alerts can't be sent – the queue, its consumer or the channel down – the pings stop too, and
  the service tells you. The first ping follows the first hourly status. In the same service add **HTTP checks of your homepage and your checkout** (e.g.
  `/checkout/cart/`): they catch a site that is up for cron but down for shoppers.

## Extending

Stable extension points (`@api`); the names are in the `CommerceKings\AppHealth` namespace.

| For | Use |
|---|---|
| A check for the whole shop | `Api\CheckInterface` (`getCode()`, `getLabel()`, `getHint()`, `run()`) in `Model\CheckPool` "checks" |
| A check per website | `Api\WebsiteCheckInterface` (`runForWebsites($websiteIds)`: a result per website id) in the same pool |
| What a check returns | `Api\Data\CheckResult` – `ok()`, `warn()` or `fail()`, a title, a one-line detail of names and numbers, counts for the daily summary, gauges, notices (one-off news) – and its causes, `Api\Data\Cause` (code, detail, hint) via `withCauses()` |
| A check's on/off switch | `commercekings_app_health/<code>/enabled` (on when not set; a field in your `system.xml` shows it) |
| A log source | `Api\LogSourceInterface` (new data since a cursor, as `Api\Data\LogChunk`) and `Api\LogParserInterface` (data to `Api\Data\LogEntry` items) in `Model\Log\LogSourcePool` "sources", for the `system_log` or `exceptions` check; app health makes every entry safe itself (fingerprint, label, masking, file:line) |
| Sensitive-data rules, no PHP | `etc/app_health_rules.xml` in any module (schema `etc/app_health_rules.xsd`), merged by rule set and rule code: add a set or a rule, override a shipped one, or turn it off (`enabled="false"`) |
| A validator for rules | `Api\SensitiveDataValidatorInterface` (`getCode()`, `getPattern()`, `isValid($match)`) in `Model\Sensitive\ValidatorPool` "validators"; rules use it as `validator="<code>"` (`type="validator"` for its pattern) |
| Rules in the admin | *Sensitive data in logs*: *Rule settings* (a set or rule on or off, its severity) and *Custom rules* (regex rules, checked on save) |
| Where messages go | *Alerts > Channel* (`slack` by default) and *Recipient*, per website: any Notification channel, so any provider module (Notification's `Api\ProviderInterface`) works; sent through Notification's queue as the types `app_health_alert` and `app_health_summary` |

A check, in your module's `etc/di.xml`:

```xml
<type name="CommerceKings\AppHealth\Model\CheckPool">
    <arguments>
        <argument name="checks" xsi:type="array">
            <item name="erp_sync" xsi:type="object">Vendor\Erp\Model\Health\SyncCheck</item>
        </argument>
    </arguments>
</type>
```

A rule set, in your module's `etc/app_health_rules.xml` – a set of its own, a shipped rule changed, a shipped set off:

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

How to use them – a run, the state, a log source and its parser, the rules: [how it works](docs/how-it-works.md).

## Sensitive data in logs

A log that holds card data, secrets or personal data is a security problem of its own (for card data, a PCI DSS
breach). Every new log entry – every level, every log source – is scanned before anything is masked, and a finding is
a high alert at once, with the rule, how often, the last time and where – never the value:

```
*Security: sensitive data in logs · Main Website Store (all websites)*

PCI: payment card number in system.log (3×, last 14:02)
Where: app/code/Vendor/Pay/Model/Gateway.php:120

What to do: Stop logging it at the source, then rotate or remove the log: card data in logs breaks PCI DSS.
```

| Rule set (severity) | Rules |
|---|---|
| `pci` – PCI (critical) | `card_number` 13–19 digits with a card scheme's prefix and a valid Luhn check digit; `card_cvv` cvv/cvc/cid fields; `card_track` magnetic stripe track data |
| `credentials` – Credentials (critical) | `private_key` PEM private keys; `jwt` JSON web tokens; `auth_header` Bearer/Basic authorization headers; `password_field` password=… / "password": …; `api_key` Stripe live keys, Slack tokens, AWS access keys, Google API keys, GitHub tokens |
| `personal` – Personal data (warning) | `iban` IBANs with a valid checksum; `bulk_emails` 5+ email addresses in one entry; `bulk_phones` 5+ phone numbers in one entry |

Country-specific ID formats and other rules can come as rule-set modules.

Add, change or turn off rules from a module (one XML file, no PHP), add a validator, or set them in the admin
(*Rule settings*, *Custom rules* – a custom rule is checked on save: it compiles, at most 300 characters, no nested
quantifiers, fast on long lines and on a realistic 80 KB log sample): see [Extending](#extending).

## What is masked

A log line never leaves the server as it is. An error is shown by its label – the exception class, else
the message's first 3 words – and, in alerts, file:line (relative to the Magento root) and the masked message. *Add
the masked log message* is **on by default**, because a readable message is what makes an alert useful; turn it off
when your logs may hold free text you don't want in a chat.

Always masked, in the label and the message, as `***`:

- the value after a secret's key word – **password, pass, pwd, secret, token, key, api key, pin, cvv, cvc** – whatever
  its length ("password hunter2", "pin=12");
- one or two words after a person's key word – **customer, name, first/last name, user, recipient, cardholder, buyer,
  contact** ("customer jane doe could not pay");
- **emails, phone numbers, numbers of 4 digits or more, IPv4 and IPv6 addresses, tokens, URLs and quoted values**.

- **The masked message:** its first 160 characters, one line. PHP's function prefixes go first, and a long message
  keeps its trailing reason ("… (Connection refused)"). **Not masked:** other free text, e.g. a capitalized name with no
  key word before it, or SQL. The latest per error is kept in the module's table (at most 500 errors).
- **The label:** at most 3 words, with URLs, emails, quoted values, hex strings, numbers and paths after their first
  segment – and any capitalized word after the first (often a name) – as `***`. The daily summary shows labels only.
- **Off** (*Add the masked log message* = No): labels only, everywhere; a cron job's error text is not read either.

## Limitations

- Checks run inside Magento's cron: a cron that is down entirely is reported when it comes back – use the heartbeat for
  the rest. Nothing is sent from cron directly: every message goes through Notification's queue.
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
