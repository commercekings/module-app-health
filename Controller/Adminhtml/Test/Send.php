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

/** POST ckapphealth/test/send (website): the "Send test alert" button; JSON {success, message}. */
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
        // the configuration page's scope: a website, else the default scope (0, the shop-wide checks' recipient)
        $websiteId = max(0, (int)$this->getRequest()->getParam('website'));
        try {
            $recipient = $this->testAlert->send($websiteId);
            $result = ['success' => true, 'message' => (string)__('Test alert sent to %1.', $recipient)];
        } catch (LocalizedException $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->jsonFactory->create()->setData($result);
    }
}
