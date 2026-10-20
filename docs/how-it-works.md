# How it works

Health checks and team alerts in `commercekings/module-app-health`. Start with the [README](../README.md).

## Parts

```
 CRON (every 5 min)     Runner: the checks within a 5 s budget, the "checks" row, alerts queued on news, heartbeat
 CRON (every 15 min)    QueueSummaries: each website's daily summary once its time has passed
 QUEUE (Notification)   Alert, Summary: compose on fresh state, send once, record what the team was told
 ADMIN                  App health settings, "Send test alert"
 SERVER (every minute)  host-status.sh (not Magento): var/app-health/status-<server>.json, read by the 5-min run
```

Nothing runs on the storefront: no plugins, observers, layout or frontend routes.

## A run

1. Nothing happens while alerts are off everywhere and no heartbeat is set (a few configuration reads).
2. `Model\RunState` reads the module's table once (`ck_app_health_state`, a few dozen rows).
3. The enabled checks run in `Model\CheckPool`'s order. Shop-wide checks (`Api\CheckInterface`) run once; checks per
   website (`Api\WebsiteCheckInterface`) run once for all websites with alerts on. A check that would start after
   `Runner::BUDGET_SECONDS` (5 s) is skipped and keeps its last result; one that throws becomes a warning ("could not
   run") and only its exception class is logged.
4. Each result goes to the `checks` row by scope (0 = shop-wide, else the website id) and code, with the time its
   status last changed and its run time in ms; its worst status and counts to the hour's `tally:<YmdH>` row. One write.
5. For each scope with alerts on, `Model\AlertPlan` compares the results with what that team was last told
   (`alerted:<scope>`): a status other than the one told is news (a problem, or "recovered"), and so is a fail told
   longer ago than *Repeat a failing check after*. A check never told counts as told ok. News queues one
   `app_health_alert` for the scope.
6. A complete run (every check ran, nothing failed to queue) pings the heartbeat URL: one GET, 2 s to connect, 5 s in
   all, never retried; a failure is logged without the URL.

Typical cost: two queries for the state, one to three per check that reads the database, no cache writes.

## Sending

`Model\Notification\TeamMessage` queues and sends a team message per scope through Notification, with its delivery state
(`ck_notification_delivery_state`, type `app_health_alert`, `app_health_summary` or `app_health_status`, reference =
the scope):

- **Queue:** one UPDATE marks the row queued only while it is free (not queued, or queued for over 60 minutes; no retry
  waiting) – and for the summary only while it was not sent since today's summary time – then publishes
  `<type>|<scope>` to `commercekings.notification.send`. A failed publish clears the mark.
- **Send (consumer):** the type composes the text on the state as it is now (nothing new: the mark is cleared,
  nothing is sent), claims the delivery version, and sends `Delivery::text(..., useCooldown: false, stoppable: false)`
  from the scope's default store view – no cooldown, stop list or quiet hours. Sent: `sent_at` and what the alert told,
  in one transaction.
- **Failures:** a provider's temporary refusal waits its Retry-After (1 minute to 1 hour) without counting; any other
  failure waits 5, 15, 30, then 60 minutes and keeps retrying (a team alert is never given up) – the next run queues it
  again after the wait. A recipient the channel rejects is logged as a setting to fix. A message that may have gone out
  (`MessageSentException`) is never sent again.

**Pace:** a check going to FAIL is sent at once, and its "resolved" at its first OK run – for the checks that count
in a time window (`AlertPlan::WINDOW_CHECKS`: exceptions, system_log, queues, email_failing) only after *Recovered
after* OK runs in a row (the `runs` in the checks row). A WARN is not sent at once: the hourly status and the daily
summary list it (fail to warn waits for the OK). A check's notices – new CRITICAL log entries, exceptions,
sensitive data – at most once per *New log errors at most every* (30 min) per scope, what came in between folded into the next (at most 10 shown). ERROR
log entries are not notices: `Model\Log\Fingerprints` adds each run's count per error to `errors:recent`, and the
hourly status lists them. WARNING entries only count, for the daily summary.

**The hourly status** (`Model\Notification\Status`, reference = its scope: 0, the default scope's recipient, or with
shop-wide alerts off the first website with alerts on): every run reads its delivery state (one query); once its
interval (`Api\StatusPolicyInterface::getIntervalMinutes()`, kept within 15 to 180 minutes) has passed since the last
delivery, every run queues it until it is delivered. Its text: "All checks OK" or the checks failing in every scope,
the warnings, then "New errors this hour" since the last status. The consumer builds an `Api\Data\StatusReport` and
asks the policy (`Model\Status\DefaultPolicy`: posted with problems, warnings or errors, and "All checks OK" once a
day from the summary time): posted, or delivered quietly – claimed and recorded as sent (`sent_at`) without calling the
provider. With the hourly status on, the heartbeat is pinged unless the status has been due for
`Runner::STATUS_GRACE_MINUTES` and is still not delivered (the `status` row keeps since when it is due). A quiet
delivery proves the queue and its consumer, not the channel: the posted statuses and the alerts do.

The daily summary covers the last 24 hourly tallies of the shop-wide checks and the website's own: checks not ok now,
counts, checks that had problems and checks ok all day. It is sent from its time until 12 hours later, once a day.

## The checks

| Code | Reads | Bounded by |
|---|---|---|
| `cron` | `cron_schedule`: last activity of other jobs, running jobs, jobs ended since the last run | the configured job codes, a status and a `scheduled_at` window: the `(job_code, status, scheduled_at)` index |
| `indexers` | `indexer_state` | one row per indexer |
| `caches` | cache type states (env.php) and the invalidated list (one cache read) | – |
| `maintenance` | `var/.maintenance.flag` | a file check |
| `system_log` | new bytes of `var/log/system.log` (and the extra files, and other modules' sources) | *Read at most* (1 MB) per source; only WARNING and above are parsed |
| `exceptions` | new bytes of `var/log/exception.log` after the stored offset | *Read at most* (1 MB); of a larger backlog the newest 1 MB (from its first whole line), the count marked "at least" and the size not read in the detail; rotation starts the new file from its beginning |
| `queues` | MySQL queue: new and retry messages per queue; RabbitMQ: a passive declare per queue | one statement with a part per queue, each at most twice the limit (a big queue hides no other; on MariaDB 11.8 with 200,000 completed rows each part is a range on `(status, updated_at)`, ~50 rows); no message is read or consumed |
| `email_failing` | mail transport classes among the new exception entries; failing CommerceKings notifications | the delivery state's `updated_at` index, the last hour, at most 100 rows |
| `email_disabled` | `system/smtp/disable` per store view | configuration only |
| `external` | `var/app-health/status-*.json` (written by a script on the server); creates the folder and `status.json.dist` when missing | at most 20 files of 32 KB, each validated only when its hash changed; no query |
| `sales_emails` | orders (not cancelled or closed), invoices, shipments, credit memos with `send_email = 1` and no `email_sent` | each table's `created_at` index: the last 24 hours, older than *Unsent after*, at most 100 rows each, in one query |

Times are UTC, like Magento's database session; the summary time is in the website's time zone.

## Logs

The `system_log` and `exceptions` checks read their log sources through `Model\Log\LogReader`: each source of the
check (`Model\Log\LogSourcePool`) is read once per run from its stored cursor (`cursor:log:<code>`), at most the check's
*Read at most* (1 MB), parsed by the source's parser, and every entry passed through `Model\Log\Normalizer`, which
alone decides what leaves the server: level, channel, exception class names (checked), where it happened (relative
to the Magento root, plus the line), a fingerprint and a safe label – and the masked message (on by default, *Add the masked log message*).

- **Fingerprint:** a hash of the channel, the level group (warning, error, critical), the exception class and the
  normalized message (URLs, emails, quoted values, hex, numbers and paths after their first segment as placeholders),
  so the same error with other values is one fingerprint.
- **NEW:** `Model\Log\Fingerprints` keeps the entries seen (WARNING and above) in the `fingerprints` row, at most 500,
  the least recently seen dropped first (and remembered for a day in `fingerprints:recent`). A CRITICAL entry never
  seen before is a notice; for `system_log` a CRITICAL one is told again after the repeat interval. ERROR entries go
  to the hourly status (`errors:recent`), WARNING entries to the daily summary.
- **Notices** are one-off news: kept in `notices:<scope>` (30, for 24 hours) and told once (`noticed:<scope>`), in one
  message per check – no "recovered" follows.

### Adding a log source

A module's own log (a file, a service, a stream) feeds a check with one class pair and one di.xml item:

```php
class ErpFeedSource implements \CommerceKings\AppHealth\Api\LogSourceInterface
{
    public function getCode(): string { return 'erp_feed'; }

    /** $cursor is what the last read returned ([] the first time: start at the end) */
    public function read(array $cursor, int $maxBytes): \CommerceKings\AppHealth\Api\Data\LogChunk
    {
        // new data since $cursor, at most $maxBytes, and the cursor to continue from
    }
}

class ErpFeedParser implements \CommerceKings\AppHealth\Api\LogParserInterface
{
    public function parse(string $data): array
    {
        // one \CommerceKings\AppHealth\Api\Data\LogEntry per entry at WARNING or above:
        // level, channel, message, exception classes, file, line, time
    }
}
```

```xml
<type name="CommerceKings\AppHealth\Model\Log\LogSourcePool">
    <arguments>
        <argument name="sources" xsi:type="array">
            <item name="erp_feed" xsi:type="array">
                <item name="source" xsi:type="object">Vendor\Erp\Model\ErpFeedSource</item>
                <item name="parser" xsi:type="object">Vendor\Erp\Model\ErpFeedParser</item>
                <item name="check" xsi:type="string">system_log</item>
            </item>
        </argument>
    </arguments>
</type>
```

A file in Magento's own format needs no code: a `Model\Log\FileLogSource` virtual type with its `code` and `file`
and `Model\Log\MonologLineParser` – or just the admin's *Extra log files*. The parser hands over what it read; the
Normalizer masks it, so a third-party parser can't send a line as it is.

### Sensitive data

`Model\Log\Normalizer` scans every entry's raw text (`LogEntry::$text`, every level, the first 16 KB) with
`Model\Sensitive\Detector` before anything else, so it covers every log source and parser:

- **Rules** (`Model\Sensitive\Rules`): every module's `etc/app_health_rules.xml` (`etc/app_health_rules.xsd`; merged by
  ruleset and rule code, read through Magento's config reader and cached like other XML config), then the admin's
  *Rule settings* and *Custom rules*. A rule is a PCRE pattern, or a validator's pattern (`type="validator"`),
  optionally confirmed by a validator (`Model\Sensitive\ValidatorPool`, `Api\SensitiveDataValidatorInterface`), with a
  severity and a minimum per entry ("5 or more emails"). Each pattern must pass `Model\Sensitive\RegexGuard`; the
  detector runs them under a backtracking limit; a rule that hits it finds nothing in that entry and is logged as a
  warning by its key. A custom rule is also benchmarked when it is saved: on `RegexGuard::sample()` (about 80 KB of
  real-looking log text) it must stay within the limit and 10 ms.
- **Time budget:** a check's log reading and scanning stop after `LogReader::BUDGET_SECONDS` (1 s); the rest of what
  was read is not scanned, and the counts are marked "at least".
- **Out:** only "<set>/<rule>" and the number of matches. The `sensitive_data` check keeps findings per rule and
  fingerprint (the `sensitive` row, at most 200) and makes each a notice once, and again after the repeat interval
  while it goes on, under the title "Security: sensitive data in logs".

## Privacy

Messages carry the shop's name (its *Store Information* name, else the store's frontend name), check labels, statuses,
details built from codes, class names and numbers, and counts. `Api\Data\CheckResult` keeps a detail to one line of
200 characters and replaces anything that looks like an email address; count names are codes or class names only. The
exception log reader keeps the number of entries and their exception class names – nothing else of a line is stored,
logged or sent. Logs name the check, the scope and exception classes, never a message text or the recipient.

## State

`ck_app_health_state` (`state_key`, JSON `value`, `updated_at`):

| Key | Holds |
|---|---|
| `checks` | the last result per scope and check: status, detail, since, runs (in a row with this status), checked_at, ms |
| `alerted:<scope>` | what that team was last told per check: status and time |
| `cursor:<name>` | what a check remembers: cron's last run, first-seen times, samples; `cursor:log:<source>` a log source's cursor |
| `fingerprints` | the log errors seen: label, level, channel, where, first and last seen, count, last told, the run that last saw it (at most 500) |
| `fingerprints:recent` | errors pushed out of `fingerprints`: first seen and when dropped, for a day (at most 2,000) – not "NEW" again |
| `errors:recent` | ERROR entries per run and error: time, fingerprint, count, new (3 hours, at most 500) – for the hourly status |
| `status` | since when the hourly status is due and not yet delivered |
| `status:posted` | per scope, when a status was last posted, and last posted as "All checks OK" |
| `sensitive` | sensitive-data findings per rule and fingerprint: first, last, count, last told (at most 200) |
| `notices:<scope>`, `noticed:<scope>` | one-off news (e.g. NEW critical errors) waiting for the next alert, and the last told per check (id and time) |
| `external` | each server's last good status from `var/app-health/status-<source>.json` (source => generated_at, its lines – code, label, status, value, unit, masked detail – the file's hash, read_at); at most 20, written only when a file changed |
| `tally:<YmdH>` | an hour's worst status and counts per scope and check; kept 26 hours |

To start over (e.g. in a test), truncate the table and delete the delivery state rows of the types `app_health_alert`,
`app_health_summary` and `app_health_status`.
