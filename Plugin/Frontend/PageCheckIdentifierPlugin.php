<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Model\Preview\State;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;

/**
 * Cms\Controller\Router::match calls Page::checkIdentifier which goes through
 * ResourceModel\Page::checkIdentifier — that filters is_active=1 in the SQL.
 * For a DB-disabled page that's staged active, the router returns null → 404
 * before our CmsPagePlugin's aroundLoad ever has a chance to run.
 *
 * On the no-match path: when preview is active, retry the identifier lookup
 * without the is_active filter. The router will then forward to cms/page/view,
 * which calls prepareResultPage → $page->load($pageId) → CmsPagePlugin overlays
 * the staged data → helper's isActive() check sees the overlaid value.
 */
class PageCheckIdentifierPlugin
{
    public function __construct(
        private State $state,
        private ResourceConnection $resourceConnection,
        private MetadataPool $metadataPool
    ) {
    }

    /**
     * @return int|string|false
     */
    public function afterCheckIdentifier(Page $subject, $result, $identifier, $storeId)
    {
        if ($result) {
            return $result;
        }
        if (!$this->state->isActive()) {
            return $result;
        }

        $metadata = $this->metadataPool->getMetadata(PageInterface::class);
        $linkField = $metadata->getLinkField();
        $idField = $metadata->getIdentifierField();

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['cp' => $this->resourceConnection->getTableName('cms_page')],
                ['cp.' . $idField]
            )
            ->join(
                ['cps' => $this->resourceConnection->getTableName('cms_page_store')],
                'cp.' . $linkField . ' = cps.' . $linkField,
                []
            )
            ->where('cp.identifier = ?', (string) $identifier)
            ->where('cps.store_id IN (?)', [Store::DEFAULT_STORE_ID, (int) $storeId])
            ->order('cps.store_id DESC')
            ->limit(1);

        $pageId = $connection->fetchOne($select);

        return $pageId !== false ? $pageId : $result;
    }
}
