<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Model\Check\External;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Message\RendererPool;
use CommerceKings\AppHealth\Model\Notification\Status;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Send status now": the checks as they are this minute - a snapshot run (Runner::run(snapshot: true): the same
 * checks within the same budget, nothing stored, queued or pinged) - posted at once to the global recipient as a
 * "Status now" with what the hourly status says (problems, warnings, what is not set up, the log errors since the last
 * hourly status) and the servers' containers as a table. The alerts, "Resolved" and the hourly status keep their timing; only app health on and a global
 * channel and recipient are needed.
 */
class StatusNow
{
    public const TYPE = 'app_health_status_now';

    public function __construct(
        private readonly Targets $targets,
        private readonly Runner $runner,
        private readonly RunState $state,
        private readonly Status $status,
        private readonly Config $config,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly SenderInterface $sender,
        private readonly DateTime $dateTime,
        private readonly Messages $messages,
        private readonly SiteDomain $siteDomain,
        private readonly RendererPool $renderers
    ) {
    }

    /** Whether the button can work: app health on, with a global channel and recipient. */
    public function isAvailable(): bool
    {
        return $this->targets->shopWide() !== null;
    }

    /**
     * @return array{0: string, 1: string} the recipient and the channel it was posted to
     * @throws LocalizedException on a copy of the shop, when app health is off or has no global recipient, or when the
     *     channel did not take it
     */
    public function send(): array
    {
        if (!$this->siteDomain->matches()) {
            // a copy of the shop (SiteDomain): silent, as the runtime is - not even the checks run
            throw new LocalizedException(__('App health is silent: this looks like a copy of %1 (now %2). Use the'
                . ' new domain first if this is the shop.', $this->siteDomain->stored(), $this->siteDomain->current()));
        }
        $target = $this->targets->shopWide();
        if ($target === null) {
            throw new LocalizedException(__('Set a channel and recipient under Alerts first.'));
        }
        $snapshot = $this->runner->run(snapshot: true);
        // the log errors since the last hourly status went out, else over one interval
        $now = (int)$this->dateTime->gmtTimestamp();
        $sentAt = (string)($this->deliveryState->load(Status::CODE, 0)['sent_at'] ?? '');
        $from = $sentAt !== '' ? (int)strtotime($sentAt . ' UTC')
            : $now - $this->config->getHourlyStatusMinutes() * 60;
        // the snapshot's own state: what this run read, never committed
        $message = $this->status->content($target, $this->status->parts((array)($snapshot['checks'] ?? []),
            $this->state->get(Fingerprints::KEY) ?? [], $this->state->get(Fingerprints::ERRORS_KEY) ?? [], $from),
            'Status now');
        // the servers' containers, as the snapshot's own external check just read them
        $containers = $this->messages->containers($this->state->get(External::ROW) ?? [], $now,
            $this->config->getInt(External::CODE, 'stale_minutes', 15) * 60);
        if ($containers !== null) {
            $message = $message->withSections($containers);
        }
        if (!$snapshot['complete']) {
            $message = $message->withSections(new TeamMessageSection([$snapshot['skipped'] !== []
                ? 'Not checked this time (time budget): ' . implode(', ', $snapshot['skipped']) . '.'
                : 'A check could not run (see the Magento log).']));
        }
        $text = $this->renderers->render($target->channel, $message);
        try {
            $result = $this->sender->send(Delivery::text($target->storeId, $target->channel, $target->recipient,
                fn () => new DeliveryContent([], null, null, $text), useCooldown: false, stoppable: false,
                type: self::TYPE));
        } catch (\InvalidArgumentException) {
            throw new LocalizedException(__('"%1" is not a recipient the "%2" channel takes.', $target->recipient,
                $target->channel));
        } catch (\Throwable $e) {
            // the provider's own words (e.g. Slack's code and what to fix), never a PHP class name
            throw new LocalizedException(__('Not posted: %1', $e->getMessage()), $e instanceof \Exception ? $e : null);
        }
        if ($result !== SenderInterface::SENT) {
            throw new LocalizedException(__('Not posted: %1.', $result));
        }
        return [$target->recipient, $target->channel];
    }
}
