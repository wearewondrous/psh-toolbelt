<?php

namespace wearewondrous\PshToolbelt\Commands;

use Robo\Robo;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class UpdateCommands extends BaseCommands
{

    /**
     * Updates the local Drupal VM with data from platform.sh remote
     *
     * @param  array $opt
     * @option $branch Select the remote branch to pull from
     * @option $db Whether to pull the database and configs
     * @option $files Whether to pull the files
     *
     * @throws \Robo\Exception\TaskException
     */
    public function updateFromRemote($opt = [
    'branch|b' => 'master',
    'db|d' => false,
    'files|f' => false,
    ]
    ): void {
        if ($this->pshConfig->isValidPlatform()) {
            die("Sorry, only works in local Environment.");
        }

        $activeStash = false;
        $app_dir = $this->fileSystemHelper->getRootDir();
        $git = $this->taskGitStack()
            ->stopOnFail()
            ->dir($app_dir);
        // to capture cli output, use php native commands
        $workspaceChanges = shell_exec("cd {$app_dir} && git status --porcelain");
        $currentBranch = shell_exec("cd {$app_dir} && git rev-parse --abbrev-ref HEAD");

        if ($workspaceChanges) {
            $overwrite = $this->ask('There are changes in your local. Stash them? [Y/n]');

            if (!$overwrite || strtolower($overwrite) !== 'n') {
                $activeStash = true;
                $git->exec('stash');
            }

            $git->exec('reset --hard');
        }
        // checkout target
        $result = $git->checkout($opt['branch'])->run();

        if ($result->wasCancelled()) {
            return;
        }

        if ($opt['files']) {
            $this->syncFilesFromRemote($opt['branch'], $app_dir);
        }

        if ($opt['db']) {
            $this->exportImportDbAndConfig($opt['branch'], $currentBranch, $app_dir);
        }

        $this->yell('All Done!', 50, 'green');

        if (!$activeStash) {
            return;
        }

        $this->yell('🤔 Trying to apply Git Stash, now. This may hurt...', 50, 'default');
        $this->taskGitStack()
            ->stopOnFail()
            ->dir($app_dir)
            ->exec('stash pop')
            ->run();
    }


    /**
     * @param string $branch
     * @param string $currentBranch
     * @param string $app_dir
     *
     * @throws \Robo\Exception\TaskException
     */
    private function exportImportDbAndConfig(string $branch, string $currentBranch, string $app_dir): void
    {
        $this->yell('Export config on remote', 50, 'default');
        $drushPath = Robo::Config()->get('drush.path');
        $this->_exec("platform ssh -e {$branch} '{$drushPath} cex -y'");

        $this->yell('Pull config from remote', 50, 'default');
        $configSyncDir = Robo::config()->get('drupal.config_sync_directory');
        $devDir = Robo::config()->get('drupal.config.splits.dev.folder') . '/';
        $remoteConfigFolder = Robo::config()->get('platform.mounts.config');
        $this->_exec("platform mount:download -e {$branch} -m {$remoteConfigFolder} --target {$app_dir}{$configSyncDir} --delete --exclude={$devDir} --exclude=.htaccess -y -q");

        $this->yell("Pull DB from remote", 50, 'default');
        $dbFileName = implode(
            '', [
            Robo::Config()->get('drush.alias_group') . self::FILE_DELIMITER,
            $branch . self::FILE_DELIMITER,
            date(self::DATE_FORMAT),
            self::DB_DUMP_SUFFIX,
            '.sql',
            ]
        );
        $this->_exec("platform db:dump -e {$branch} -f {$app_dir}{$dbFileName} -y");

        $this->yell("🦄 Applying the magic", 50, 'default');
        $this->taskDrushStack(Robo::Config()->get('drush.path'))
            ->stopOnFail()
            ->dir($app_dir)
            ->siteAlias($this->drushAlias)
            ->exec("{$this->drushAlias} -y sql-drop")
            ->exec("{$this->drushAlias} -y sql-cli < {$dbFileName}")
            ->cacheRebuild()
            ->updateDb()
            ->exec("{$this->drushAlias} -y config-import")
            ->run();
        $this->yell("Go back initital Git branch", 50, 'default');
        $this->taskGitStack()
            ->stopOnFail()
            ->dir($app_dir)
            ->checkout($currentBranch)
            ->run();
    }

    /**
     * Import private and public files.
     *
     * @param string $branch
     */
    private function syncFilesFromRemote(string $branch, string $app_dir): void
    {
        $this->yell("Importing files from remote", 50, 'default');
        $publicFilesDir = Robo::Config()->get('drupal.public_files_directory');
        $privateFilesDir = Robo::Config()->get('drupal.private_files_directory');

        $excludes = '--exclude=' . implode(
            ' --exclude=', Robo::Config()
            ->get('drupal.excludes')
        );
        $options = implode(
            ' ', [
            "-e {$branch}",
            '--delete',
            $excludes,
            '-y',
            ]
        );

        $this->_exec("platform mount:download {$options} -m {$publicFilesDir} --target {$app_dir}{$publicFilesDir}");
        $this->_exec("platform mount:download {$options} -m {$privateFilesDir} --target {$app_dir}{$privateFilesDir}");
    }
}
