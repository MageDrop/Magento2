# Changelog

All notable changes to `MageDrop_Magento2` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-10-02

Fixes found testing deploys, rollbacks and revision restores of products and categories.

### Fixed
- **Deploy revisions recorded an empty gallery.** The revision captured after a MageDrop deploy, rollback or revision restore was taken from the trimmed model the apply saves (untouched sections such as the gallery are left out so Magento doesn't rewrite them), so it showed the gallery, and at store-view scope untouched attributes, as removed. It is now taken from the entity reloaded after the save. Nothing was ever removed in Magento; the MageDrop dashboard ignores empty values in deploy revisions recorded by 2.0.0, so restoring one can't remove images.
- **Rolling back a store-view gallery change failed** with "The media gallery cannot inherit" when the store view had no gallery rows of its own. The previous value of a store-view gallery change now records which images had their own store-view row and which image roles the store view overrode, and a rollback recreates exactly that. "Use Default Value" for a store-view gallery is supported (drops the store view's own image rows and role overrides).
- **A store-view gallery change pinned inherited image roles** (e.g. `swatch_image`) as store-view overrides. A role now only gets a store-view value when the store view already overrode it or the role's image actually changes.
- **Removing images at the default scope deleted every store view's image rows**, so a rollback brought the images back without their store-view labels, positions and hidden flags. Store-view rows are kept while the image is unlinked and come back with it.
- **Rolling back a category removal made from the product reset the product's position** in that category to 0. The previous value now carries the positions and a rollback restores them.

### Added
- `GET /V1/magedrop/entity/:type/:id` reports `scopable_fields` (fields that can hold a store-view value). The dashboard uses it so restoring a store-view revision never changes global values (global attributes, extension sections such as an option mapping) for every store view.
- `Section/CapturesPreviousInterface` and `Section/AfterSaveInterface` for section handlers whose store-view state is more than "overridden or inherited", or that need to adjust rows after the entity save.

## [2.0.0] - 2026-10-01

MageDrop 2.0 adds catalog content (categories and products), store-view scope and an
extension API, and moves staging, deploys and rollbacks into the module. The SaaS keeps
serving 1.x modules unchanged, so stores can upgrade whenever they are ready.

### Added
- **Catalog categories** can be staged, quick-previewed, loaded from a release and deployed: every EAV attribute (including custom ones), any image-backend attribute (`image`, `thumbnail`, …) and the "Products in Category" assignments and positions.
- **Catalog products** (existing products only): every EAV attribute at store-view scope (design fields included), the **media gallery** (new uploads, removals, labels, positions, the hide-from-page flag and every image role as one `media_gallery` change), **category / website assignments**, **customizable options** (`options`, with values), **tier prices**, **related / up-sell / cross-sell links** (`product_links`) and **configurable product associations** (`configurable_links`). The admin POST runs through Magento's own `Initialization\Helper::initializeFromData()`, so custom attributes and third-party form sections that write plain attributes are picked up automatically.
- **Image roles are read from the product's `media_image` attributes**, so custom roles are staged, deployed and previewed like the core ones.
- **Store-view scope.** Changes staged from a store-view scoped form deploy to that store view only; "Use Default Value" is staged as an `inherit` change that removes the store override on deploy and is restored on rollback. Fields without a store dimension (global attributes, the gallery's image set, category products, extension sections) are filed under the default scope even when edited from a store view. One release can hold changes for the same entity at several scopes.
- **Entity adapter architecture.** `Model/Entity/AdapterInterface` + `AdapterPool` describe how to load, diff, apply, preview and snapshot an entity; `Section/SectionHandlerInterface` covers a group of fields. Register your own adapters and section handlers in `di.xml` to make custom entities and form tabs stageable without changing this module.
- `Section/DescribesValuesInterface`: sections that stage structured values can tell the MageDrop dashboard how to diff them (record identity, labels, nested lists); published on handshake.
- **Module REST endpoints for the SaaS** (`GET /V1/magedrop/entity/:type/:id`, `POST /V1/magedrop/apply`, `GET /V1/magedrop/capabilities`, guarded by the new ACL resource `MageDrop_Magento2::api`). Deploys, rollbacks and revision restores go through Magento's own models (store scope, image backends, save observers) instead of core `cmsPage`/`cmsBlock` REST.
- **Open in Magento** deep link (`/<admin>/magedrop/open/index/type/<type>/id/<id>[/store/<id>]`) that works with "Add Secret Key to URLs"; custom entity types resolve through their adapter.
- **Preview of category listings**: products added to or removed from a category (from the category's product list or a product's categories) and staged positions show on category pages. The change is applied to the page Magento loads, so layered navigation and the product count reflect the live listing.
- **Media cleanup** (cron `magedrop_clean_staged_media`, daily): removes images staged for releases that never deployed, and gallery images a deploy removed once they can no longer be rolled back to. Configure under **Stores > Configuration > MageDrop > Media Cleanup** (on by default, 30-day retention — effectively the rollback window for removed images).
- `X-MageDrop-Module-Version` header on every SaaS request and a `POST handshake` that publishes version, features, entity types, value rules and store views.
- Block-HTML cache key plugin so cached menus (Luma `catalog.topnav`, Hyvä `topmenu_generic`) never leak between preview and live visitors.
- Category and product revisions captured on admin save (`etc/adminhtml/events.xml`).

### Changed
- **Staging intercepts the real admin save.** Save & Stage / Quick Preview submit the form with a MageDrop flag; `StageSavePlugin` diffs the exact POST Magento would have persisted against the live entity and sends only the delta. The AJAX `SaveStage`/`QuickPreview` controllers and the flat `form_data` protocol are gone. Staging is only offered for existing entities.
- All values cross boundaries as typed envelopes (`text`, `json`, `image`, `inherit`). Decimal formatting (`2025.000000` vs `2025.00`), layout "no update" sentinels and untouched attribute defaults no longer stage as changes.
- **Removing a gallery image never deletes its file.** The image is unlinked from the product so a rollback can restore it; the media cleanup removes it after the retention period.
- Preview is store-scope aware and matches what a deploy would show: default-scope changes are hidden where the store view overrides the field, and store-view gallery labels/positions/hidden flags and role images win over a default-scope gallery change.
- A release's preview changes are fetched once and cached for 60 seconds per preview session.
- Quick Preview result is shown after the redirect via `quick-preview-result.js`.
- `LoadNotice`, `MageDropButton`, `LoadChanges` and the revision observers are adapter-driven; the hardcoded CMS routing tables are removed.
- `ping` now requires `MageDrop_Magento2::api` instead of `Magento_Cms::page`.

### Not supported (by design)
- **Stock and source quantities** are never staged: a content release must not change stock.
- **Bundle options** and **downloadable links / samples** are not staged: they are tied to orders and files.
- **Creating entities** cannot be scheduled. Create the entity disabled and stage enabling it.

### Requires
- MageDrop SaaS with the 2.0 module API (live on magedrop.com).
- The Magento integration used by MageDrop must be granted **MageDrop → API** (`MageDrop_Magento2::api`); it is the only permission the module needs. "Stores → Settings → All Stores" is optional (lets the dashboard list store views directly). Upgraded integrations without MageDrop → API show the module as disconnected until it is ticked.
- `bin/magento setup:upgrade && rm -rf generated/code generated/metadata && bin/magento setup:di:compile && bin/magento cache:flush` after upgrading.

### Known limitations
- Category listing preview: layered navigation filters and counts reflect the live listing; added products are placed by staged position even when sorting by price or name; search results do not reflect staged assignments.
- Preview does not show related / up-sell / cross-sell products or stock and salability changes; deploy is correct.
- Configurable products: only the association (which children) is staged; new variations created in the matrix are not, and changing the configurable attributes is rejected at deploy. Stage child product edits on the child products.
- An empty store-view value is Magento's "use default": deploying an empty text to a store view shows the default value there (the dashboard warns about this).
- Deploying a gallery change at store-view scope goes through Magento's own gallery handlers, which write per-store label/position rows for every image of that product — the same as an admin save at that store view.

## [1.0.8] - 2026-06-19

### Changed
- Preview overlays now fetch all of a release's staged changes in a single SaaS request instead of one request per CMS page/block. `Overlay` loads the full change set on first use and serves every subsequent entity from an in-memory map, so a page rendering many CMS blocks costs at most one API call. Backed by the new `POST /api/module/preview/all` endpoint.

## [1.0.7] - 2026-05-21

### Fixed
- `LoadChangesPlugin::afterGetData` no longer crashes when creating a new CMS block or page. Magento's data provider returns `null` from `getData()` before an entity exists, but the plugin's `array` parameter type rejected it with a `TypeError`. Signature relaxed and a defensive guard added so non-array results pass through untouched.

## [1.0.6] - 2026-05-20

### Fixed
- Identifier-based block and page loads now bypass the storeId-gated `is_active=1` filter during preview. v1.0.5 only covered numeric `block_id` / `page_id` lookups; identifier lookups (`{{block id="..."}}`, `Cms\Block\BlockByIdentifier`, the CMS router's `Page::checkIdentifier`) still hit the filter and silently returned empty for disabled entities, so staged `is_active=1` had no visible effect.
- Deterministic tie-breaker (newest `block_id` / `page_id`) when the same identifier exists in the same store more than once — picks the latest entity rather than an arbitrary legacy duplicate.

### Changed
- `CmsBlockPlugin` and `CmsPagePlugin` consolidate their numeric and identifier resolve paths into a single helper that switches the lookup column on `is_numeric($modelId)`. No behaviour change for callers; less duplication internally.

### Requires
- SaaS app commit `0e72bf0` or later — the preview/validate endpoint must translate REST-style field names (e.g. `active`) back to Magento DB column names (e.g. `is_active`) before returning, otherwise the overlay sets a field the model doesn't read.

## [1.0.5] - 2026-05-20

### Added
- Preview support for staged `is_active` changes on CMS blocks and pages — a release that toggles a disabled entity to enabled (or vice versa) is now reflected in preview
- `GetBlockByIdentifierPlugin` to cover `Cms\Block\BlockByIdentifier` / `BlockRepository::getByIdentifier` — looks up disabled blocks store-scoped and overlays staged data
- `PageCheckIdentifierPlugin` so disabled pages can be resolved by URL during preview (router was 404'ing before any overlay could run)
- `Model/Preview/State` and `Model/Preview/Overlay` services for shared preview state reads and request-cached overlay application

### Changed
- `CmsBlockPlugin` and `CmsPagePlugin` switched from `afterLoad` to `aroundLoad` — verify store membership ourselves then bypass the storeId-gated `is_active=1` filter in `ResourceModel\Block`/`Page::_getLoadSelect`, with no cross-store leak

## [1.0.4] - 2026-04-16

### Changed
- Moved CMS page/block save revision observers from adminhtml to global scope

## [1.0.3] - 2026-04-07

### Changed
- Updated preview bar to black with MageDrop logo and rounded exit button
- Updated Quick Preview modal button and links to match branding
- Updated load notice banner to match branding
- Moved logo assets to base scope for use across adminhtml and frontend

## [1.0.1] - 2026-04-07

### Changed
- Updated admin button to black with MageDrop logo icon
- Added MageDrop logo to system configuration tab

## [1.0.0] - 2026-04-07

### Added
- MageDrop split button on CMS Page and Block edit forms (Quick Preview, Load from Release, Save & Stage)
- Stage CMS Page and Block changes to releases via SaaS API
- Quick Preview — creates temporary preview release, opens preview in new tab
- Load from Release — loads staged changes into form via data provider plugin
- Save & Stage — AJAX-based staging with modal release picker
- Preview bar on frontend for Luma and Hyva themes
- Preview context plugin for FPC vary key support
- Automatic revision tracking — every CMS page and block save is captured as a revision
- Connection test via REST API ping endpoint (`GET /V1/magedrop/ping`)
- Admin configuration (Stores > Config > MageDrop > Connection)
- Admin grid page (Marketing > Releases)
- FPC Identifier fix for Magento 2.4.8-p4 compatibility
