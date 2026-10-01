<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterface;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Controller\Adminhtml\Product\Initialization\Helper\Plugin\Bundle as BundleInitializationPlugin;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper as InitializationHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Bundle options and their selections, for bundle products only:
 *   [{option_id, title, type, required, position,
 *     selections: [{sku, qty, position, is_default, price, price_type, can_change_quantity}]}]
 *
 * Stage time reuses Magento's own bundle initialisation plugin (it only reads the
 * POST and fills extension attributes — nothing is persisted). Apply rebuilds the
 * extension attributes and Bundle\Model\Product\SaveHandler persists them.
 */
class BundleOptions implements SectionHandlerInterface
{
    public const FIELD = 'bundle_options';

    private const OPTION_KEYS = ['title', 'type', 'required', 'position'];
    private const LINK_KEYS = ['sku', 'qty', 'position', 'is_default', 'price', 'price_type', 'can_change_quantity'];

    public function __construct(
        private BundleInitializationPlugin $initializationPlugin,
        private InitializationHelper $initializationHelper,
        private OptionInterfaceFactory $optionFactory,
        private LinkInterfaceFactory $linkFactory
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $form = $post['_form_product'] ?? null;
        if (!$form instanceof Product || $form->getTypeId() !== BundleType::TYPE_CODE) {
            return [];
        }
        if (!isset($post['_post'][self::FIELD])) {
            return [];
        }

        $this->initializationPlugin->afterInitialize($this->initializationHelper, $form);

        return [self::FIELD => Value::json($this->normalise((array) $form->getExtensionAttributes()?->getBundleProductOptions()))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product || $entity->getTypeId() !== BundleType::TYPE_CODE) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise((array) $entity->getExtensionAttributes()?->getBundleProductOptions()))];
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return $field === self::FIELD;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        return false;
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
        if ($entity->getTypeId() !== BundleType::TYPE_CODE) {
            throw new \InvalidArgumentException('Bundle options can only be applied to a bundle product.');
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Bundle options cannot inherit.');
        }

        $options = [];
        foreach ($this->normalise((array) $values[self::FIELD]->value) as $row) {
            /** @var OptionInterface $option */
            $option = $this->optionFactory->create();
            $option->setOptionId($row['option_id'] ?: null)
                ->setTitle($row['title'])
                ->setType($row['type'])
                ->setRequired((bool) $row['required'])
                ->setPosition($row['position'])
                ->setSku($entity->getSku());
            $links = [];
            foreach ($row['selections'] as $selection) {
                /** @var LinkInterface $link */
                $link = $this->linkFactory->create();
                $link->setSku($selection['sku'])
                    ->setQty($selection['qty'])
                    ->setPosition($selection['position'])
                    ->setIsDefault((bool) $selection['is_default'])
                    ->setPrice($selection['price'])
                    ->setPriceType($selection['price_type'])
                    ->setCanChangeQuantity($selection['can_change_quantity']);
                $links[] = $link;
            }
            $option->setProductLinks($links);
            $options[] = $option;
        }

        $extension = $entity->getExtensionAttributes();
        $extension->setBundleProductOptions($options);
        $entity->setExtensionAttributes($extension);
        $entity->setCanSaveBundleSelections(true);
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    public function toFormData(array $data, array $values): array
    {
        return $data;
    }

    /**
     * @param OptionInterface[]|array[] $options
     */
    private function normalise(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            if ($option instanceof OptionInterface) {
                $row = [
                    'option_id' => $option->getOptionId() ? (int) $option->getOptionId() : null,
                    'title' => (string) $option->getTitle(),
                    'type' => (string) $option->getType(),
                    'required' => (int) (bool) $option->getRequired(),
                    'position' => (int) $option->getPosition(),
                ];
                $links = (array) $option->getProductLinks();
            } elseif (is_array($option)) {
                $row = ['option_id' => !empty($option['option_id']) ? (int) $option['option_id'] : null];
                foreach (self::OPTION_KEYS as $key) {
                    $row[$key] = $key === 'title' || $key === 'type' ? (string) ($option[$key] ?? '') : (int) ($option[$key] ?? 0);
                }
                $links = (array) ($option['selections'] ?? $option['product_links'] ?? []);
            } else {
                continue;
            }
            $selections = [];
            foreach ($links as $link) {
                if ($link instanceof LinkInterface) {
                    $selections[] = [
                        'sku' => (string) $link->getSku(),
                        'qty' => round((float) $link->getQty(), 4),
                        'position' => (int) $link->getPosition(),
                        'is_default' => (int) (bool) $link->getIsDefault(),
                        'price' => $link->getPrice() !== null ? round((float) $link->getPrice(), 4) : null,
                        'price_type' => $link->getPriceType() !== null ? (int) $link->getPriceType() : null,
                        'can_change_quantity' => (int) $link->getCanChangeQuantity(),
                    ];
                } elseif (is_array($link)) {
                    $selections[] = [
                        'sku' => (string) ($link['sku'] ?? ''),
                        'qty' => round((float) ($link['qty'] ?? 0), 4),
                        'position' => (int) ($link['position'] ?? 0),
                        'is_default' => (int) !empty($link['is_default']),
                        'price' => isset($link['price']) && $link['price'] !== null && $link['price'] !== '' ? round((float) $link['price'], 4) : null,
                        'price_type' => isset($link['price_type']) && $link['price_type'] !== null && $link['price_type'] !== '' ? (int) $link['price_type'] : null,
                        'can_change_quantity' => (int) ($link['can_change_quantity'] ?? 0),
                    ];
                }
            }
            usort($selections, fn ($a, $b) => [$a['position'], $a['sku']] <=> [$b['position'], $b['sku']]);
            $row['selections'] = $selections;
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => [$a['position'], $a['title']] <=> [$b['position'], $b['title']]);

        return $out;
    }
}
