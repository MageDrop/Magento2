<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Release;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Releases extends Action
{
    public function __construct(
        Context $context,
        private ApiClient $apiClient,
        private JsonFactory $jsonFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * AJAX endpoint to fetch releases for the stage modal dropdown.
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        $releases = $this->apiClient->getReleases();

        return $result->setData(['releases' => $releases]);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
