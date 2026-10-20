<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Controller\Adminhtml\Domain;

use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST ckapphealth/domain/accept (form key): "Use <domain>" - this shop, at its current domain, is the one App health
 * reports on (after a move, not on a copy); JSON {success, message}.
 */
class Accept extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceKings_AppHealth::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SiteDomain $siteDomain
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $current = $this->siteDomain->current();
        if ($current === '') {
            return $this->jsonFactory->create()->setData(['success' => false,
                'message' => (string)__('The shop\'s base URL has no domain. Check Stores > Configuration > General'
                    . ' > Web.')]);
        }
        $this->siteDomain->accept();
        return $this->jsonFactory->create()->setData(['success' => true,
            'message' => (string)__('App health now reports on %1. Reload the page.', $current)]);
    }
}
