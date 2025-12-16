<?php
/**
 * @file
 *
 * Install and configure Monolog to direct all logs to stdout
 */

if (!defined('CIVI_SETUP')) {
  exit("Installation plugins must only be loaded by the installer.\n");
}

\Civi\Setup::dispatcher()
  ->addListener('civi.setup.init', function (\Civi\Setup\Event\InitEvent $e) {
    $e->getModel()->extensions[] = 'monolog';
  });

\Civi\Setup::dispatcher()
  ->addListener('civi.setup.installDatabase', function (\Civi\Setup\Event\InstallDatabaseEvent $e) {
    if (!in_array('monolog', $e->getModel()->extensions)) {
      // in case installing Monolog extension was overridden by user
      return;
    }

    \Civi\Setup::log()->info(sprintf('[%s] Handle %s', basename(__FILE__), 'installDatabase'));

    // disable monolog configs packaged in extension
    \Civi\Api4\Monolog::update(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addValue('is_active', FALSE)
      ->execute();

    // create new config to pass all to stdout
    \Civi\Api4\Monolog::create(FALSE)
      ->addValue('name', 'docker_std_out')
      ->addValue('description', 'Direct all logs to stdout so they go to the Docker log feed')
      ->addValue('minimum_severity', 'debug')
      ->addValue('type', 'std_out')
      ->addValue('channel', 'default')
      ->addValue('is_active', TRUE)
      ->addValue('is_default', TRUE)
      ->addValue('is_final', TRUE)
      ->addValue('weight', 0)
      ->addValue('configuration_options', [
        'cli_only' => FALSE,
      ])
      ->execute();

  }, \Civi\Setup::PRIORITY_LATE);
