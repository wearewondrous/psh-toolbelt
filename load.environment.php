<?php
/**
 * This file is included very early. See autoload.files in composer.json and
 * https://getcomposer.org/doc/04-schema.md#files
 */

use Dotenv\Dotenv;
use wearewondrous\PshToolbelt\SiteSettings;

/**
 * Load any .env file. See /.env.dist.
 */

try {
  Dotenv::create(SiteSettings::getRootDir())->safeLoad();
} catch (Exception $e) {
  // suppressing exception
}
