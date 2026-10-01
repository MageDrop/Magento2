<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper as InitializationHelper;
use Magento\Catalog\Model\Product;
use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Api\Data\LinkInterfaceFactory;
use Magento\Downloadable\Api\Data\SampleInterface;
use Magento\Downloadable\Api\Data\SampleInterfaceFactory;
use Magento\Downloadable\Controller\Adminhtml\Product\Initialization\Helper\Plugin\Downloadable as DownloadableInitializationPlugin;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\DataObject;

/**
 * Downloadable links and samples, for downloadable products only:
 *   {links: [{id, title, price, link_type, link_url, link_file, sample_type, sample_url, sample_file,
 *             sort_order, number_of_downloads, is_shareable}],
 *    samples: [{id, title, sample_type, sample_url, sample_file, sort_order}]}
 *
 * Stage time reuses Magento's downloadable initialisation plugin (reads the POST,
 * moves freshly uploaded files out of tmp, fills extension attributes — no DB
 * writes). Apply rebuilds the extension attributes; the Downloadable save
 * handlers persist them.
 */
class DownloadableLinks implements SectionHandlerInterface
{
    public const FIELD = 'downloadable';

    public function __construct(
        private DownloadableInitializationPlugin $initializationPlugin,
        private InitializationHelper $initializationHelper,
        private LinkInterfaceFactory $linkFactory,
        private SampleInterfaceFactory $sampleFactory
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $form = $post['_form_product'] ?? null;
        if (!$form instanceof Product || $form->getTypeId() !== DownloadableType::TYPE_DOWNLOADABLE) {
            return [];
        }
        if (empty($post['_post'][self::FIELD])) {
            return [];
        }

        $this->initializationPlugin->afterInitialize($this->initializationHelper, $form);

        return [self::FIELD => Value::json($this->normalise($form))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product || $entity->getTypeId() !== DownloadableType::TYPE_DOWNLOADABLE) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise($entity))];
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return $field === self::FIELD;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        return true; // link/sample titles are store scoped
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        return true;
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        if (!isset($values[self::FIELD]) || !$entity instanceof Product) {
            return;
        }
        if ($entity->getTypeId() !== DownloadableType::TYPE_DOWNLOADABLE) {
            throw new \InvalidArgumentException('Downloadable links can only be applied to a downloadable product.');
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Downloadable links cannot inherit.');
        }
        $staged = (array) $values[self::FIELD]->value;

        $links = [];
        foreach ((array) ($staged['links'] ?? []) as $row) {
            /** @var LinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setId(!empty($row['id']) ? (int) $row['id'] : null)
                ->setTitle((string) ($row['title'] ?? ''))
                ->setPrice((float) ($row['price'] ?? 0))
                ->setLinkType((string) ($row['link_type'] ?? 'url'))
                ->setLinkUrl($row['link_url'] ?? null)
                ->setLinkFile($row['link_file'] ?? null)
                ->setSampleType($row['sample_type'] ?? null)
                ->setSampleUrl($row['sample_url'] ?? null)
                ->setSampleFile($row['sample_file'] ?? null)
                ->setSortOrder((int) ($row['sort_order'] ?? 0))
                ->setNumberOfDownloads((int) ($row['number_of_downloads'] ?? 0))
                ->setIsShareable((int) ($row['is_shareable'] ?? 0));
            $links[] = $link;
        }
        $samples = [];
        foreach ((array) ($staged['samples'] ?? []) as $row) {
            /** @var SampleInterface $sample */
            $sample = $this->sampleFactory->create();
            $sample->setId(!empty($row['id']) ? (int) $row['id'] : null)
                ->setTitle((string) ($row['title'] ?? ''))
                ->setSampleType((string) ($row['sample_type'] ?? 'url'))
                ->setSampleUrl($row['sample_url'] ?? null)
                ->setSampleFile($row['sample_file'] ?? null)
                ->setSortOrder((int) ($row['sort_order'] ?? 0));
            $samples[] = $sample;
        }

        $extension = $entity->getExtensionAttributes();
        $extension->setDownloadableProductLinks($links);
        $extension->setDownloadableProductSamples($samples);
        $entity->setExtensionAttributes($extension);
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    public function toFormData(array $data, array $values): array
    {
        return $data;
    }

    private function normalise(Product $product): array
    {
        $extension = $product->getExtensionAttributes();
        $links = [];
        foreach ((array) $extension?->getDownloadableProductLinks() as $link) {
            if (!$link instanceof LinkInterface) {
                continue;
            }
            $links[] = [
                'id' => $link->getId() ? (int) $link->getId() : null,
                'title' => (string) $link->getTitle(),
                'price' => round((float) $link->getPrice(), 4),
                'link_type' => (string) $link->getLinkType(),
                'link_url' => $link->getLinkUrl() ?: null,
                'link_file' => $link->getLinkFile() ?: null,
                'sample_type' => $link->getSampleType() ?: null,
                'sample_url' => $link->getSampleUrl() ?: null,
                'sample_file' => $link->getSampleFile() ?: null,
                'sort_order' => (int) $link->getSortOrder(),
                'number_of_downloads' => (int) $link->getNumberOfDownloads(),
                'is_shareable' => (int) $link->getIsShareable(),
            ];
        }
        $samples = [];
        foreach ((array) $extension?->getDownloadableProductSamples() as $sample) {
            if (!$sample instanceof SampleInterface) {
                continue;
            }
            $samples[] = [
                'id' => $sample->getId() ? (int) $sample->getId() : null,
                'title' => (string) $sample->getTitle(),
                'sample_type' => (string) $sample->getSampleType(),
                'sample_url' => $sample->getSampleUrl() ?: null,
                'sample_file' => $sample->getSampleFile() ?: null,
                'sort_order' => (int) $sample->getSortOrder(),
            ];
        }
        usort($links, fn ($a, $b) => [$a['sort_order'], $a['title']] <=> [$b['sort_order'], $b['title']]);
        usort($samples, fn ($a, $b) => [$a['sort_order'], $a['title']] <=> [$b['sort_order'], $b['title']]);

        return ['links' => $links, 'samples' => $samples];
    }
}
