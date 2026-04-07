<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Block\PreviewBar;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Http\Context as HttpContext;
use Psr\Log\LoggerInterface;

class CmsPagePlugin
{
    private array $appliedPages = [];

    public function __construct(
        private HttpContext $httpContext,
        private ApiClient $apiClient,
        private LoggerInterface $logger
    ) {
    }

    public function afterLoad(Page $subject, Page $result): Page
    {
        return $this->applyPreview($result);
    }

    private function applyPreview(Page $page): Page
    {
        $contextValue = $this->httpContext->getValue(PreviewBar::CONTEXT_PREVIEW);

        if (!$contextValue) {
            return $page;
        }

        // Value is "releaseId:changesHash" — extract the release ID
        $releaseId = (int) explode(':', (string) $contextValue, 2)[0];

        if (!$releaseId) {
            return $page;
        }

        $pageId = $page->getId();
        if (!$pageId || isset($this->appliedPages[$pageId])) {
            return $page;
        }

        $this->appliedPages[$pageId] = true;

        try {
            $changes = $this->apiClient->getPreviewChanges(
                (int) $releaseId,
                'cms_page',
                (string) $pageId
            );

            foreach ($changes as $field => $value) {
                $page->setData($field, $value);
            }

            if (!empty($changes)) {
                $this->logger->info('MageDrop preview: applied ' . count($changes) . ' changes to CMS page ' . $pageId);
            }
        } catch (\Exception $e) {
            $this->logger->error('MageDrop preview error (cms_page): ' . $e->getMessage());
        }

        return $page;
    }
}
