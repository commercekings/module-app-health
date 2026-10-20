<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Sending failing shop-wide (fail): a mail transport exception among the new exception.log entries, or
 * "failed_reminders" or more CommerceKings notifications whose last send failed (Notification's delivery state,
 * changed in the last hour, on its updated_at index, at most CAP rows). Counts: mail errors. Unsent sales emails per
 * website: SalesEmails.
 */
class EmailFailing implements CheckInterface
{
    public const CODE = 'email_failing';
    private const CAP = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly ExceptionLog $log,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Sending';
    }

    public function run(): CheckResult
    {
        $mail = $this->log->newEntries()['mail'];
        $failing = $this->failingNotifications();
        $parts = [];
        if ($mail > 0) {
            $parts[] = sprintf('%d new mail transport %s', $mail, $mail === 1 ? 'error' : 'errors');
        }
        if (array_sum($failing) >= $this->config->getInt(self::CODE, 'failed_reminders', 3)) {
            $parts[] = sprintf('%d notifications failing (%s)', array_sum($failing), Format::top($failing));
        }
        $counts = ['mail errors' => $mail];
        return $parts === [] ? CheckResult::ok('', $counts) : CheckResult::fail(implode('; ', $parts), $counts);
    }

    /** @return array<string, int> by notification type, other modules' only */
    private function failingNotifications(): array
    {
        $db = $this->resource->getConnection();
        $failing = $db->select()->from($this->deliveryState->getTableName(), ['type'])
            ->where('updated_at >= ?', gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - 3600))
            ->where('failed_sends > 0')
            ->where('type NOT LIKE ?', 'app\_health\_%')
            ->limit(self::CAP);
        return array_map('intval', $db->fetchPairs($db->select()
            ->from(['f' => $failing], ['type', 'count' => new Expression('COUNT(*)')])->group('type')));
    }
}
