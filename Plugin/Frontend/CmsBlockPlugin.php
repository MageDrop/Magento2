<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Block\PreviewBar;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Cms\Model\Block;
use Magento\Framework\App\Http\Context as HttpContext;
use Psr\Log\LoggerInterface;

class CmsBlockPlugin
{
    private array $appliedBlocks = [];

    public function __construct(
        private HttpContext $httpContext,
        private ApiClient $apiClient,
        private LoggerInterface $logger
    ) {
    }

    public function afterLoad(Block $subject, Block $result): Block
    {
        return $this->applyPreview($result);
    }

    private function applyPreview(Block $block): Block
    {
        $contextValue = $this->httpContext->getValue(PreviewBar::CONTEXT_PREVIEW);

        if (!$contextValue) {
            return $block;
        }

        // Value is "releaseId:changesHash" — extract the release ID
        $releaseId = (int) explode(':', (string) $contextValue, 2)[0];

        if (!$releaseId) {
            return $block;
        }

        $blockId = $block->getId();
        if (!$blockId || isset($this->appliedBlocks[$blockId])) {
            return $block;
        }

        $this->appliedBlocks[$blockId] = true;

        try {
            $changes = $this->apiClient->getPreviewChanges(
                (int) $releaseId,
                'cms_block',
                (string) $blockId
            );

            foreach ($changes as $field => $value) {
                $block->setData($field, $value);
            }

            if (!empty($changes)) {
                $this->logger->info('MageDrop preview: applied ' . count($changes) . ' changes to CMS block ' . $blockId);
            }
        } catch (\Exception $e) {
            $this->logger->error('MageDrop preview error (cms_block): ' . $e->getMessage());
        }

        return $block;
    }
}
