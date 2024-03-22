<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt\Commands;

use Platformsh\ConfigReader\Config as PshConfig;
use Robo\Robo;
use Robo\Tasks;
use wearewondrous\PshToolbelt\ConfigFileReader;
use wearewondrous\PshToolbelt\FileSystemHelper;
use function getenv;
use function implode;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
abstract class BaseCommands extends Tasks {

  public const FILE_DELIMITER = '--';

  public const DATETIME_FORMAT = 'c';

  public const DATE_FORMAT = 'Y-m-d';

  public const VCS_MASTER = 'master';

  public const DB_DUMP_SUFFIX = '--dump';

  public const FILES_DUMP_SUFFIX = '--files-%s';

  /**
   * @var string
   */
  protected $drushAlias = '';

  /**
   * @var string
   */
  protected $containerCommand = '';

  /**
   * @var \Platformsh\ConfigReader\Config
   */
  protected $pshConfig;

  /**
   * @var \wearewondrous\PshToolbelt\ConfigFileReader
   */
  protected $configFileReader;

  /**
   * @var \wearewondrous\PshToolbelt\FileSystemHelper
   */
  protected $fileSystemHelper;

  public function __construct() {
    $this->fileSystemHelper = new FileSystemHelper();
    $this->configFileReader = new ConfigFileReader($this->fileSystemHelper->getRootDir());
  }

  /**
   * Command initialization.
   *
   * @hook init
   */
  public function initEnvironmentVars() : void {
    Robo::config()->replace($this->configFileReader->getRoboConfig()->export());
    // halt, if native cli commands fail.
    $this->stopOnFail();
    $this->drushAlias = implode(
          '',
          [
            '@',
            Robo::config()->get('drush.alias_group'),
            '.',
            Robo::config()->get('drush.alias'),
          ]
      );

    $this->containerCommand = Robo::config()->get('local_container.name');

    $this->pshConfig = new PshConfig();

    if (!$this->pshConfig->isValidPlatform()) {
      return;
    }

    Robo::config()->setDefault('drush.path', 'drush');
  }

  protected function getEnv(string $variableName) : ?string {
    $envVariable = getenv($variableName);
    $pshVariable = $this->pshConfig->variable($variableName);

    if ($pshVariable !== NULL) {
      return $pshVariable;
    }

    if ($envVariable !== FALSE) {
      return $envVariable;
    }

    return NULL;
  }

}
