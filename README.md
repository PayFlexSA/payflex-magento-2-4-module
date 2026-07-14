# Payflex Magento 2.4 Gateway Plugin

## Overview

The Payflex Magento 2.4 Gateway plugin integrates Payflex with your Magento store, adding Payflex "pay in instalments" as a checkout payment method.

**Requirements:** PHP 7.4 or newer, and one of the Magento versions listed below.

## Tested Magento2 Versions

- Magento 2.4.5
- Magento 2.4.6
- Magento 2.4.7
- Magento 2.4.8
- Magento 2.4.9

## Installation Steps

Follow these steps to install the Payflex Gateway plugin:

> ⚠️ **Choose one installation method — 1a *or* 1b, not both.** Keeping a copy in `app/code/Payflex/Gateway` (1a) *and* installing via Composer (1b) means two copies of the module on disk. The module's `registration.php` guards against a double-registration fatal — whichever copy loads first wins and the other silently defers — but running both is confusing and makes updates unpredictable. If you are migrating from a manual install to Composer, delete `app/code/Payflex/Gateway` first (and vice versa).

### 1a. Copy Plugin to Directory (Manual)

- Paste the plugin into the appropriate directory: `/app/code/` for a release (downloaded a zip), or `/app/code/Payflex/Gateway` if you're using the repo directly
- This README.md should be located here if done correctly: `/app/code/Payflex/Gateway/README.md`

### 1b. Install via Composer

From your Magento root directory, run:

```bash
composer require "payflex/magento2-gateway:^2.0"
```

`^2.0` tracks the latest stable `2.x` release; `composer update payflex/magento2-gateway` pulls newer ones later. (Windows `cmd` users: keep the quotes so `^` isn't treated as an escape.)

### 2. Run Magento Setup Commands

- Open your terminal and navigate to your Magento root directory.
- Execute the following commands:

  ```bash
  php bin/magento setup:upgrade
  php bin/magento setup:di:compile
  php bin/magento setup:static-content:deploy -f
  chmod -R 775 var/ pub/ pub/static/ generated/
  php bin/magento cache:clean
  ```

  > **Note:** `setup:static-content:deploy -f` is only required in production mode; in developer mode you can skip it. Adjust the `chmod` target ownership/permissions to match your hosting setup — `775` with the correct web-server group is generally sufficient. See Magento's [file system permissions](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/prerequisites/file-system/overview) guidance if in doubt.

### 3. Configure the Payment Method

Once the setup commands finish, enable and configure the gateway in the Magento admin:

1. Go to **Stores → Configuration → Sales → Payment Methods → Payflex**.
2. Enter your Payflex **Client ID** and **Client Secret**, and select the correct **Environment** (Sandbox or Production).
3. Set **Enabled** to *Yes* and save.
4. Flush the cache (`php bin/magento cache:flush`) if the method does not appear at checkout immediately.

## Upgrading from Previous Versions of This Plugin (<V2.0.0)

1. **Check Pending Orders:**
   - Ensure there are no current orders pending payment that are newer than about an hour old. Existing orders that were completed will still contain their order notes but will effectively be treated as a separate gateway.
   - We suggest putting up a maintenance page while installing this module to avoid any risk of in-progress orders failing during the upgrade process
2. **Check your current config**
   - Review your configuration for Payflex in the admin panel, make sure you have your client details because you will need to fill them in again at the end
3. **Backup:**
   - Make a database and file backup before upgrading.

4. **Remove Old Module:**
   - Delete the old `/app/code/MR/` folder.

5. **Add New Module:**
   - Add the new Payflex module in its place.

6. **Run Upgrade Commands:**
   - Run the necessary commands as you usually would to activate the new module.

7. **Configuration:**
   - Once the new module is active, input your client details and set up the configuration on the configuration page. All new orders should work as normal.

8. **Legacy files**
   - Older modules needed to have `symfony/lock` installed via composer, and the `SourceSandboxproduction.php` file added to `Magento/Config/Model/Config/`. Once
   you upgrade to the new module, these can be removed if you wish, though leaving them shouldn't cause any issues.

## Running the Unit Tests

The module ships with PHPUnit unit tests under `app/code/Payflex/Gateway/Test/Unit/`. They are pure unit tests — no database, web server, or live Payflex connection is required (HTTP is mocked), so they are safe to run on any install.

### Prerequisites

- The module is installed (either manually in `app/code/Payflex/Gateway` or via Composer in `vendor/payflex/magento2-gateway`), and Magento's `composer install` has been run so that PHPUnit and the framework test dependencies are available.

### Run all module unit tests (recommended)

The module ships a small runner, `run-tests.php`, in its root folder. Run it with PHP from anywhere — it locates the Magento root itself, so it works whether the module lives in `app/code` or `vendor`, and regardless of your current directory:

```bash
# Manual (app/code) install:
php app/code/Payflex/Gateway/run-tests.php

# Composer install:
php vendor/payflex/magento2-gateway/run-tests.php
```

Expected result: **13 tests, 21 assertions, all passing**.

The runner generates its own minimal PHPUnit configuration that reuses Magento's standard unit-test bootstrap but omits the Allure reporting extension. This keeps the output clean — see the note below for why that matters.

### Run a single test class or filter

Any extra arguments are passed straight through to PHPUnit. For example:

```bash
# One test file (path is relative to the module's Test/Unit folder or absolute):
php app/code/Payflex/Gateway/run-tests.php Test/Unit/Service/OrderFinalizerTest.php

# Or filter by test-method name:
php app/code/Payflex/Gateway/run-tests.php --filter versionCheck
```

### Running via PHPUnit directly (alternative)

If you prefer to invoke PHPUnit yourself against Magento's stock config, run this from your **Magento root directory**:

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  -c dev/tests/unit/phpunit.xml.dist \
  app/code/Payflex/Gateway/Test/Unit
```

> **Note — the Allure warning.** Magento's stock `dev/tests/unit/phpunit.xml.dist` registers the Allure reporting extension with a *relative* config path (`allure/allure.config.php`). Because the extension resolves that path against the current working directory rather than the config file's location, running from the Magento root produces a noisy `Config file allure/allure.config.php doesn't exist` bootstrap warning. (Running from `dev/tests/unit` makes it resolve instead — but then Allure loads and dumps JSON result files into `dev/tests/unit/var/allure-results`.) This is a stock Magento/Allure quirk, **not** something this module introduces — it affects any module's unit tests — and it never changes the test results. The `run-tests.php` runner above avoids it entirely by not loading Allure.

### What the tests cover

| Test | Covers |
|------|--------|
| `Helper/ConfigurationTest` | `versionCheck()` boundaries (incl. `-pN` suffixes and the max bound); order status/state fallback to `processing`; boolean flag casting |
| `Helper/CommunicationTest` | Environment resolution (sandbox vs production URLs/keys); OAuth token cache-hit vs fresh-fetch (HTTP mocked) |
| `Service/OrderFinalizerTest` | The finalisation decision matrix: reject when not Approved; reject Approved-but-mismatched-reference; capture when Approved with a matching reference |

## Additional Information

- Ensure that you have the necessary permissions to execute the commands.
- It is recommended to back up your Magento store before performing the installation.
- For any issues or support, please contact the Payflex support team.
