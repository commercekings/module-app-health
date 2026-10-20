# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5.
The Composer PHP range is a dependency constraint, not a claim that every
Magento/PHP combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4`. Magento 2.4.0–2.4.3
run only on PHP 7.3/7.4; this package needs PHP 8.1.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Passed: Composer, DI compilation, database status, unit tests, PHPStan level 5, coding standard, end-to-end suite against a simulated Slack API (the log and sensitive-data checks: pending), a live Slack send |
| 2.4.4–2.4.8 | 8.1–8.4 | Unverified; use a matching Magento dependency set and services |

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5, Git archive contents and runs the unit tests.

## Tested (as of 0.1.0)

- **Automated tests:** over 300 unit tests (on every push; among them the server-status script against a scripted
  Docker, checked by the status file's validator); integration tests that run the generated SQL on MySQL/MariaDB
  (`Test/Integration`, on copies of the tables); PHPStan level 5 and the Magento2 coding standard.
- **End to end on Magento 2.4.9:** cron, indexers, caches, maintenance, email, exceptions, queues and sales emails
  triggered for real against a simulated Slack API – one alert, then one *Resolved*, the daily summary, the test
  button, the heartbeat, Slack's error modes, no private data in any message – and live to a real Slack channel.
- **Not tested end to end yet:** the system.log and sensitive-data checks (unit-tested). Server status: unit-tested
  (Magento's own file classes on a real folder); the example script, run on Debian, writes a file the check takes.
- **Cost:** a run with every check on takes about 47 ms and 11 queries; with a new 1 MB backlog in both system.log and
  exception.log, about 5 MB of memory (sensitive-data rules: about 13 ms per MB of log).
