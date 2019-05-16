<?php

declare(strict_types=1);

namespace wearewondrous\PshToolbelt\Commands;

use Boedah\Robo\Task\Drush\loadTasks;
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
abstract class BaseCommands extends Tasks
{
    use loadTasks;

    public const FILE_DELIMITER = '--';

    public const DATETIME_FORMAT = 'c';

    public const DATE_FORMAT = 'Y-m-d';

    public const VCS_MASTER = 'master';

    public const DB_DUMP_SUFFIX = '--dump';

    public const FILES_DUMP_SUFFIX = '--files-%s';

    /** @var string */
    protected $drushAlias = '';

    /** @var PshConfig */
    protected $pshConfig;

    /** @var ConfigFileReader */
    protected $configFileReader;

    /** @var FileSystemHelper */
    protected $fileSystemHelper;

    public function __construct()
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->configFileReader = new ConfigFileReader($this->fileSystemHelper->getRootDir());
    }

    /**
     * Command initialization.
     *
     * @hook init
     */
    public function initEnvironmentVars() : void
    {
        Robo::Config()->replace($this->configFileReader->getRoboConfig()->export());
        $this->stopOnFail();  // halt, if native cli commands fail
        $this->drushAlias = implode(
            '',
            [
                '@',
                Robo::Config()->get('drush.alias_group'),
                '.',
                Robo::Config()->get('drush.alias'),
            ]
        );

        $this->pshConfig = new PshConfig();

        if (! $this->pshConfig->isValidPlatform()) {
            return;
        }

        Robo::Config()->setDefault('drush.path', 'drush');
    }

    protected function getEnv(string $variable) : ?string
    {
        return $this->pshConfig->variable($variable, getenv($variable));
    }
}
