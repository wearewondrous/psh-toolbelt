<?php
declare(strict_types=1);

namespace wearewondrous\PshToolbelt\Tests;

use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use wearewondrous\PshToolbelt\ConfigFileReader;
use org\bovigo\vfs\vfsStream;

final class ConfigFileReaderTest extends TestCase
{

  /**
   * @var vfsStreamDirectory
   */
  public $fileSystem;

  public function setUp()
  {
    $structure = [
      'robo.yml' => 'test',
      'robo.yml.dist' => 'test',
    ];

    $this->fileSystem = vfsStream::setup('root', null, $structure);
  }

  public function testGetAcceptableProjectLocalConfigFilenames(): void
  {
    $configFileReader = new ConfigFileReader($this->fileSystem->url());

    $this->assertEquals(
      $configFileReader->getAcceptableProjectLocalConfigFilenames(),
      ['robo.yml', 'toolbelt.yml']
    );
  }

  public function testGetProjectLocalConfigFilename() : void
  {
    $configFileReader = new ConfigFileReader($this->fileSystem->url());

    $this->assertEquals(
      'robo.yml',
      $configFileReader->getProjectLocalConfigFilename()
    );
  }

  public function testGetProjectLocalConfigFilenameWithToolbelt() : void
  {
    $structure = [
      'toolbelt.yml' => 'test',
      'toolbelt.yml.dist' => 'test',
    ];

    $localFileSystem = vfsStream::setup('root', null, $structure);

    $configFileReader = new ConfigFileReader($localFileSystem->url());

    $this->assertEquals(
      'toolbelt.yml',
      $configFileReader->getProjectLocalConfigFilename()
    );
  }

  public function testGetProjectLocalConfigFilenameWithMultipleOnlyReturnsFirst() : void
  {
    $structure = [
      'toolbelt.yml' => 'test',
      'toolbelt.yml.dist' => 'test',
      'robo.yml' => 'test',
      'robo.yml.dist' => 'test',
    ];

    $localFileSystem = vfsStream::setup('root', null, $structure);

    $configFileReader = new ConfigFileReader($localFileSystem->url());

    $this->assertEquals(
      'robo.yml',
      $configFileReader->getProjectLocalConfigFilename()
    );
  }

  /**
   * @expectedException Symfony\Component\Filesystem\Exception\FileNotFoundException
   */
  public function testGetProjectLocalConfigFilenameWithNoneFailsHard() : void
  {
    $structure = [
      'test.yml' => 'test',
    ];

    $localFileSystem = vfsStream::setup('root', null, $structure);

    $configFileReader = new ConfigFileReader($localFileSystem->url());
    $configFileReader->getProjectLocalConfigFilename();
  }
}
