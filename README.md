# Payflex Magento 2.4 Gateway Plugin

## Overview

The Payflex Magento 2.4 Gateway plugin integrates Payflex with your Magento store, providing seamless payment processing.

## Tested Magento2 Versions

- Magento 2.4.5 (Currently in testing)
- Magento 2.4.6 (Currently in testing)
- Magento 2.4.7 (Currently in testing)

## Installation Steps

Follow these steps to install the Payflex Gateway plugin:

### 1a. Copy Plugin to Directory (Manual)

- Paste the plugin into the appropriate directory: `/app/code/` for a release, or `/app/code/Payflex/Gateway` if you're using the repo directly
- This README.md should be located here if done correctly: `/app/code/Payflex/Gateway/README.md`

### 1b. Copy Plugin to Directory (Composer)

Add the following to your `composer.json`:

```json
{
   "repositories": [
      {
         "type": "vcs",
         "url": "https://github.com/PayFlexSA/payflex-magento-2-4-module"
      }
   ]
}
```

Then run:

```bash
composer require payflex/magento2-gateway
```

### 2. Run Magento Setup Commands

- Open your terminal and navigate to your Magento root directory.
- Execute the following commands:

  ```bash
  php bin/magento setup:upgrade
  php bin/magento setup:di:compile
  php bin/magento setup:static-content:deploy -f
  chmod -R 777 var/ pub/ pub/static/ generated/
  php bin/magento cache:clean

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

## Additional Information

- Ensure that you have the necessary permissions to execute the commands.
- It is recommended to back up your Magento store before performing the installation.
- For any issues or support, please contact the Payflex support team.
