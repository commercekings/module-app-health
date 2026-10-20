<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\CheckResult;

/**
 * One shop-wide health check (e.g. cron), registered in di.xml (Model\CheckPool "checks": code => implementation) and
 * run by cron every 5 minutes within a shared time budget; its alerts go to the default scope's recipient. A check of
 * something each website has on its own implements WebsiteCheckInterface instead. A check reads what Magento
 * already has, quickly: indexed, bounded queries,
 * no writes outside Model\RunState, nothing that loads the shop. Its result goes to the team as it is, so the detail
 * and counts carry names and numbers only - never a log line, an address or any other customer data.
 *
 * On or off per check in the admin: "commercekings_app_health/<code>/enabled" (on when it is not configured).
 *
 * @api
 */
interface CheckInterface
{
    /** Unique, [a-z0-9_], at most 32 characters, e.g. "cron". */
    public function getCode(): string;

    /** One short, translated line telling the team where to look, e.g. "Is the cron container running?" */
    public function getHint(): string;

    /** Shown in the messages, e.g. "Cron". */
    public function getLabel(): string;

    public function run(): CheckResult;
}
