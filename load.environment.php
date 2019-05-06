<?php
/**
 * This file is included very early. See autoload.files in composer.json and
 * https://getcomposer.org/doc/04-schema.md#files
 */

use Dotenv\Dotenv;
use wearewondrous\PshToolbelt\FileSystemHelper;

/**
 * Load any .env file. See /.env.dist.
 *
 */

try {
    $fileSystemHelper = new FileSystemHelper();

    Dotenv::create($fileSystemHelper->getRootDir())->safeLoad();
} catch (Exception $e) {
  // suppressing exception
}
