<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Adapter;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\CategoryFactory;
use MageDrop\Magento2\Model\Entity\FrontendUrlResolver;
use Magento\Eav\Model\Entity\Attribute\Backend\JsonEncoded;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Catalog category adapter. Loading and saving mirror
 * Magento\Catalog\Controller\Adminhtml\Category\Save so a MageDrop apply is
 * indistinguishable from an admin save at the same store view.
 */
class Category extends AbstractAdapter
{
    /** Inputs the admin form posts as "true"/"false" strings (Category\Save::$stringToBoolInputs). */
    private const BOOL_INPUTS = ['is_active', 'include_in_menu', 'is_anchor', 'custom_use_parent_settings', 'custom_apply_to_products'];

    /** Attributes with a "Use Config Settings" checkbox (Category\Save::$stringToBoolInputs['use_config']). */
    private const USE_CONFIG_ATTRIBUTES = ['available_sort_by', 'default_sort_by', 'filter_price_range'];

    public function __construct(
        private CategoryFactory $categoryFactory,
        private StoreManagerInterface $storeManager,
        private ScopeOverriddenValue $scopeOverriddenValue,
        private FrontendUrlResolver $urlResolver,
        array $sections = []
    ) {
        parent::__construct(
            'catalog_category',
            'Category',
            'Categories',
            'id',
            'catalog/category/edit',
            $sections,
            true,
            'entity_id'
        );
    }

    public function resolveEntityId(RequestInterface $request): ?string
    {
        $id = (int) $request->getParam('id', 0);
        $id = $id ?: (int) $request->getParam('entity_id', 0);

        return $id > 0 ? (string) $id : null;
    }

    public function load(string $entityId, int $storeId): DataObject
    {
        $category = $this->categoryFactory->create();
        $category->setStoreId($storeId);
        $category->load((int) $entityId);

        if (!$category->getId()) {
            throw new NoSuchEntityException(__('Category with id "%1" does not exist.', $entityId));
        }

        return $category;
    }

    public function getTitle(DataObject $entity): ?string
    {
        $name = $entity->getData('name');

        return is_scalar($name) && $name !== '' ? (string) $name : null;
    }


    public function getFrontendUrl(DataObject $entity, int $storeId): ?string
    {
        return $this->urlResolver->forEntity('category', (int) $entity->getId(), $storeId);
    }

    public function preparePost(array $post, DataObject $entity, int $storeId): array
    {
        foreach (self::BOOL_INPUTS as $key) {
            if (isset($post[$key]) && is_string($post[$key])) {
                if ($post[$key] === 'true') {
                    $post[$key] = true;
                } elseif ($post[$key] === 'false') {
                    $post[$key] = false;
                }
            }
        }
        if (isset($post['use_default']) && is_array($post['use_default'])) {
            foreach ($post['use_default'] as $key => $flag) {
                $post['use_default'][$key] = $flag === 'true' ? true : ($flag === 'false' ? false : $flag);
            }
        }

        return $post;
    }

    public function save(DataObject $entity, int $storeId, array $appliedFields = []): void
    {
        if (!$entity instanceof CategoryModel) {
            throw new LocalizedException(__('Expected a category model.'));
        }

        $previousStore = $this->storeManager->getStore()->getCode();
        $scopeStore = $this->storeManager->getStore($storeId ?: Store::DEFAULT_STORE_ID);
        $this->storeManager->setCurrentStore($scopeStore->getCode());

        try {
            $entity->setStoreId($storeId);

            $unpinUrlKey = false;
            if (in_array('url_key', $appliedFields, true)) {
                // Same default as the admin form: keep a 301 from the old URL
                $entity->setData('save_rewrites_history', true);
            }

            if ($storeId !== Store::DEFAULT_STORE_ID) {
                $this->keepInheritedAttributesInherited($entity, $storeId, $appliedFields);
                $unpinUrlKey = !in_array('url_key', $appliedFields, true)
                    && !$this->scopeOverriddenValue->containsValue(CategoryInterface::class, $entity, 'url_key', $storeId);
            }

            if ($storeId === Store::DEFAULT_STORE_ID) {
                $this->validateAll($entity);
            } else {
                // Inherited attributes are deliberately null here; only what we changed can be judged.
                $this->validateApplied($entity, $appliedFields);
            }

            $entity->unsetData('use_post_data_config');
            $entity->save();

            if ($unpinUrlKey) {
                $this->unpinInheritedUrlKey($entity, $storeId);
            }
        } finally {
            $this->storeManager->setCurrentStore($previousStore);
        }
    }

    /**
     * Full model validation as Category\Save does it: attributes left on "Use
     * Config Settings" are null on the model and only pass when listed in
     * use_post_data_config.
     */
    private function validateAll(CategoryModel $entity): void
    {
        $useConfig = [];
        foreach (self::USE_CONFIG_ATTRIBUTES as $code) {
            $value = $entity->getData($code);
            if ($value === null || $value === '' || $value === [] || $value === false) {
                $useConfig[] = $code;
            }
        }
        $entity->setData('use_post_data_config', $useConfig);

        $validation = $entity->validate();
        if ($validation !== true && is_array($validation)) {
            foreach ($validation as $code => $error) {
                if ($error === true) {
                    $label = $entity->getResource()->getAttribute($code)->getFrontend()->getLabel();
                    throw new LocalizedException(
                        __('The "%1" attribute is required. Enter and try again.', $label)
                    );
                }
                throw new LocalizedException(__('Category validation failed for "%1".', $code));
            }
        }
    }

    /**
     * Run each applied attribute's backend validation only.
     */
    private function validateApplied(CategoryModel $entity, array $appliedFields): void
    {
        foreach ($appliedFields as $code) {
            $attribute = $entity->getResource()->getAttribute($code);
            if (!$attribute || $entity->getData($code) === null) {
                continue; // inherit: nothing to validate
            }
            $result = $attribute->getBackend()->validate($entity);
            if ($result === false) {
                throw new LocalizedException(
                    __('The "%1" attribute is required. Enter and try again.', $attribute->getFrontend()->getLabel())
                );
            }
        }
    }

    /**
     * CategoryUrlPathAutogeneratorObserver copies the default url_key onto the
     * store scope when a category with children is saved there with "Use
     * Default" — the admin does the same, but for a tool that saves at store
     * scope on every deploy that would silently pin URL keys per store view.
     * Remove the copy again when it is identical to the default value.
     */
    private function unpinInheritedUrlKey(CategoryModel $entity, int $storeId): void
    {
        $resource = $entity->getResource();
        $connection = $resource->getConnection();
        $linkField = $resource->getLinkField();
        $linkValue = $entity->getData($linkField);

        foreach (['url_key', 'url_path'] as $code) {
            $attribute = $resource->getAttribute($code);
            if (!$attribute || !$linkValue) {
                continue;
            }
            $table = $attribute->getBackend()->getTable();
            $where = [
                $linkField . ' = ?' => $linkValue,
                'attribute_id = ?' => (int) $attribute->getId(),
            ];

            $default = $connection->fetchOne(
                $connection->select()->from($table, 'value')->where($linkField . ' = ?', $linkValue)
                    ->where('attribute_id = ?', (int) $attribute->getId())->where('store_id = ?', Store::DEFAULT_STORE_ID)
            );
            $storeValue = $connection->fetchOne(
                $connection->select()->from($table, 'value')->where($linkField . ' = ?', $linkValue)
                    ->where('attribute_id = ?', (int) $attribute->getId())->where('store_id = ?', $storeId)
            );

            if ($storeValue !== false && $storeValue === $default) {
                $connection->delete($table, $where + ['store_id = ?' => $storeId]);
            }
        }
    }

    /**
     * A category loaded at store scope carries the effective value of every
     * attribute; saving it as-is would insert a store-level row for each one
     * (Eav AbstractEntity::_collectSaveData treats "unchanged at store scope"
     * as an insert). The admin form avoids that by posting use_default for
     * every attribute without an override — do the same for everything the
     * apply did not touch, so only the applied fields become store overrides.
     */
    private function keepInheritedAttributesInherited(CategoryModel $entity, int $storeId, array $appliedFields): void
    {
        $useDefault = [];

        foreach ($entity->getAttributes() as $code => $attribute) {
            if (in_array($code, $appliedFields, true)) {
                continue;
            }
            if (!method_exists($attribute, 'isScopeGlobal') || $attribute->isScopeGlobal()) {
                continue;
            }
            if ($this->scopeOverriddenValue->containsValue(CategoryInterface::class, $entity, $code, $storeId)) {
                continue;
            }
            // Backends that materialise a value from null (JsonEncoded → "null") must not see the key at all
            if ($attribute->getBackend() instanceof JsonEncoded) {
                $entity->unsetData($code);
                continue;
            }
            $entity->setData($code, null);
            $useDefault[$code] = true;
        }

        // Explicit "inherit" values are use_default too (matters for url_key, whose
        // autogeneration observer reads this flag)
        foreach ($appliedFields as $code) {
            if ($entity->getData($code) === null) {
                $useDefault[$code] = true;
            }
        }

        $entity->setData('use_default', $useDefault);
    }
}
