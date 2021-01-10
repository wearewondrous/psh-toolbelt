<?php

declare(strict_types = 1);

namespace wearewondrous\PshToolbelt\Commands;

use Robo\Robo;
use const JSON_PRETTY_PRINT;
use function getenv;
use function json_encode;
use function print_r;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class TestCommands extends BaseCommands {

  /**
   * Print out the current configuration with env overwrites.
   */
  public function testVars() : void {
    $this->say('=== Env vars =============================');
    print_r(json_encode(getenv(), JSON_PRETTY_PRINT) . "\n");
    $this->say('=== Robo vars ============================');
    print_r(json_encode(Robo::config()->export(), JSON_PRETTY_PRINT) . "\n");
  }

  /**
   * Test the local vm by running drush status.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function testLocalDrush() : void {
    $this->taskDrushStack(Robo::config()->get('drush.path'))
      ->siteAlias($this->drushAlias)
      ->status()
      ->run();
  }

}
