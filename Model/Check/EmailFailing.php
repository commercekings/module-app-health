<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\DB\Select;
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
 *
 * No news is no recovery: after a transport error it stays failing until a mail is seen going out (a sales email
 * marked sent, a CommerceKings notification sent) or "quiet_minutes" (60) pass without a new error.
 */
class EmailFailing implements CheckInterface
{
    public const CODE = 'email_failing';
    /** While sending is broken: since, the last error, its reason and cause. */
    private const CURSOR = 'cursor:email_failing';
    /** The latest mail transport error, for the daily summary ("last 09:40"). */
    private const LAST = 'cursor:email_failing_last';
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
        private readonly DateTime $dateTime,
        private readonly RunState $state
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('The mail server or the email provider\'s settings; the class in var/log/exception.log.');
    }

    public function getLabel(): string
    {
        return 'Mail delivery errors';
    }

    public function run(array $websiteIds): array
    {
        return [self::SHOP => $this->check()->withHint($this->hint())];
    }

    private function check(): CheckResult
    {
        $mailEntries = array_values(array_filter(array_merge($this->log->newEntries()['safe'],
            $this->reader->entries(SystemLog::CODE)['entries']), fn ($entry) => !empty($entry['mail'])));
        $mail = count($mailEntries);
        $failing = $this->failingNotifications();
        $parts = [];
        $causes = [];
        $now = $this->dateTime->gmtTimestamp();
        $broken = (array)($this->state->get(self::CURSOR) ?? []);
        if ($mail > 0) {
            usort($mailEntries, fn ($a, $b) => (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));
            $latest = $mailEntries[0];
            $this->state->set(self::LAST, ['at' => (int)($latest['time'] ?? $now)]);
            $reason = (array)($latest['mail_reason'] ?? []) + ['code' => 'other', 'reason' => 'mail sending failed',
                'port' => null];
            $parts[] = sprintf('%s: %s', Format::many($mail, 'new mail transport errors'), $reason['reason']);
            $cause = new Cause('mail_' . $reason['code'], ucfirst((string)$reason['reason'])
                . ($reason['port'] ? ' (port ' . (int)$reason['port'] . ')' : '') . ': '
                . ((string)($latest['message'] ?? '') !== '' ? $latest['message'] : $latest['label'])
                . ($latest['location'] ? ' (' . $latest['location'] . ')' : ''),
                (string)__(self::HINTS[$reason['code']] ?? self::HINTS['other']));
            $causes[] = $cause;
            // broken until a send succeeds or it stays quiet long enough: a quiet minute is no recovery
            $this->state->set(self::CURSOR, ['since' => (int)($broken['since'] ?? $now), 'last' => $now,
                'reason' => (string)$reason['reason'], 'cause' => $cause->toArray()]);
        } elseif ($broken !== []) {
            $last = (int)($broken['last'] ?? 0);
            $quiet = $last <= $now - $this->config->getInt(self::CODE, 'quiet_minutes', 60) * 60;
            if ($quiet || $this->sentSince($last)) {
                $this->state->set(self::CURSOR, []);
            } else {
                $parts[] = sprintf('no successful send since the last mail transport error at %s: %s',
                    Format::at($last), $broken['reason'] ?? 'mail sending failed');
                [$code, $detail, $hint] = array_pad((array)($broken['cause'] ?? []), 3, '');
                if ($code !== '') {
                    $causes[] = new Cause((string)$code, (string)$detail, (string)$hint);
                }
            }
        }
        if (array_sum($failing) >= $this->config->getInt(self::CODE, 'failed_reminders', 3)) {
            $parts[] = sprintf('%d notifications failing (%s)', array_sum($failing), Format::top($failing));
            $causes[] = new Cause('notifications_failing', sprintf('%d CommerceKings notifications failed in the'
                . ' last hour (%s)', array_sum($failing), Format::top($failing)), (string)__('Their provider\'s'
                . ' settings and status; var/log/system.log for the failed sends.'));
        }
        $counts = ['mail errors' => $mail];
        $gauges = ['last error' => (int)($this->state->get(self::LAST)['at'] ?? 0)];
        return new CheckResult($parts === [] ? CheckResult::OK : CheckResult::FAIL, implode('; ', $parts), $counts,
            [], $parts === [] ? '' : 'Mail sending failing', $gauges, $causes);
    }

    /**
     * A mail went out since $since: a sales email marked sent (each table's updated_at index), or a CommerceKings
     * notification sent (the delivery state's updated_at index) - one bounded query, only while sending is broken.
     */
    private function sentSince(int $since): bool
    {
        $db = $this->resource->getConnection();
        $from = gmdate('Y-m-d H:i:s', $since);
        $parts = [];
        foreach (['sales_order', 'sales_invoice', 'sales_shipment', 'sales_creditmemo'] as $table) {
            $parts[] = $db->select()->from($this->resource->getTableName($table), ['sent' => new Expression('1')])
                ->where('updated_at >= ?', $from)->where('email_sent = 1')->limit(1);
        }
        $parts[] = $db->select()->from($this->deliveryState->getTableName(), ['sent' => new Expression('1')])
            ->where('updated_at >= ?', $from)->where('sent_at >= ?', $from)
            ->where('type NOT LIKE ?', 'app\_health\_%')->limit(1);
        $union = $db->select();
        $union->union(array_map(fn ($part) => $db->select()->from(['s' => $part], ['sent']), $parts),
            Select::SQL_UNION_ALL);
        return (bool)$db->fetchOne($db->select()->from(['u' => $union], ['sent'])->limit(1));
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
