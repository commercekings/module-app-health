<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Controller\Adminhtml\Status;

use CommerceKings\AppHealth\Model\StatusNow;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * POST ckapphealth/status/send (with the form key, as every admin POST): the "Send status now" button - the checks
 * as they are now, posted to the global recipient; JSON {success, message}. Its own ACL resource.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceKings_AppHealth::send_status';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly StatusNow $statusNow
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        try {
            [$recipient, $channel] = $this->statusNow->send();
            $result = ['success' => true, 'message' => (string)__('Posted to %1 (%2).', $recipient, $channel)];
        } catch (LocalizedException $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->jsonFactory->create()->setData($result);
    }
}
