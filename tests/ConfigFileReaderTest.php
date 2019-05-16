<?php

declare(strict_types=1);

namespace wearewondrous\PshToolbelt\Tests;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use wearewondrous\PshToolbelt\ConfigFileReader;
use function file_get_contents;

final class ConfigFileReaderTest extends TestCase
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

    public function testGetAcceptableProjectLocalConfigFilenames() : void
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

    public function testGetProjectLocalConfigFilenameWithNoneFailsHard() : void
    {
        $structure = ['test.yml' => 'test'];

        $localFileSystem = vfsStream::setup('root', null, $structure);

        $this->expectException(FileNotFoundException::class);

        $configFileReader = new ConfigFileReader($localFileSystem->url());
        $configFileReader->getProjectLocalConfigFilename();
    }

    public function testGetConfigSplitFromRoboConfig() : void
    {
        $structure = [
            'robo.yml' => '',
            'robo.yml.dist' => file_get_contents('./robo.yml.dist'),
        ];

        $localFileSystem  = vfsStream::setup('root', null, $structure);
        $configFileReader = new ConfigFileReader($localFileSystem->url());

        $wantedConfigSplitArray = [
            'default' => [
                'machine_name' => 'config_split.config_split.default',
                'folder' => $localFileSystem->url() . '/remote-config/default',
            ],
            'prod' => [
                'machine_name' => 'config_split.config_split.production',
                'folder' => $localFileSystem->url() . '/remote-config/prod',
            ],
            'dev' => [
                'machine_name' => 'config_split.config_split.development',
                'folder' => $localFileSystem->url() . '/remote-config/dev',
            ],
        ];

        $this->assertEquals(
            $wantedConfigSplitArray,
            $configFileReader->getConfigSplitFromRoboConfig()
        );
    }
}
