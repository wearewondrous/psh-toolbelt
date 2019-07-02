<?php

declare(strict_types = 1);

use Dotenv\Dotenv;
use wearewondrous\PshToolbelt\FileSystemHelper;

/**
 * Load any .env file. See /.env.dist.
 */

try {
  $fileSystemHelper = new FileSystemHelper();

  Dotenv::create($fileSystemHelper->getRootDir())->safeLoad();
}
catch (Throwable $e) {
  // Suppressing exception.
}
