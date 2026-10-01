<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Open;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Store\Model\Store;

/**
 * Stable deep link for the dashboard's "Open in Magento":
 *   /<admin>/magedrop/open/index/type/<entity type>/id/<id>[/store/<store id>]
 *
 * Admin URLs carry a per-session secret key the SaaS cannot know, so this action
 * is public (no key; login and ACL still apply) and redirects to the entity's
 * edit page with a valid key. Targets come only from the registered adapters,
 * so custom entity types work as soon as their adapter is in the pool.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** @var string[] */
    protected $_publicActions = ['index'];

    public function __construct(
        Context $context,
        private AdapterPool $adapterPool
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $type = (string) $this->getRequest()->getParam('type', '');
        $id = (string) $this->getRequest()->getParam('id', '');
        $storeId = (int) $this->getRequest()->getParam('store', Store::DEFAULT_STORE_ID);

        if ($type === '' || $id === '' || !$this->adapterPool->has($type)) {
            $this->messageManager->addErrorMessage(__('MageDrop could not find what to open.'));

            return $this->resultRedirectFactory->create()->setPath('admin/dashboard');
        }

        $adapter = $this->adapterPool->get($type);

        return $this->resultRedirectFactory->create()->setPath(
            $adapter->getEditRoute(),
            $adapter->getEditParams($id, $storeId)
        );
    }
}
