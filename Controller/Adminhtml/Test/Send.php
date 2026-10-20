<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Controller\Adminhtml\Test;

use CommerceKings\AppHealth\Model\TestAlert;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/** POST ckapphealth/test/send: the "Send test alert" button (the global recipient); JSON {success, message}. */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceKings_AppHealth::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TestAlert $testAlert
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        try {
            $recipient = $this->testAlert->send();
            $result = ['success' => true, 'message' => (string)__('Test alert sent to %1.', $recipient)];
        } catch (LocalizedException $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->jsonFactory->create()->setData($result);
    }
}
