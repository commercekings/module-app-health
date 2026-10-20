<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\CheckResult;

/**
 * One health check, registered in di.xml under its code (Model\CheckPool "checks": code => implementation, code
 * [a-z0-9_], at most 32 characters, e.g. "cron") and run by cron every 5 minutes within a shared time budget. The code
 * is its name everywhere: its state, its settings ("commercekings_app_health/<code>/enabled", on when not configured)
 * and the messages.
 *
 * A check of the whole shop (e.g. cron) returns one result for SHOP, which goes to the default scope's recipient; a
 * check of something each website has on its own (e.g. its email settings) returns one per website it is asked
 * about, each going to that website's recipient - one call for all of them, so a query can group by store.
 *
 * A check reads what Magento already has, quickly: indexed, bounded queries, no writes outside Model\RunState,
 * nothing that loads the shop. Its result goes to the team as it is, so the detail and counts carry names and numbers
 * only - never a log line, an address or any other customer data. What to do comes with the result: each problem's
 * Data\Cause carries its hint (CheckResult::withHint() for a check's one general hint).
 *
 * @api
 */
interface CheckInterface
{
    /** The scope of a result about the whole shop. */
    public const SHOP = 0;

    /** Shown in the messages, e.g. "Cron". */
    public function getLabel(): string;

    /**
     * @param int[] $websiteIds the websites with alerts on (a check of the whole shop may ignore them)
     * @return array<int, CheckResult> by scope: [SHOP => result] for the whole shop, else one per website id asked
     *     about (one left out counts as "could not run")
     */
    public function run(array $websiteIds): array;
}
