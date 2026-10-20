<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Sending failing shop-wide (fail): a mail transport failure among the new log entries (a mail exception in
 * exception.log, or Magento's "Transport error while sending email" / "Unable to send mail" in system.log - the
 * Normalizer's mail flag), or "failed_reminders" or more CommerceKings notifications whose last send failed
 * (Notification's delivery state, changed in the last hour, on its updated_at index, at most CAP rows). Counts: mail
 * errors. Causes: the latest transport error (masked), the failing notification types. Unsent sales emails per
 * website: SalesEmails.
 */
class EmailFailing implements CheckInterface
{
    public const CODE = 'email_failing';
    /** Where to look, by the kind of mail transport failure (Normalizer::mailReason()). */
    private const HINTS = [
        'refused' => 'Is the mail server running and reachable from this server (host, port, firewall)?',
        'timeout' => 'A firewall may drop the connection: check the host, the port (25, 465, 587) and outgoing rules.',
        'auth' => 'The mail login: the username and password (or app password) in the mail settings.',
        'tls' => 'TLS: the port and encryption setting (SSL on 465, STARTTLS on 587) and the server\'s certificate.',
        'dns' => 'The mail server\'s host name in the mail settings, and DNS on this server.',
        'other' => 'The mail server or the email provider\'s settings: host, port, login, and whether it accepts mail'
            . ' from this server.',
    ];
    private const CAP = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly ExceptionLog $log,
        private readonly LogReader $reader,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getHint(): string
    {
        return (string)__('The mail server or the email provider\'s settings; the class in var/log/exception.log.');
    }

    public function getLabel(): string
    {
        return 'Sending';
    }

    public function run(): CheckResult
    {
        $mailEntries = array_values(array_filter(array_merge($this->log->newEntries()['safe'],
            $this->reader->entries(SystemLog::CODE)['entries']), fn ($entry) => !empty($entry['mail'])));
        $mail = count($mailEntries);
        $failing = $this->failingNotifications();
        $parts = [];
        $causes = [];
        if ($mail > 0) {
            usort($mailEntries, fn ($a, $b) => (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));
            $latest = $mailEntries[0];
            $reason = (array)($latest['mail_reason'] ?? []) + ['code' => 'other', 'reason' => 'mail sending failed',
                'port' => null];
            $parts[] = sprintf('%d new mail transport %s: %s', $mail, $mail === 1 ? 'error' : 'errors',
                $reason['reason']);
            $causes[] = new Cause('mail_' . $reason['code'], ucfirst((string)$reason['reason'])
                . ($reason['port'] ? ' (port ' . (int)$reason['port'] . ')' : '') . ': '
                . ((string)($latest['message'] ?? '') !== '' ? $latest['message'] : $latest['label'])
                . ($latest['location'] ? ' (' . $latest['location'] . ')' : ''),
                (string)__(self::HINTS[$reason['code']] ?? self::HINTS['other']));
        }
        if (array_sum($failing) >= $this->config->getInt(self::CODE, 'failed_reminders', 3)) {
            $parts[] = sprintf('%d notifications failing (%s)', array_sum($failing), Format::top($failing));
            $causes[] = new Cause('notifications_failing', sprintf('%d CommerceKings notifications failed in the'
                . ' last hour (%s)', array_sum($failing), Format::top($failing)), (string)__('Their provider\'s'
                . ' settings and status; var/log/system.log for the failed sends.'));
        }
        $counts = ['mail errors' => $mail];
        return $parts === [] ? CheckResult::ok('', $counts)
            : CheckResult::fail(implode('; ', $parts), $counts, 'Mail sending failing')->withCauses($causes);
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
