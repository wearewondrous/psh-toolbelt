<?php
declare(strict_types=1);

namespace wearewondrous\PshToolbelt;

final class FileSystemHelper
{

    const SRC_PATH = 'vendor/wearewondrous/psh-toolbelt/src';

    /**
     * @var string
     */
    private $rootDir;

    /**
     * FileSystemHelper constructor.
     *
     * @param string|NULL $rootDirectory
     */
    public function __construct(string $rootDirectory = null)
    {
        $this->setRootDir($rootDirectory);
    }

    /**
     * @return string
     */
    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * @param string|NULL $rootDirectory
     */
    private function setRootDir(string $rootDirectory = null) : void
    {
        $directoryToUse = $rootDirectory ?? __DIR__;

        $this->rootDir = str_replace(self::SRC_PATH, '', $directoryToUse);
    }
}
