# Messages

What App health posts and why. Start with the [README](../README.md).

## How a message looks

Every header names the shop and its domain (without a *Store Information* name, the domain alone: *Hourly status ·
shop.example.com*). One message per problem, without emoji: a first line that says what and where (what a phone
notification shows; *Problem*, *Resolved*, *New error*, *New problem in Server status*, *Security*, *Cron was down*,
*Hourly status*, *Status now*, *Test alert*, the daily summary), then one fact per line and what to do.
As Slack shows it (a bold first line; other channels get plain text, or their module's renderer:
[Extending](extending.md)):

```
*Problem: Cron stopped · Acme Shop · shop.example.com*

No job has run since 10:39 (65 min).

What to do: Is the cron container (or crontab) running? bin/magento cron:run by hand shows errors.
```

A failing check told again after the repeat interval ends with "Still failing since 19 Oct 09:00.".

```
*Resolved: Cron · Acme Shop · shop.example.com*

Failed for 1 h; OK again since 11:52.
```

```
*New error in system.log · Acme Shop · shop.example.com*

Critical: PaymentException (3×, 14:02)
Where: app/code/Vendor/Pay/Model/Gateway.php:120

What to do: var/log/system.log: look for the error around that time, in the file it names.
```

With *Add the masked log message* on (off by default), a third line adds the message, masked:
`Message: Card *** declined for order ***`.

The daily summary leaves out file paths and messages, and any section without content:

```
*Acme Shop · shop.example.com · daily summary · 20 Oct*

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
• Indexers, maintenance mode, queues, mail delivery errors, email communications disabled
```

At most 5 critical, 10 errors and 3 warnings, the latest first, then "• …and N more". With nothing unsent and no mail
errors, *Email* is "• All order emails sent, no mail errors".

## Why it fails

Each alert says why, as far as Magento's own data shows it, and what to do (`What to do: …`):

| Check | Causes |
|---|---|
| Cron | nothing scheduled at all (cron not started: the container or crontab); jobs scheduled but none starting; a job stuck "running" and how many jobs of its group wait; the same job failing 3+ times in 2 h, with its latest error masked; 10+ jobs missed in an hour (cron too rare or too slow) |
| Indexers | which indexer, invalid or stuck working since when, its mode (on save or by schedule); when `indexer_reindex_all_invalid` and `indexer_update_all_views` last ran |
| Caches | which type: disabled, or invalidated since when |
| Maintenance mode | on since when; "likely left on after a deployment" after 30 min |
| Queues | which queue and its consumer; whether cron starts the consumers (`cron_consumers_runner` in env.php) and when `consumers_runner` last ran |
| Mail delivery errors | the kind of mail transport failure – refused, timed out, login refused, TLS, host not found (DNS) – with the port and the latest error (masked); the failing CommerceKings notifications |
| Email communications disabled | the scope that disables it (default, website, store view, or env.php) |
| Unsent sales emails | how many are unsent and the oldest; asynchronous sending or refused at checkout |
| Server status | each line not OK: the server, what, its value and the script's detail (masked); "No status from host-1 since 14:02" |

```
*Problem: Cron jobs stuck · Acme Shop · shop.example.com*

Job indexer_reindex_all_invalid running for 3 h (since 08:12).
12 other jobs in its group are waiting.

What to do: The process may have crashed: restart cron, then clear the stuck job (status "running" in cron_schedule).
```

A check of another module gives its own causes the same way (`Api\Data\Cause` on its `CheckResult`).
