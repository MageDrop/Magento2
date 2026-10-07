<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Adapter;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use MageDrop\Magento2\Model\Entity\FrontendUrlResolver;
use MageDrop\Magento2\Model\Entity\Section\Product\MediaGallery;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper as InitializationHelper;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product\Authorization;
use Magento\Eav\Model\Entity\Attribute\Backend\JsonEncoded;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Catalog product adapter.
 *
 * Extract runs the admin POST through Magento's own product initialisation
 * helper (initializeFromData — never initialize(), whose plugins persist
 * configurable children) on a fresh copy of the product, then lets the
 * section handlers read the resulting model. Save mirrors an admin save at
 * the requested store view, keeping every attribute we did not touch
 * inheriting (AttributeFilter::prepareDefaultData semantics).
 */
class Product extends AbstractAdapter
{
    /**
     * Keys the section handlers receive alongside the initialised product data:
     * the raw request POST and the initialised model itself.
     */
    public const DATA_RAW_POST = '_post';
    public const DATA_FORM_PRODUCT = '_form_product';

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private InitializationHelper $initializationHelper,
        private Authorization $authorization,
        private StoreManagerInterface $storeManager,
        private ScopeOverriddenValue $scopeOverriddenValue,
        private FrontendUrlResolver $urlResolver,
        private ProductFactory $productFactory,
        array $sections = []
    ) {
        parent::__construct(
            'catalog_product',
            'Product',
            'Products',
            'id',
            'catalog/product/edit',
            $sections,
            true,
            'entity_id'
        );
    }

    public function resolveEntityId(RequestInterface $request): ?string
    {
        $id = (int) $request->getParam('id', 0);

        return $id > 0 ? (string) $id : null;
    }

    public function load(string $entityId, int $storeId): DataObject
    {
        try {
            // forceReload so each load is a distinct instance (the repository caches by id/store)
            $product = $this->productRepository->getById((int) $entityId, true, $storeId, true);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__('Product with id "%1" does not exist.', $entityId));
        }

        return $product;
    }

    public function getTitle(DataObject $entity): ?string
    {
        $name = $entity->getData('name');

        return is_scalar($name) && $name !== '' ? (string) $name : null;
    }

    public function getFrontendUrl(DataObject $entity, int $storeId): ?string
    {
        return $this->urlResolver->forEntity('product', (int) $entity->getId(), $storeId);
    }

    public function extract(DataObject $entity, array $post, int $storeId): array
    {
        $productData = $post['product'] ?? null;
        if (!is_array($productData) || !$productData) {
            return [];
        }

        // Apply the POST to a separate copy so the live entity stays pristine for current()
        $formProduct = $this->load((string) $entity->getId(), $storeId);
        if (!$formProduct instanceof ProductModel) {
            return [];
        }

        $this->withStore($storeId, function () use ($formProduct, $productData) {
            $this->initializationHelper->initializeFromData($formProduct, $productData);
            try {
                $this->authorization->authorizeSavingOf($formProduct);
            } catch (AuthorizationException $e) {
                throw new LocalizedException(__($e->getMessage()));
            }
        });

        $data = $formProduct->getData();
        // Magento's configurable price field is disabled and cleared (price-configurable.js), so
        // the form always posts an empty price: that is what the form shows, not an edit
        if ($formProduct->getTypeId() === 'configurable' && (string) ($productData['price'] ?? '') === '') {
            unset($data['price']);
        }
        $data['use_default'] = is_array($post['use_default'] ?? null) ? $post['use_default'] : [];
        $data[self::DATA_RAW_POST] = $post;
        $data[self::DATA_FORM_PRODUCT] = $formProduct;

        $values = [];
        foreach ($this->getSections() as $section) {
            foreach ($section->extract($data, $entity, $storeId) as $field => $value) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    protected function formProbe(): DataObject
    {
        return $this->productFactory->create();
    }

    public function toFormData(array $data, array $values): array
    {
        // ProductDataProvider output is {product: {...}, use_default: {...}, ...}
        $product = is_array($data['product'] ?? null) ? $data['product'] : [];
        $product = parent::toFormData($product, $values);

        if (isset($product['use_default']) && is_array($product['use_default'])) {
            $data['use_default'] = array_merge($data['use_default'] ?? [], $product['use_default']);
            unset($product['use_default']);
        }
        if (isset($product['sources'])) {
            $data['sources'] = $product['sources'];
            unset($product['sources']);
        }
        $data['product'] = $product;

        return $data;
    }

    public function save(DataObject $entity, int $storeId, array $appliedFields = []): void
    {
        if (!$entity instanceof ProductModel) {
            throw new LocalizedException(__('Expected a product model.'));
        }

        $this->withStore($storeId, function () use ($entity, $storeId, $appliedFields) {
            $entity->setStoreId($storeId);

            if (in_array('url_key', $appliedFields, true)) {
                // Same default as the admin form: keep a 301 from the old URL
                $entity->setData('save_rewrites_history', true);
            }

            if (!in_array(MediaGallery::FIELD, $appliedFields, true)) {
                // Untouched gallery: don't let the gallery handlers rewrite rows at this scope
                $entity->unsetData(MediaGallery::FIELD);
            }

            if ($storeId !== Store::DEFAULT_STORE_ID) {
                $this->keepInheritedAttributesInherited($entity, $storeId, $appliedFields);
            }

            $this->validateApplied($entity, $appliedFields);

            $entity->save();

            $this->afterSave($entity, $storeId);
        });
    }

    private function withStore(int $storeId, callable $fn): void
    {
        $previous = $this->storeManager->getStore()->getCode();
        $this->storeManager->setCurrentStore($this->storeManager->getStore($storeId ?: Store::DEFAULT_STORE_ID)->getCode());
        try {
            $fn();
        } finally {
            $this->storeManager->setCurrentStore($previous);
        }
    }

    private function validateApplied(ProductModel $entity, array $appliedFields): void
    {
        foreach ($appliedFields as $code) {
            $attribute = $entity->getResource()->getAttribute($code);
            if (!$attribute || $entity->getData($code) === null || $entity->getData($code) === false) {
                continue;
            }
            if (is_array($entity->getData($code))) {
                continue;
            }
            if ($attribute->getBackend()->validate($entity) === false) {
                throw new LocalizedException(
                    __('The "%1" attribute is required. Enter and try again.', $attribute->getFrontend()->getLabel())
                );
            }
        }
    }

    /**
     * Mirror AttributeFilter::prepareDefaultData for every store-scoped attribute
     * we did not apply: varchar/text/datetime → false (also skips the url_key
     * autogeneration observer), everything else → null, JsonEncoded → absent.
     * Without this, saving a product loaded at store scope would insert a
     * store-level row for every attribute.
     */
    private function keepInheritedAttributesInherited(ProductModel $entity, int $storeId, array $appliedFields): void
    {
        foreach ($entity->getAttributes() as $code => $attribute) {
            if (in_array($code, $appliedFields, true) || $code === MediaGallery::FIELD) {
                continue;
            }
            if ($attribute->getFrontendInput() === 'media_image' && in_array(MediaGallery::FIELD, $appliedFields, true)) {
                continue; // roles are set by the gallery handler
            }
            if (!method_exists($attribute, 'isScopeGlobal') || $attribute->isScopeGlobal()) {
                continue;
            }
            if (!$entity->hasData($code)) {
                continue;
            }
            if ($this->scopeOverriddenValue->containsValue(ProductInterface::class, $entity, $code, $storeId)) {
                continue;
            }
            if ($attribute->getBackend() instanceof JsonEncoded) {
                $entity->unsetData($code);
                continue;
            }
            $type = (string) $attribute->getBackendType();
            $entity->setData($code, in_array($type, ['varchar', 'text', 'datetime'], true) ? false : null);
        }
    }
}
