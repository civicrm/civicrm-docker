<?php

/**
 * Functions shared by the civicrm-docker-install scripts.
 */

/**
 * Get a required environment variable, exiting if it is not set.
 */
function requireEnv(string $name, string $hint = ''): string {
  $value = getenv($name);
  if ($value === FALSE || $value === '') {
    fwrite(STDERR, "civicrm-docker-install: {$name} is not set{$hint}\n");
    exit(1);
  }
  return $value;
}

/**
 * Run a command without going through a shell, exiting if it fails.
 */
function run(array $command): void {
  $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
  $exitCode = proc_close($process);
  if ($exitCode !== 0) {
    exit($exitCode);
  }
}

/**
 * Get the CiviCRM database DSN.
 *
 * CIVICRM_DSN is used as given, so its credentials must already be encoded.
 * Otherwise the DSN is built from the CIVICRM_DB_* variables, which are
 * encoded with rawurlencode() because CiviCRM decodes DSN credentials with
 * rawurldecode(): urlencode() would turn a space into a '+' that is then read
 * back literally.
 */
function getDsn(): string {
  if (getenv('CIVICRM_DSN')) {
    return getenv('CIVICRM_DSN');
  }
  $hint = ' (set it, or supply a complete CIVICRM_DSN)';
  return sprintf('mysql://%s:%s@%s:%s/%s',
    rawurlencode(requireEnv('CIVICRM_DB_USER', $hint)),
    rawurlencode((string) getenv('CIVICRM_DB_PASSWORD')),
    requireEnv('CIVICRM_DB_HOST', $hint),
    getenv('CIVICRM_DB_PORT') ?: '3306',
    rawurlencode(requireEnv('CIVICRM_DB_NAME', $hint))
  );
}
