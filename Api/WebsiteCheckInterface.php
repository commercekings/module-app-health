<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\CheckResult;

/**
 * A health check of something each website has on its own (e.g. its email settings), registered like a
 * CheckInterface (Model\CheckPool "checks") and held to the same rules; its alerts go to each website's recipient.
 * One call covers all websites with alerts on, so a query can group by store instead of running per website.
 *
 * @api
 */
interface WebsiteCheckInterface
{
    /** Unique among all checks, [a-z0-9_], at most 32 characters, e.g. "email_disabled". */
    public function getCode(): string;

    /** One short, translated line telling the team where to look, e.g. "Is the cron container running?" */
    public function getHint(): string;

    /** Shown in the messages, e.g. "Email sending". */
    public function getLabel(): string;

    /**
     * @param int[] $websiteIds
     * @return array<int, CheckResult> by website id, one for each of $websiteIds
     */
    public function runForWebsites(array $websiteIds): array;
}
