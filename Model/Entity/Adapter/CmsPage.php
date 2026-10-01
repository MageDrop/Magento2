<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Adapter;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use MageDrop\Magento2\Model\Entity\FrontendUrlResolver;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\DataObject;

class CmsPage extends AbstractAdapter
{
    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private FrontendUrlResolver $urlResolver,
        array $sections = []
    ) {
        parent::__construct('cms_page', 'CMS Page', 'CMS Pages', 'page_id', 'cms/page/edit', $sections, false);
    }

    public function load(string $entityId, int $storeId): DataObject
    {
        /** @var \Magento\Cms\Model\Page $page */
        $page = $this->pageRepository->getById((int) $entityId);

        return $page;
    }

    public function getTitle(DataObject $entity): ?string
    {
        $title = $entity->getData('title');

        return is_scalar($title) && $title !== '' ? (string) $title : null;
    }

    public function getFrontendUrl(DataObject $entity, int $storeId): ?string
    {
        $identifier = (string) $entity->getData('identifier');

        return $identifier !== '' ? $this->urlResolver->forCmsPage($identifier, $storeId) : null;
    }

    public function save(DataObject $entity, int $storeId, array $appliedFields = []): void
    {
        /** @var \Magento\Cms\Api\Data\PageInterface $entity */
        $this->pageRepository->save($entity);
    }
}
