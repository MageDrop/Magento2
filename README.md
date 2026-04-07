# MageDrop_Magento2

Magento 2 companion module for [MageDrop](https://magentoscheduler-production.up.railway.app) — stage, preview, and deploy CMS content changes as coordinated releases.

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

A branded split button appears on CMS Page and Block edit forms with three actions:

- **Quick Preview** — stages the current form data to a temporary release and opens a preview in a new tab
- **Load from Release** — loads staged changes from a release into the edit form so you can review them
- **Save & Stage** — stages the current form data to a selected release via AJAX

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

1. **Staging** — When you save & stage, the module sends the form data to the SaaS. The SaaS diffs it against what's live (via the Magento REST API) and stores only the changed fields.

2. **Preview** — The module's frontend plugins intercept CMS page/block loading and overlay staged values in-memory. FPC is handled via a vary key (`releaseId:changesHash`) so previewed and non-previewed visitors get different cached pages.

3. **Deploy** — The SaaS pushes staged changes back to Magento via the REST API at the scheduled time. Rollback values are captured at deploy time for safe reversals.

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
