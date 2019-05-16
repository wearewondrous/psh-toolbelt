<?php

declare(strict_types=1);

namespace wearewondrous\PshToolbelt\Tests;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use wearewondrous\PshToolbelt\FileSystemHelper;

final class FileSystemHelperTest extends TestCase
{
    /** @var vfsStreamDirectory */
    public $fileSystem;

    public function setUp() : void
    {
        $structure = [
            'robo.yml' => 'test',
            'robo.yml.dist' => 'test',
        ];

        $this->fileSystem = vfsStream::setup('root', null, $structure);
    }

    public function testGetRootDir() : void
    {
        $fileSystemHelper = new FileSystemHelper($this->fileSystem->url());
        $rootDir          = $fileSystemHelper->getRootDir();

        $this->assertEquals(
            'vfs://root',
            $rootDir
        );

        $fileSystemHelper = new FileSystemHelper('/app/tests');
        $rootDir          = $fileSystemHelper->getRootDir();

        $this->assertEquals(
            '/app/tests',
            $rootDir
        );

        $fileSystemHelper = new FileSystemHelper('/app/vendor/wearewondrous/psh-toolbelt/src');
        $rootDir          = $fileSystemHelper->getRootDir();

        $this->assertEquals(
            '/app/',
            $rootDir
        );
    }
}
