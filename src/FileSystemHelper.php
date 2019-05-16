<?php

declare(strict_types=1);

namespace wearewondrous\PshToolbelt;

use function str_replace;

final class FileSystemHelper
{
    public const SRC_PATH = 'vendor/wearewondrous/psh-toolbelt/src';

    /** @var string */
    private $rootDir;

    public function __construct(?string $rootDirectory = null)
    {
        $this->setRootDir($rootDirectory);
    }

    public function getRootDir() : string
    {
        return $this->rootDir;
    }

    private function setRootDir(?string $rootDirectory = null) : void
    {
        $directoryToUse = $rootDirectory ?? __DIR__;

        $this->rootDir = str_replace(self::SRC_PATH, '', $directoryToUse);
    }
}
