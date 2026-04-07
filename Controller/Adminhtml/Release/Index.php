<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Release;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public function __construct(
        Action\Context $context,
        private PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MageDrop_Magento2::releases');
        $page->getConfig()->getTitle()->prepend(__('Releases'));

        return $page;
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
