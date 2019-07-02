<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt;

use Robo\Config\Config as RoboConfig;
use Robo\Robo;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use function array_filter;
use function array_map;
use function file_exists;
use function implode;
use function reset;

/**
 * The Yml config file reader.
 */
class ConfigFileReader {

  /**
   * @var \Robo\Config\Config
   */
  protected $roboConfig;

  /**
   * @var FileSystemHelper
   */
  protected $fileSystemHelper;

  /**
   * @param string|null $rootDirectory
   *   The root directory to use for file management.
   */
  public function __construct(?string $rootDirectory = NULL) {
    $this->fileSystemHelper = new FileSystemHelper($rootDirectory);

    $this->roboConfig = $this->createRoboConfig();
  }

  /**
   * Get merged yml config.
   */
  public function createRoboConfig(): RoboConfig {
    return Robo::createConfiguration(
      [
        $this->getProjectLocalConfigDistFilename(),
        $this->getProjectLocalConfigFilename(),
      ]
    );
  }

  public function getRoboConfig(): RoboConfig {
    return $this->roboConfig;
  }

  /**
   * @return string[]
   *   All acceptable config file names.
   */
  public function getAcceptableProjectLocalConfigFilenames(): array {
    $acceptableFilenames = [
      'robo',
      'toolbelt',
    ];

    return array_map(
      static function ($acceptableFilename) {
        return $acceptableFilename . '.yml';
      },
      $acceptableFilenames
    );
  }

  public function getProjectLocalConfigFilename(): string {
    $realProjectConfigFiles = array_filter(
      $this->getAcceptableProjectLocalConfigFilenames(),
      function ($acceptableProjectLocalConfigFilename) {
        return file_exists($this->fileSystemHelper->getRootDir() . '/' . $acceptableProjectLocalConfigFilename);
      }
    );

    if (count($realProjectConfigFiles) === 0) {
      throw new FileNotFoundException('No valid configuration files found');
    }

    return reset($realProjectConfigFiles);
  }

  public function getProjectLocalConfigDistFilename(): string {
    return $this->getProjectLocalConfigFilename() . '.dist';
  }

  /**
   * Render a config split array for default, dev, and production info.
   *
   * @return mixed[]
   *   The resulting config split array definition for usage.
   */
  public function getConfigSplitFromRoboConfig(): array {
    return [
      'default' => [
        'machine_name' => implode(
          '.',
          [
            'config_split.config_split',
            $this->roboConfig->get('drupal.config.splits.default.machine_name'),
          ]
        ),
        'folder' => implode(
          '/',
          [
            $this->fileSystemHelper->getRootDir(),
            $this->roboConfig->get('platform.mounts.config'),
            $this->roboConfig->get('drupal.config.splits.default.folder'),
          ]
        ),
      ],
      'prod' => [
        'machine_name' => implode(
          '.',
          [
            'config_split.config_split',
            $this->roboConfig->get('drupal.config.splits.prod.machine_name'),
          ]
        ),
        'folder' => implode(
          '/',
          [
            $this->fileSystemHelper->getRootDir(),
            $this->roboConfig->get('platform.mounts.config'),
            $this->roboConfig->get('drupal.config.splits.prod.folder'),
          ]
        ),
      ],
      'dev' => [
        'machine_name' => implode(
          '.',
          [
            'config_split.config_split',
            $this->roboConfig->get('drupal.config.splits.dev.machine_name'),
          ]
        ),
        'folder' => implode(
          '/',
          [
            $this->fileSystemHelper->getRootDir(),
            $this->roboConfig->get('platform.mounts.config'),
            $this->roboConfig->get('drupal.config.splits.dev.folder'),
          ]
        ),
      ],
    ];
  }

}
