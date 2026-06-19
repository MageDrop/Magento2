# Changelog

All notable changes to `MageDrop_Magento2` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
