# MageDrop_Magento2

Magento 2 companion module for [MageDrop](https://www.magedrop.com) — stage, preview, and deploy CMS and catalog content changes as coordinated releases.

## Requirements

- Magento 2.4.x
- PHP 8.2+
- A MageDrop SaaS account

## Installation

```bash
composer require magedrop/magento2
php bin/magento module:enable MageDrop_Magento2
php bin/magento setup:upgrade
rm -rf generated/
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Configuration

Navigate to **Stores > Configuration > MageDrop > Connection** and enter:

| Field | Description |
|-------|-------------|
| Enabled | Enable/disable the module |
| Module Token | The token from your store's setup page in the MageDrop dashboard |

## Features

### MageDrop Button

A branded split button appears on CMS Page, CMS Block, Category and Product edit forms with three actions:

- **Quick Preview** — diffs the form against the live entity, stages the delta to a temporary release and shows a shareable preview link
- **Load from Release** — loads staged changes from a release into the edit form so you can review them
- **Save & Stage** — submits the real admin form flagged for MageDrop; the save is intercepted, diffed against the live entity at the current store view, and only the delta is staged. Nothing is saved to the live store.

Categories support store-view scope: stage from a store-view scoped form and the change deploys to that store view only. Ticking **Use Default Value** stages an `inherit` change that removes the override on deploy.

### Entity adapters (extension point)

Every entity type is an `AdapterInterface` registered in the `AdapterPool` DI argument (`etc/di.xml`). An adapter owns a list of `SectionHandlerInterface` implementations, each covering a group of fields:

| Handler | Fields |
|---|---|
| `Section\Attributes` | scalar / EAV attributes, "Use Default Value" → `inherit` |
| `Section\Category\Image` | `image`, `thumbnail` and any category attribute with the image backend |
| `Section\Category\Products` | "Products in Category" assignments |
| `Section\Product\MediaGallery` | product images: files, labels, positions, disabled flag, base/small/thumbnail/swatch roles |
| `Section\Product\Assignments` | product `category_ids` and `website_ids` |
| `Section\Product\CustomOptions` | customizable options and their values |
| `Section\Product\TierPrice` | tier prices |
| `Section\Product\ProductLinks` | related / up-sell / cross-sell links |
| `Section\Product\Stock` | stock item fields (qty, in stock, Advanced Inventory) |
| `Section\Product\BundleOptions` | bundle options and selections |
| `Section\Product\ConfigurableLinks` | configurable child associations |
| `Section\Product\DownloadableLinks` | downloadable links and samples |

Product section handlers receive the initialised product data plus two extra keys: `_post` (the raw admin POST) and `_form_product` (the product model after `initializeFromData`). A third-party handler for a custom product-form tab (for example a fabric mapping stored in its own table) reads its rows from `_post['product'][...]`, compares them with what it loads for the product, writes them on `apply()`, and can set a data key on the product in `overlay()` for its own frontend code to pick up during preview.

To make a custom form section stageable (e.g. a third-party product tab), implement `SectionHandlerInterface` and add it to the adapter's `sections` array in your own `di.xml`. To add a whole new entity type, implement `AdapterInterface` (extend `AbstractAdapter`) and register it under a new key in `AdapterPool`; the SaaS picks it up from the handshake.

### REST endpoints used by the SaaS

| Route | Purpose | ACL |
|---|---|---|
| `GET /V1/magedrop/ping` | round-trip connection test | `Magento_Cms::page` |
| `GET /V1/magedrop/capabilities` | module version, features, entity types | `MageDrop_Magento2::api` |
| `GET /V1/magedrop/entity/:type/:id?storeId=` | current normalised state at a store scope | `MageDrop_Magento2::api` |
| `POST /V1/magedrop/apply` | apply values at a store scope, returns previous values for rollback | `MageDrop_Magento2::api` |

The integration created for MageDrop must be granted **MageDrop → API**.

### Preview Bar

When previewing staged content on the frontend, a branded bar appears at the top of the page showing which release is being previewed, with an exit button.

Supports both Luma and Hyva themes.

### Connection Test

The module exposes a REST endpoint (`GET /rest/V1/magedrop/ping`) that the SaaS uses to verify the round-trip connection: SaaS > Magento > Module > SaaS.

## How It Works

```
Magento Admin                         MageDrop SaaS
+------------------+                 +------------------+
| Edit CMS content |                 | Release CRUD     |
| "Save & Stage"   |---- delta ---->| Store changes    |
|                  |                 |                  |
| Preview mode     |<--- changes ---| Preview API      |
| (plugins overlay |                 |                  |
|  staged data)    |                 | Deploy cron      |
|                  |<--- REST API --| (pushes changes)  |
| REST API receives|                 |                  |
| deployed changes |                 |                  |
+------------------+                 +------------------+
```

1. **Staging** — Save & Stage submits the real admin form with a MageDrop flag. A plugin on the Save controller loads the entity at the requested store view, diffs the POST against it through the entity adapter's section handlers, and sends only the changed fields (as typed value envelopes) to the SaaS.

2. **Preview** — Frontend plugins overlay staged values in-memory on CMS pages/blocks and on categories (single loads and menu collections), honouring store-view scope. FPC is varied by `releaseId:changesHash`; block-HTML cache keys include the same token.

3. **Deploy** — At the scheduled time the SaaS calls `POST /V1/magedrop/apply` for each entity/scope. The module applies the values with Magento's own models (so store scope, image backends and save observers behave exactly as an admin save) and returns the previous values, which the SaaS keeps for rollback.

## Compatibility

- **Magento 2.4.8-p4**: Includes a fix for the FPC `IdentifierInterface` change in `etc/di.xml`
- **Hyva**: Preview bar has a dedicated Hyva template

## Updating

```bash
composer update magedrop/magento2
rm -rf generated/
php bin/magento setup:di:compile
php bin/magento setup:upgrade
php bin/magento cache:flush
```

## Uninstalling

```bash
php bin/magento module:disable MageDrop_Magento2
composer remove magedrop/magento2
rm -rf generated/
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## License

Proprietary. All rights reserved.
