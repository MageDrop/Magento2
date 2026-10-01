# Changelog

All notable changes to `MageDrop_Magento2` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-09-14

### Added
- **Catalog categories** can now be staged, quick-previewed, loaded from a release and deployed: every EAV attribute (including custom ones such as navigation colours), the `image`/`thumbnail` (and any other image-backend attribute), and the "Products in Category" assignments.
- **Catalog products** (existing products only): every EAV attribute at store-view scope (design fields included), the **media gallery** (new uploads, removals, labels, positions, hide-from-page flag and the base/small/thumbnail/swatch roles as one `media_gallery` change), **category / website assignments**, **customizable options** (`options`, with values), **tier prices**, **related / up-sell / cross-sell links** (`product_links`) and **configurable product associations** (`configurable_links`). Stock / source quantities, bundle options and downloadable links are deliberately **not** staged — stock must never change through a content release, and bundle/downloadable data is tied to orders and files. The admin POST is run through Magento's own `Initialization\Helper::initializeFromData()` so custom attributes and third-party form sections that write plain attributes are picked up automatically. Preview overlays attributes, gallery, custom options and tier prices on the product page, in listings and search.
- Cron `magedrop_clean_staged_media` (daily) removes images that staging moved into `catalog/product` / `catalog/category` for releases that never deployed (14-day grace, only when nothing references the file).
- **Store-view scope.** Changes staged from a store-view scoped admin form deploy to that store view only; "Use Default Value" is staged as an `inherit` change that removes the store override on deploy and is restored on rollback.
- **Entity adapter architecture.** `Model/Entity/AdapterInterface` + `AdapterPool` describe how to load, diff, apply, preview and snapshot an entity; `Section/SectionHandlerInterface` covers a group of fields (scalar attributes, images, product assignments, a third-party form tab). Register your own adapters and section handlers via `di.xml` to make custom entities and form sections stageable without changing this module.
- **Module REST endpoints for the SaaS** (`GET /V1/magedrop/entity/:type/:id`, `POST /V1/magedrop/apply`, `GET /V1/magedrop/capabilities`), guarded by the new ACL resource `MageDrop_Magento2::api`. Deploys, rollbacks and revision restores now go through Magento's own models (store scope, image backends, save observers) instead of core `cmsPage`/`cmsBlock` REST.
- `X-MageDrop-Module-Version` header on every SaaS request and a `POST handshake` that publishes version, features, entity types and store views.
- Block-HTML cache key plugin so cached menus (Luma `catalog.topnav`, Hyvä `topmenu_generic`) never leak between preview and live visitors.
- Category revisions captured on admin save (`etc/adminhtml/events.xml`).

### Changed
- **Staging now intercepts the real admin save.** Save & Stage / Quick Preview submit the form with a MageDrop flag; `StageSavePlugin` diffs the exact POST Magento would have persisted against the live entity and ships only the delta. The AJAX `SaveStage`/`QuickPreview` controllers and the flat scalar `form_data` protocol are gone.
- All values cross boundaries as typed envelopes (`text`, `json`, `image`, `inherit`).
- Quick Preview result is shown after the redirect via `quick-preview-result.js`.
- `LoadNotice`, `MageDropButton`, `LoadChanges` and the revision observers are adapter-driven; the hardcoded CMS routing tables are removed.
- `Overlay` is store-scope aware: default-scope changes are hidden where the current store view overrides the field.

### Requires
- SaaS commit that ships the protocol-2 module API (Value envelopes, scoped preview groups, `POST handshake`).
- The Magento integration used by the SaaS must be granted **MageDrop → API** (`MageDrop_Magento2::api`). It is the only permission the module needs: `ping` moved to it from `Magento_Cms::page`, so Content → Pages / Blocks are no longer required. Upgraded integrations that lack it report the module as disconnected until it is ticked.
- `bin/magento setup:upgrade && rm -rf generated && bin/magento setup:di:compile` after upgrading (new webapi/di).

### Known limitations
- Preview cannot add a category to menus/listings when only `is_active`/`include_in_menu`/product assignment is staged (SQL/search-index filters); deploy is correct.
- Configurable products: only the association (which children) is staged; new variations created in the matrix are not (Magento would have to create the child products first), and changing the configurable attributes is rejected at deploy. Child product edits made in the matrix should be staged on the child products.
- Preview does not overlay product links, bundle selections, downloadable links, stock/salability or category assignments — those are read from the database or the search index on the storefront. Deploy is correct.
- An empty store-view value is Magento's "use default": deploying an empty text to a store view shows the default value there (the dashboard warns about this).
- Saving a product loaded at store scope goes through Magento's own gallery handlers, so when a gallery change is deployed to a store view Magento writes per-store label/position rows for every image of that product — the same thing an admin save at that store view does.

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
