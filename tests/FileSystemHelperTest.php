<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt\Tests;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use wearewondrous\PshToolbelt\FileSystemHelper;

final class FileSystemHelperTest extends TestCase {
  /**
   * @var \org\bovigo\vfs\vfsStreamDirectory
   */
  public $fileSystem;

  public function setUp() : void {
    $structure = [
      'robo.yml' => 'test',
      'robo.yml.dist' => 'test',
    ];

    $this->fileSystem = vfsStream::setup('root', NULL, $structure);
  }

  public function testGetRootDir() : void {
    $fileSystemHelper = new FileSystemHelper($this->fileSystem->url());
    $rootDir          = $fileSystemHelper->getRootDir();

    Assert::assertEquals(
          'vfs://root',
          $rootDir
      );

    $fileSystemHelper = new FileSystemHelper('/app/tests');
    $rootDir          = $fileSystemHelper->getRootDir();

    Assert::assertEquals(
          '/app/tests',
          $rootDir
      );

    $fileSystemHelper = new FileSystemHelper('/app/vendor/wearewondrous/psh-toolbelt/src');
    $rootDir          = $fileSystemHelper->getRootDir();

    Assert::assertEquals(
          '/app/',
          $rootDir
      );
  }

}
