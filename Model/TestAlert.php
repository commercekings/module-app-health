<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SenderInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * "Send test alert": one message to the saved global channel and recipient, sent at once (not queued), whether app
 * health is on or not - so the admin sees the result.
 */
class TestAlert
{
    public const TYPE = 'app_health_test';

    public function __construct(
        private readonly Targets $targets,
        private readonly Messages $messages,
        private readonly SenderInterface $sender
    ) {
    }

    /**
     * @return string the recipient it went to
     * @throws LocalizedException without a channel and recipient, or when the channel did not take it
     */
    public function send(): string
    {
        $target = $this->targets->global();
        if ($target === null) {
            throw new LocalizedException(__('Set a channel and a recipient, save the configuration, then try again.'));
        }
        $text = $this->messages->test($target);
        try {
            $status = $this->sender->send(Delivery::text($target->storeId, $target->channel, $target->recipient,
                fn () => new DeliveryContent([], null, null, $text), useCooldown: false, stoppable: false,
                type: self::TYPE));
        } catch (\InvalidArgumentException) {
            throw new LocalizedException(__('"%1" is not a recipient the "%2" channel takes.', $target->recipient,
                $target->channel));
        } catch (\Throwable $e) {
            throw new LocalizedException(__('Not sent (%1): %2', get_class($e), $e->getMessage()),
                $e instanceof \Exception ? $e : null);
        }
        if ($status !== SenderInterface::SENT) {
            throw new LocalizedException(__('Not sent: %1.', $status));
        }
        return $target->recipient;
    }
}
