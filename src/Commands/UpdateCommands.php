<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt\Commands;

use Robo\Exception\TaskException;
use Robo\Robo;
use function date;
use function implode;
use function shell_exec;
use function sprintf;
use function strtolower;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class UpdateCommands extends BaseCommands {

  /**
   * Updates the local Drupal VM with data from platform.sh remote.
   *
   * @param mixed[] $opt
   *   The operation variables to use.
   *
   * @throws \Robo\Exception\TaskException
   *
   * @option $branch Select the remote branch to pull from
   * @option $db Whether to pull the database and configs
   * @option $files Whether to pull the files
   */
  public function updateFromRemote(array $opt = [
    'branch|b' => 'master',
    'db|d' => FALSE,
    'files|f' => FALSE,
  ]
    ) : void {
    if ($this->pshConfig->isValidPlatform()) {
      die('Sorry, only works in local Environment.');
    }

    $activeStash = FALSE;
    $app_dir     = $this->fileSystemHelper->getRootDir();
    $git         = $this->taskGitStack()
      ->stopOnFail()
      ->dir($app_dir);
    // To capture cli output, use php native commands.
    $workspaceChanges = shell_exec(sprintf('cd %s && git status --porcelain', $app_dir));
    $currentBranch    = shell_exec(sprintf('cd %s && git rev-parse --abbrev-ref HEAD', $app_dir));

    if ($currentBranch === NULL) {
      $currentBranch = 'master';
    }

    if ($workspaceChanges !== NULL) {
      $overwrite = $this->ask('There are changes in your local. Stash them? [Y/n]');

      if ($overwrite === "" || strtolower($overwrite) !== 'n') {
        $activeStash = TRUE;
        $git->exec('stash');
      }

      $git->exec('reset --hard');
    }

    // Checkout target.
    $result = $git->checkout($opt['branch'])->run();

    if ($result->wasCancelled()) {
      return;
    }

    if ($opt['files'] === TRUE) {
      $this->syncFilesFromRemote($opt['branch'], $app_dir);
    }

    if ($opt['db'] === TRUE) {
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
   * @throws \Robo\Exception\TaskException
   */
  private function exportImportDbAndConfig(string $branch, string $currentBranch, string $app_dir) : void {
    $this->yell('Export config on remote', 50, 'default');
    $drushPath = Robo::config()->get('drush.path');
    $this->_exec(sprintf("platform ssh -e %s '%s cex -y'", $branch, $drushPath));

    $this->yell('Pull config from remote', 50, 'default');
    $configSyncDir      = Robo::config()->get('drupal.config_sync_directory');
    $devDir             = Robo::config()->get('drupal.config.splits.dev.folder') . '/';
    $remoteConfigFolder = Robo::config()->get('platform.mounts.config');
        // phpcs:ignore
        $this->_exec(sprintf("platform mount:download -e %s -m %s --target %s%s --delete --exclude=%s --exclude=.htaccess -y -q", $branch, $remoteConfigFolder, $app_dir, $configSyncDir, $devDir));

    $this->yell('Pull DB from remote', 50, 'default');
    $dbFileName = implode(
          '',
          [
            Robo::config()->get('drush.alias_group') . self::FILE_DELIMITER,
            $branch . self::FILE_DELIMITER,
            date(self::DATE_FORMAT),
            self::DB_DUMP_SUFFIX,
            '.sql',
          ]
      );
    $this->_exec(sprintf('platform db:dump -e %s -f %s%s -y', $branch, $app_dir, $dbFileName));

    $this->yell('🦄 Applying the magic', 50, 'default');
    $this->taskDrushStack(Robo::config()->get('drush.path'))
      ->stopOnFail()
      ->dir($app_dir)
      ->siteAlias($this->drushAlias)
      ->exec(sprintf('%s -y sql-drop', $this->drushAlias))
      ->exec(sprintf('%s -y sql-cli < %s', $this->drushAlias, $dbFileName))
      ->cacheRebuild()
      ->updateDb()
      ->exec(sprintf('%s -y config-import', $this->drushAlias))
      ->run();
    $this->yell('Go back initital Git branch', 50, 'default');
    $this->taskGitStack()
      ->stopOnFail()
      ->dir($app_dir)
      ->checkout($currentBranch)
      ->run();
  }

  /**
   * Import private and public files.
   */
  private function syncFilesFromRemote(string $branch, string $app_dir) : void {
    $this->yell('Importing files from remote', 50, 'default');
    $publicFilesDir = Robo::config()->get('drupal.public_files_directory');
    $privateFilesDir = Robo::config()->get('drupal.private_files_directory');

    $excludes = '--exclude=' . implode(
          ' --exclude=',
          Robo::config()
            ->get('drupal.excludes')
      );
    $options = implode(
          ' ',
          [
            sprintf('-e %s', $branch),
            '--delete',
            $excludes,
            '-y',
          ]
      );

    $this->_exec(sprintf('platform mount:download %s -m %s --target %s%s', $options, $publicFilesDir, $app_dir, $publicFilesDir));
    $this->_exec(sprintf('platform mount:download %s -m %s --target %s%s', $options, $privateFilesDir, $app_dir, $privateFilesDir));
  }

}
