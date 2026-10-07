<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Staging;

use MageDrop\Magento2\Model\Entity\AdapterInterface;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Entity\Differ;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Preview\ReleaseValues;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns an intercepted admin save into a delta and ships it to the SaaS.
 */
class Stager
{
    public function __construct(
        private AdapterPool $adapterPool,
        private Differ $differ,
        private ApiClient $apiClient,
        private StoreManagerInterface $storeManager,
        private ReleaseValues $releaseValues
    ) {
    }

    /**
     * @return array{entity_id: string, store_id: int, change_count: int, response: array}
     * @throws LocalizedException
     */
    public function stage(string $type, RequestInterface $request, int $releaseId): array
    {
        $delta = $this->buildDelta($type, $request);
        if (!$delta['changes']) {
            return $delta + ['response' => []];
        }

        // Fields with no store dimension (global attributes, gallery set, third-party sections)
        // belong to the default scope even when edited from a store view
        $scoped = $delta;
        $global = $delta;
        $scoped['changes'] = [];
        $global['changes'] = [];
        $global['store_id'] = Store::DEFAULT_STORE_ID;
        $releaseDefaults = null;
        $releaseAtView = null;
        foreach ($delta['changes'] as $change) {
            $isScoped = $delta['store_id'] !== Store::DEFAULT_STORE_ID
                && ($change['staged']->isInherit() || $delta['adapter']->isScopable($delta['entity'], $change['field']));
            if ($isScoped) {
                // A form loaded from this release at a store view shows the release's default-scope
                // values; posted back unchanged they are not a store-view edit (custom options would
                // otherwise be staged again at the view and a new option created twice on deploy)
                $releaseDefaults ??= $this->releaseValues->get(
                    $releaseId,
                    $delta['adapter']->getCode(),
                    (string) $delta['entity_id'],
                    Store::DEFAULT_STORE_ID
                );
                $releaseAtView ??= $this->releaseValues->storeOverrides(
                    $releaseId,
                    $delta['adapter']->getCode(),
                    (string) $delta['entity_id'],
                    $delta['store_id']
                );
                $default = $releaseDefaults[$change['field']] ?? null;
                if ($default !== null && !isset($releaseAtView[$change['field']])
                    && !$change['staged']->isInherit() && $default->equals($change['staged'])) {
                    continue;
                }
                $scoped['changes'][] = $change;
            } else {
                $global['changes'][] = $change;
            }
        }

        $response = [];
        $staged = 0;
        foreach ([$global, $scoped] as $group) {
            if (!$group['changes']) {
                continue;
            }
            $response = $this->apiClient->stageDelta($releaseId, $this->payload($group));
            if (empty($response) || !empty($response['error'])) {
                return $delta + ['response' => $response];
            }
            $staged += count($group['changes']);
        }
        $response['change_count'] = $staged;

        return $delta + ['response' => $response];
    }

    /**
     * @return array{entity_id: string, store_id: int, change_count: int, response: array}
     * @throws LocalizedException
     */
    public function quickPreview(string $type, RequestInterface $request): array
    {
        $delta = $this->buildDelta($type, $request);
        if (!$delta['changes']) {
            return $delta + ['response' => []];
        }

        $response = $this->apiClient->quickPreviewDelta($this->payload($delta));

        return $delta + ['response' => $response];
    }

    /**
     * @return array{adapter: AdapterInterface, entity_id: string, store_id: int, title: ?string, changes: array, change_count: int}
     * @throws LocalizedException
     */
    private function buildDelta(string $type, RequestInterface $request): array
    {
        $adapter = $this->adapterPool->get($type);

        $entityId = $adapter->resolveEntityId($request);
        if ($entityId === null) {
            throw new LocalizedException(__('Save the %1 once before staging changes to it.', $adapter->getLabel()));
        }

        $storeId = $adapter->resolveStoreId($request);
        if ($this->storeManager->hasSingleStore()) {
            $storeId = Store::DEFAULT_STORE_ID;
        }

        $entity = $adapter->load($entityId, $storeId);
        $post = $request->getPostValue();
        if (!is_array($post)) {
            $post = [];
        }

        $changes = $this->differ->diff($adapter, $entity, $post, $storeId);

        return [
            'adapter' => $adapter,
            'entity' => $entity,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'title' => $adapter->getTitle($entity),
            'url' => $adapter->getFrontendUrl($entity, $storeId),
            'changes' => $changes,
            'change_count' => count($changes),
        ];
    }

    private function payload(array $delta): array
    {
        /** @var AdapterInterface $adapter */
        $adapter = $delta['adapter'];

        return [
            'entity_type' => $adapter->getCode(),
            'entity_id' => $delta['entity_id'],
            'entity_title' => $delta['title'],
            'entity_url' => $delta['url'],
            'scope_store_id' => $delta['store_id'] === Store::DEFAULT_STORE_ID ? null : $delta['store_id'],
            'changes' => array_map(
                fn (array $c) => [
                    'field' => $c['field'],
                    'original' => $c['original']->toArray(),
                    'staged' => $c['staged']->toArray(),
                ],
                $delta['changes']
            ),
        ];
    }

    /**
     * @param array<string, Value> $values
     */
    public static function encodeValues(array $values): array
    {
        $out = [];
        foreach ($values as $field => $value) {
            $out[$field] = $value->toArray();
        }

        return $out;
    }
}
