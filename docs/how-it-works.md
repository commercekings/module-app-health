# How it works

Health checks and team alerts in `commercekings/module-app-health`. Start with the [README](../README.md).

## Parts

```
 CRON (every 5 min)     Runner: the checks within a 5 s budget, the "checks" row, alerts queued on news, heartbeat
 CRON (every 15 min)    QueueSummaries: each website's daily summary once its time has passed
 QUEUE (Notification)   Alert, Summary: compose on fresh state, send once, record what the team was told
 ADMIN                  App health settings, "Send test alert"
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
(`ck_notification_delivery_state`, type `app_health_alert` or `app_health_summary`, reference = the scope):

- **Queue:** one UPDATE marks the row queued only while it is free (not queued, or queued for over 60 minutes; no retry
  waiting) – and for the summary only while it was not sent since today's summary time – then publishes
  `<type>|<scope>` to `commercekings.notification.send`. A failed publish clears the mark.
- **Send (consumer):** the type composes the text on the state as it is now (nothing new: the mark is cleared,
  nothing is sent), claims the delivery version, and sends `Delivery::text(..., useCooldown: false, stoppable: false)`
  from the scope's default store view – no cooldown, stop list or quiet hours. Sent: `sent_at`, and the alert records
  what it told.
- **Failures:** a provider's temporary refusal waits its Retry-After (1 minute to 1 hour) without counting; any other
  failure waits 5, 15, 30, then 60 minutes and keeps retrying (a team alert is never given up) – the next run queues it
  again after the wait. A recipient the channel rejects is logged as a setting to fix. A message that may have gone out
  (`MessageSentException`) is never sent again.

The daily summary covers the last 24 hourly tallies of the shop-wide checks and the website's own: checks not ok now,
counts, checks that had problems and checks ok all day. It is sent from its time until 12 hours later, once a day.

## The checks

| Code | Reads | Bounded by |
|---|---|---|
| `cron` | `cron_schedule`: last activity of other jobs, running jobs, jobs ended since the last run | the configured job codes, a status and a `scheduled_at` window: the `(job_code, status, scheduled_at)` index |
| `indexers` | `indexer_state` | one row per indexer |
| `caches` | cache type states (env.php) and the invalidated list (one cache read) | – |
| `maintenance` | `var/.maintenance.flag` | a file check |
| `exceptions` | new bytes of `var/log/exception.log` after the stored offset | *Read at most* (1 MB); a larger backlog is skipped, the count marked "at least"; rotation starts the new file from its beginning |
| `queues` | MySQL queue: new and retry messages per queue; RabbitMQ: a passive declare per queue | the `(status, updated_at)` index, at most twice the limit; no message is read or consumed |
| `email_failing` | mail transport classes among the new exception entries; failing CommerceKings notifications | the delivery state's `updated_at` index, the last hour, at most 100 rows |
| `email_disabled` | `system/smtp/disable` per store view | configuration only |
| `sales_emails` | orders, invoices, shipments, credit memos with `send_email = 1` and no `email_sent` | each table's `created_at` index: the last 24 hours, older than *Unsent after*, at most 100 rows each, in one query |

Times are UTC, like Magento's database session; the summary time is in the website's time zone.

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
| `checks` | the last result per scope and check: status, detail, since, checked_at, ms |
| `alerted:<scope>` | what that team was last told per check: status and time |
| `cursor:<name>` | what a check remembers: cron's last run, the exception log offset and inode, first-seen times, samples |
| `tally:<YmdH>` | an hour's worst status and counts per scope and check; kept 26 hours |

To start over (e.g. in a test), truncate the table and delete the delivery state rows of the types `app_health_alert`
and `app_health_summary`.
