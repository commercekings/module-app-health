<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\StatusReport;

/**
 * When the hourly status is posted. The status itself is always queued and delivered each interval - that delivery is
 * the heartbeat's proof that the queue and its consumer work - but posted to the channel only when shouldPost() says
 * so; otherwise it is delivered quietly (recorded as sent, the channel not called). Urgent alerts (a problem,
 * sensitive data, a critical log entry) never come here. Replace it with a di.xml preference.
 *
 * @api
 */
interface StatusPolicyInterface
{
    /** Minutes between two statuses of the scope (0 = the default scope); App health keeps it within 15 to 180. */
    public function getIntervalMinutes(int $scopeId): int;

    /** Whether this status is posted to the channel (else it is delivered quietly). */
    public function shouldPost(StatusReport $report): bool;
}
