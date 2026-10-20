# App Health for the Shop Team<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-app-health/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · RabbitMQ or MySQL queue · Slack or any Notification channel

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
  variable part as `***`), in alerts with file:line and the message masked (on by default) – never the line itself.
- **When the server itself is down.** A heartbeat lets an outside service alert you when the pings stop – the server,
  cron, or the sending path down.
- **The server's status too.** A script on the server writes its containers, disk, memory, load, backups and
  certificates to a file every minute; what it reports as failing alerts like any check, and a server that stops
  reporting is a problem.

Sends through [Notification](https://github.com/commercekings/module-notification): Slack with
[module-notification-slack](https://github.com/commercekings/module-notification-slack), or any installed channel.

## What is checked

| Check | Alert when (default) | Sent to |
|---|---|---|
| Cron | no job has run for 5 min; a job running for over 60 min; a job ending with an error (warning, until it succeeds again); cron was down (warning, when it is back) | global |
| Indexers | an indexer invalid or stuck working for over 60 min | global |
| Caches | a cache type disabled; invalidated for over 60 min (warning) | global |
| Maintenance mode | on for over 15 min | global |
| system.log | a CRITICAL entry never seen before, with its file:line (again after 6 h); ERROR entries in the hourly status; 20 or more errors in 5 min | global |
| exception.log | an exception never seen before (NEW); 20 or more new entries in 15 min, with the top 3 exception classes | global |
| Queues (off) | 1,000 or more messages in a queue; a queue growing for 15 min (warning) | global |
| Sending | a mail transport error in `system.log` or `exception.log` – failing until a mail is seen going out, or 60 min without a new error; 3 or more CommerceKings notifications failing | global |
| Sensitive data in logs | a card number, a secret or personal data in bulk in any log entry (every level): a high alert at once | global |
| Email sending | *Disable Email Communications* on for any of the website's store views | the website's channel |
| Sales emails | 3 or more orders, invoices, shipments or credit memos not emailed after 30 min (cancelled and closed orders left out) | the website's channel |
| Server status | a line a server reported as `fail` (a container down, a disk 90% full…), `warn` lines as a warning; no report from a server for 15 min. Silent until a server reports ([how](#system-status-from-your-server)) | global |

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
| Server status | each line not OK: the server, what, its value and the script's detail (masked); "No status from host-1 since 14:02" |

```
*Problem: Cron jobs stuck · Main Website Store*

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
| A check's *Warning* | the hourly status ("Warnings: Queue (…)") and the daily summary | global (a website's own: its summary) |
| Sensitive data in a log (*Security*) | at once – then at most one message per check every 30 min, what comes in between in the next | global |
| A new CRITICAL log entry (and exceptions) | at once – the same 30-minute limit | global |
| ERROR log entries | the hourly status: "New errors this hour: N" and the 5 most frequent | global |
| WARNING log entries | the daily summary | global |
| The hourly status | queued and delivered every hour (the heartbeat's proof); **posted** only with problems, warnings or new errors – and "All checks OK" once a day, at the first status from *Daily summary at* | global – for all websites |
| *Send status now* (admin) | when you press it: the checks run now and what they find is posted as "Status now" (problems, warnings, what is not set up, the log errors since the last hourly status) – nothing else changes: no alert is marked told, the hourly status keeps its time | global |
| The daily summary | once a day at *Daily summary at*: a global one (the shared checks, and websites without a channel of their own, named) and one per website channel (its own items, in its time zone) | global, each website channel |

```
*Hourly status · Main Website Store*

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

### Where messages go

Every setting is global (*Stores > Configuration > CommerceKings > App health*, default scope):

| Messages | Go to |
|---|---|
| The shared checks (cron, indexers, caches, maintenance, logs, queues, sending, server status), the hourly status, *Send test alert*, *Send status now* | the global channel and recipient |
| Security findings (sensitive data in logs) | the global channel – always, never a website channel |
| A website's own checks (*Email sending*, *Sales emails*) | its *Website channels* row – else the global channel, the website named ("Email sending (EU Store)") |
| The daily summary | a global one; and one per *Website channels* row with only that website's items |
| The shared problems, also to website channels | only with *Also post shared problems to website channels* (default No); security findings never |

A website channel row is checked on save: a website once, a channel and a recipient each, the recipient as the channel's
normalizer takes it.

One message per problem, in plain Slack text without emoji: a bold first line that says what and where (what a phone
notification shows; *Problem*, *Warning*, *Resolved*, *New error*, *Security*), then one fact per line and what to do:

```
*Problem: Cron stopped · Main Website Store*

No job has run since 10:39 (65 min).

What to do: Is the cron container (or crontab) running? bin/magento cron:run by hand shows errors.
```

A failing check told again after the repeat interval ends with "Still failing since 19 Oct 09:00.".

```
*Resolved: Cron · Main Website Store*

OK again since 11:52.
```

```
*New error in system.log · Main Website Store*

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

No table of its own: the state lives in about 10 rows of Magento's `flag` table. **Uninstall:** remove the module, then
delete those rows (`DELETE FROM flag WHERE flag_code LIKE 'commercekings\_app\_health\_%'`) and, if you like, the
delivery state rows of the types `app_health_*` in `ck_notification_delivery_state`.

## Settings

**Stores > Configuration > CommerceKings > App health**:

| Group | Fields (default) |
|---|---|
| Alerts | Enabled (No); Channel (slack); Recipient (e.g. a Slack channel ID); Website channels (website, channel, recipient; none); Also post shared problems to website channels (No); Daily summary (Yes) at 08:00 (the default store's time zone; a website channel's in the website's); Repeat a failing check after (6 hours); Resolved after (2 OK runs in a row; window checks); New log errors at most every (30 minutes); Hourly status (Yes) every (60 minutes); Add the masked log message (Yes); Heartbeat URL (empty); *Send test alert*; *Send status now* (its own ACL resource, *Send status now*; off until app health is on with a channel and recipient) |
| System log, Exceptions | Log file (system.log, exception.log); Extra log files (more var/log files, e.g. payment.log; never debug.log) |
| Per check | Enabled, and its thresholds (see above); Queues off by default (it connects to the broker on every run) |

All settings are global (default scope); a website gets its own channel through *Website channels* only.

## Before going live

- Set the channel and recipient (and *Website channels* where a website has its own), save, then *Send test alert*.
- Keep `commerceKingsNotificationSend` running – the same consumer as every CommerceKings notification.
- **Add a heartbeat and outside checks.** Magento can't report its own death: when the server, Docker, PHP, the database
  or cron is down, no check runs. Create a heartbeat check in an uptime service (e.g. Healthchecks.io, Better Stack or
  UptimeRobot) expecting a ping every 5 minutes, connect that service's **Slack integration**, and paste its URL into
  *Heartbeat URL*. With the hourly status on, a run pings only while the hourly status goes out: due and still not
  delivered after 10 minutes (each run tries it again), the pings stop – the queue or its consumer is down – and the
  service tells you. A quiet status proves the queue, not the channel: the daily "All checks OK" post and the alerts
  show that the channel works. In the same service add **HTTP checks of your homepage and your checkout** (e.g.
  `/checkout/cart/`): they catch a site that is up for cron but down for shoppers.

## System status from your server

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

**Set up** – one of three, with ready files in [docs/examples](docs/examples):

| Setup | Runs | Docker access | Files |
|---|---|---|---|
| A server you run (VM, bare metal, Docker host) | on the host, every minute (systemd timer or cron) | the host's own Docker CLI, as root | [systemd/](docs/examples/systemd) – service, timer, env example |
| A Docker Compose stack | a sidecar container next to Magento's | through a socket proxy (read-only API) | [docker-compose/](docs/examples/docker-compose) |
| Warden (development) | a sidecar in the project's `.warden/warden-env.yml`, opt-in profile `host-status` | through a socket proxy | [warden/](docs/examples/warden) |

On a host, root only collects the readings (Docker needs it); the file is written as the owner of `var/app-health` –
the web server's user, by its numeric id, also under Docker's user namespaces. That folder is the web user's to write,
so root never works in it: a temporary file there could be swapped for a link to any file on the server, and root
would write through it. The script refuses when the folder (or `var/`, before the folder exists) is owned by root. A
sidecar runs as the web server's user from the start.

**Why a proxy in a container:** Docker's socket – even mounted `:ro` – is the whole Docker API, so whoever holds it can
start a privileged container: root on the host. The examples give the sidecar `tecnativa/docker-socket-proxy`
instead: `GET` on `/containers` only (state, health, restarts, stats; plus `/_ping` and `/version` for the Docker CLI),
`POST` off – nothing can be created, started, stopped or exec'd – on an internal network that nothing else reaches.

1. Copy [docs/examples/host-status.sh](docs/examples/host-status.sh) (mode 0755); on a host it needs `bash`, `jq`,
   `setpriv` (util-linux) and `docker`; the sidecars install theirs.
2. Its settings – `/etc/ck-host-status.env` (mode 0600) on a host, `environment:` in a sidecar: `CK_STATUS_DIR`
   (Magento's `var/app-health`, as the script sees it), and as needed the server's name, the containers
   (`CK_CONTAINERS`, or every container of a Compose project: `CK_COMPOSE_PROJECT`), disks, a backup marker file,
   the host names whose certificates to watch – the script's header lists them.
3. Try it: `host-status.sh --dry-run` prints the report; `host-status.sh` writes the file.
4. Schedule it – on a host the [timer](docs/examples/systemd) (a run is never started twice; its output in
   `journalctl -u ck-host-status`), or cron with `flock`:
   `* * * * * root flock -n /run/ck-host-status.lock /usr/local/bin/host-status.sh`; a sidecar loops by itself.

| Hosting | `CK_STATUS_DIR` and permissions |
|---|---|
| Docker Compose (e.g. m2_infra), Warden | on the host: the folder mounted as Magento's `var/`, e.g. `<APP_PATH>/shared/var/app-health` (read-only containers are fine: the shared `var/` is writable); in a sidecar: that folder or volume mounted at `/status` |
| A server or VM without Docker | `<Magento root>/var/app-health`; set `CK_CONTAINERS=` (empty) and list the services instead (`CK_SERVICES="nginx php8.3-fpm mariadb"`) |
| Several nodes | each node runs the script with its own `CK_SOURCE` into the `var/` that Magento's cron reads (a shared `var/`); one file per node, at most 20 |
| Managed hosting without access to the server | not possible – the checks inside Magento and the heartbeat still work |

**Containers.** Each container is a line of its own with its measurements: down, restarting or unhealthy fails (a
*Problem* at once, *Resolved* when it is back); memory over 85 % of its limit (`CK_CONTAINER_MEM_WARN`) or CPU over 90 %
(`CK_CONTAINER_CPU_WARN`, one core = 100 %) warns (hourly status and summary). *Send status now* and the daily summary
show them as a table per server, at most 20 rows:

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

## Extending

Stable extension points (`@api`); the names are in the `CommerceKings\AppHealth` namespace. In every pool the di.xml
item name is the code (`[a-z0-9_]`): a check's for its state, its settings (`commercekings_app_health/<code>/…`) and
its messages, a log source's for its cursor, a validator's for `validator="<code>"` in rules.

| For | Use |
|---|---|
| A check | `Api\CheckInterface` (`getLabel()`, `run($websiteIds)`) in `Model\CheckPool` "checks": results by scope – `[CheckInterface::SHOP => $result]` for the whole shop (to the default recipient), one per website id asked about for something each website has (to its recipient) |
| What a check returns | `Api\Data\CheckResult` – `ok()`, `warn()` or `fail()`, a title, a one-line detail of names and numbers, counts for the daily summary, gauges, notices (one-off news) – and its causes, `Api\Data\Cause` (code, detail, hint) via `withCauses()` |
| What to do | the hint of each `Api\Data\Cause`; `CheckResult::withHint($hint)` adds a check's one general hint where nothing says what to do (a problem without hinted causes, news without a "Check: …" line) |
| A check's on/off switch | `commercekings_app_health/<code>/enabled` (on when not set; a field in your `system.xml` shows it) |
| A log source | `Api\LogSourceInterface` (new data since a cursor, as `Api\Data\LogChunk`) and `Api\LogParserInterface` (data to `Api\Data\LogEntry` items) in `Model\Log\LogSourcePool` "sources", for the `system_log` or `exceptions` check; app health makes every entry safe itself (fingerprint, label, masking, file:line) |
| Sensitive-data rules, no PHP | `etc/app_health_rules.xml` in any module (schema `etc/app_health_rules.xsd`), merged by rule set and rule code: add a set or a rule, override a shipped one, or turn it off (`enabled="false"`) |
| A validator for rules | `Api\SensitiveDataValidatorInterface` (`getPattern()`, `isValid($match)`) in `Model\Sensitive\ValidatorPool` "validators"; rules use it as `validator="<code>"` (`type="validator"` for its pattern) |
| Rules in the admin | *Sensitive data in logs*: *Rule settings* (a set or rule on or off, its severity) and *Custom rules* (regex rules, checked on save) |
| Where messages go | *Alerts > Channel* (`slack` by default) and *Recipient*, *Website channels*: any Notification channel, so any provider module (Notification's `Api\ProviderInterface`) works; sent through Notification's queue as the types `app_health_alert`, `app_health_summary` and `app_health_status` |
| Status from outside Magento | any tool that writes `var/app-health/status-<source>.json` in the format above (version 1) – no PHP |
| When the hourly status is posted | `Api\StatusPolicyInterface` (`getIntervalMinutes($scopeId)`, kept within 15 to 180; `shouldPost(Api\Data\StatusReport)`), shipped as `Model\Status\DefaultPolicy`; replace it with a `<preference>`. The status is still delivered every interval (quietly when not posted); urgent alerts never ask the policy |

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

```php
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Format;

class SyncCheck implements \CommerceKings\AppHealth\Api\CheckInterface
{
    public function getLabel(): string
    {
        return 'ERP sync';
    }

    public function run(array $websiteIds): array
    {
        $result = $this->lastSyncAge() > 3600
            ? CheckResult::fail('no ERP sync for ' . Format::duration($this->lastSyncAge()), [], 'ERP sync stopped')
            : CheckResult::ok();
        return [self::SHOP => $result->withHint((string)__('Is the ERP connector\'s cron job running?'))];
    }
}
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

A status policy that posts only during opening hours – `etc/di.xml`
`<preference for="CommerceKings\AppHealth\Api\StatusPolicyInterface" type="Vendor\Shop\Model\OpeningHours"/>`:

```php
class OpeningHours implements \CommerceKings\AppHealth\Api\StatusPolicyInterface
{
    public function __construct(private readonly \CommerceKings\AppHealth\Model\Status\DefaultPolicy $default)
    {
    }

    public function getIntervalMinutes(int $scopeId): int
    {
        return $this->default->getIntervalMinutes($scopeId);
    }

    public function shouldPost(\CommerceKings\AppHealth\Api\Data\StatusReport $report): bool
    {
        $hour = (int)$report->now->format('G'); // the scope's time zone
        return $hour >= 8 && $hour < 20 && $this->default->shouldPost($report);
    }
}
```

How to use them – a run, the state, a log source and its parser, the rules: [how it works](docs/how-it-works.md).

## Sensitive data in logs

A log that holds card data, secrets or personal data is a security problem of its own (for card data, a PCI DSS
breach). Every new log entry – every level, every log source – is scanned before anything is masked, and a finding is
a high alert at once, with the rule, how often, the last time and where – never the value:

```
*Security: sensitive data in logs · Main Website Store*

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
  key word before it, or SQL. The latest per error is kept in the module's state (at most 500 errors).
- **The label:** at most 3 words, with URLs, emails, quoted values, hex strings, numbers and paths after their first
  segment – and any capitalized word after the first (often a name) – as `***`. The daily summary shows labels only.
- **Off** (*Add the masked log message* = No): labels only, everywhere; a cron job's error text is not read either.

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

## More

[How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These checks watch what Magento itself can see. They don't replace server monitoring, backups or an uptime service –
add those for production.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
