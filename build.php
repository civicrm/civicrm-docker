#!/usr/bin/env php
<?php

use function Clippy\clippy;
use function Clippy\plugins;

require_once __DIR__ . '/vendor/autoload.php';
$c = clippy()->register(plugins());

/**
 * Minimum patched PHP releases for CERT-EU 2026-018 (CVE-2026-109618, CVE-2026-109619).
 *
 * @see https://cert.europa.eu/publications/security-advisories/2026-018
 * @see SECURITY.md
 */
const PHP_MINIMUM_PATCH_VERSIONS = [
  '8.2' => '8.2.35',
  '8.3' => '8.3.36',
  '8.4' => '8.4.27',
  '8.5' => '8.5.12',
];

function getPhpVersionFromDockerImage(string $imageRef): ?string {
  $reported = trim((string) shell_exec(
    'docker run --rm ' . escapeshellarg($imageRef) . ' php -r ' . escapeshellarg('echo PHP_VERSION;')
  ));
  return $reported === '' ? NULL : $reported;
}

/**
 * Choose the official php Apache image tag to build from.
 *
 * Prefer the rolling minor tag (e.g. 8.3-apache-trixie). When Docker Library has
 * not refreshed that tag after a security release, use the explicit patch tag from
 * PHP_MINIMUM_PATCH_VERSIONS (e.g. 8.3.36-apache-trixie).
 */
function resolvePhpApacheImageTag(string $minor, string $debianVersion, bool $skipPatchFloor): string {
  $rolling = "{$minor}-apache-{$debianVersion}";
  if ($skipPatchFloor || !isset(PHP_MINIMUM_PATCH_VERSIONS[$minor])) {
    return $rolling;
  }

  $required = PHP_MINIMUM_PATCH_VERSIONS[$minor];
  $rollingVersion = getPhpVersionFromDockerImage("php:{$rolling}");
  if ($rollingVersion !== NULL && version_compare($rollingVersion, $required, '>=')) {
    return $rolling;
  }

  $pinned = "{$required}-apache-{$debianVersion}";
  $pinnedVersion = getPhpVersionFromDockerImage("php:{$pinned}");
  if ($pinnedVersion !== NULL && version_compare($pinnedVersion, $required, '>=')) {
    fwrite(STDERR, "note: building from php:{$pinned} because php:{$rolling}"
      . ($rollingVersion !== NULL ? " is still PHP {$rollingVersion}" : ' is unavailable')
      . " (< {$required}).\n");
    return $pinned;
  }

  return $rolling;
}

function assertPhpPatchVersions(array $phpVersions, array $phpImageTags, bool $skipCheck, bool $skipPush): void {
  if ($skipCheck) {
    return;
  }

  $unpatched = [];
  foreach ($phpVersions as $minor) {
    if (!isset(PHP_MINIMUM_PATCH_VERSIONS[$minor])) {
      continue;
    }
    $required = PHP_MINIMUM_PATCH_VERSIONS[$minor];
    $imageTag = $phpImageTags[$minor] ?? "{$minor}-apache-trixie";
    $image = "php:{$imageTag}";
    $reported = getPhpVersionFromDockerImage($image);
    if ($reported === NULL || version_compare($reported, $required, '<')) {
      $unpatched[] = "{$image} reports PHP {$reported}; need >= {$required}";
    }
  }

  if ($unpatched === []) {
    return;
  }

  $message = "PHP base image(s) are below patched versions for CERT-EU 2026-018:\n"
    . implode("\n", array_map(fn($line) => "  - {$line}", $unpatched))
    . "\nSee SECURITY.md. Update PHP_MINIMUM_PATCH_VERSIONS in build.php when new security patches ship,"
    . " re-pull from Docker Hub, or pass --skip-php-patch-check.\n";

  if (!$skipPush) {
    fwrite(STDERR, $message);
    exit(1);
  }

  fwrite(STDERR, "warning: {$message}");
}

$c['app']->main('[--dry-run] [--step] [--image-prefix=] [--image-filter=] [--php-version=] [--civicrm-version=] [--download-url=] [--download-prefix=] [--builder=] [--platform=] [--skip-push] [--skip-pull] [--skip-php-patch-check] [--no-cache]', function(
  Clippy\Taskr $taskr,
  $imagePrefix,
  $imageFilter,
  $phpVersion,
  $civicrmVersion,
  $downloadUrl,
  $downloadPrefix,
  $builder,
  $platform,
  $skipPull,
  $skipPush,
  $skipPhpPatchCheck,
  $noCache
) {

  // Create an array of all potential build arguments
  $args = [];

  // Debian version
  $debianVersion = 'trixie';

  // Build the CiviCRM version supplied or the latest
  $latestCivicrmVersion = trim(file_get_contents('https://latest.civicrm.org/stable.php'));
  $buildCivicrmVersion =
    $args['CIVICRM_VERSION'] =
    $civicrmVersion ?: $latestCivicrmVersion;

  // If PHP versions are supplied build for those, else build all
  // recommended PHP versions.
  if ($phpVersion) {
    $phpVersions = array_map('trim', explode(',', $phpVersion));
  }
  else {
    $phpVersions = [
      '8.2',
      '8.3',
      '8.4',
      '8.5',
    ];
  }

  // get current version of wordpress from api
  $wpVersion = unserialize(file_get_contents("https://api.wordpress.org/core/version-check/1.6/"))['offers'][0]['current'];
  $args['WORDPRESS_VERSION'] = $wpVersion;

  $defaults = ['CIVICRM_VERSION' => $latestCivicrmVersion, 'PHP_VERSION' => 'php8.5', 'WORDPRESS_VERSION' => $wpVersion];
  // The default image prefix is the official one.
  $imagePrefix ??= 'civicrm';

  $phpImageTags = [];
  foreach ($phpVersions as $phpVersion) {
    $phpImageTags[$phpVersion] = resolvePhpApacheImageTag(
      $phpVersion,
      $debianVersion,
      (bool) $skipPhpPatchCheck
    );
  }

  // Make sure we have the latest base image before we get started.
  if (!$skipPull) {
    foreach ($phpImageTags as $imageTag) {
      $taskr->passthru('docker pull php:{{0}}', [$imageTag]);
    }
  }

  assertPhpPatchVersions($phpVersions, $phpImageTags, (bool) $skipPhpPatchCheck, (bool) $skipPush);

  $args['IMAGE_PREFIX'] = $imagePrefix;

  // Some extra flags to pass to the build command.
  $extraFlags = [];
  if ($noCache) {
    $extraFlags[] = '--no-cache';
  }

  if (!$skipPush) {
    $extraFlags[] = '--push';
  }

  if ($platform) {
    $extraFlags[] = '--platform ' . $platform;
  }

  if ($builder) {
    $extraFlags[] = '--builder ' . $builder;
  }

  // This associative array defines all the images that we build.
  // - 'dir' is a directory in `build` that contains a Docker context
  // - 'args' specifies the build args that are valid for this image
  // - 'download' is the CiviCRM archive flavour this image downloads, if any
  $images = [
    [
      'dir' => 'common-base',
      'args' => [
        'IMAGE_TAG',
      ],
      'tags' => [
        'PHP_VERSION',
      ],
    ],
    [
      'dir' => 'civicrm-base',
      'args' => [
        'PHP_VERSION',
        'IMAGE_PREFIX',
      ],
      'tags' => [
        'PHP_VERSION',
      ],
    ],
    [
      'dir' => 'civicrm',
      'download' => 'standalone.tar.gz',
      'args' => [
        'PHP_VERSION',
        'IMAGE_PREFIX',
        'CIVICRM_VERSION',
        'CIVICRM_DOWNLOAD_URL',
      ],
      'tags' => [
        'PHP_VERSION',
        'CIVICRM_VERSION',
      ],
    ],
    [
      'dir' => 'wordpress-base',
      'args' => [
        'PHP_VERSION',
        'IMAGE_PREFIX',
      ],
      'tags' => [
        'PHP_VERSION',
      ],
    ],
    [
      'dir' => 'wordpress',
      'download' => 'wordpress.zip',
      'args' => [
        'PHP_VERSION',
        'IMAGE_PREFIX',
        'WORDPRESS_VERSION',
        'CIVICRM_VERSION',
        'CIVICRM_DOWNLOAD_URL',
      ],
      'tags' => [
        'PHP_VERSION',
        'CIVICRM_VERSION',
      ],
    ],
  ];

  if ($imageFilter) {
    $filteredImages = [];
    $imageFilters = explode(',', $imageFilter);
    foreach ($images as $k => $image) {
      if (in_array($image['dir'], $imageFilters)) {
        $filteredImages[] = $image;
      }
    }
    $images = $filteredImages;
  }

  // Build each image.
  foreach ($images as $image) {
    foreach ($phpVersions as $phpVersion) {

      $args['PHP_VERSION'] = $phpVersion;
      $args['IMAGE_TAG'] = $phpImageTags[$phpVersion];
      $args['CIVICRM_DOWNLOAD_URL'] = getDownloadUrl($image, $downloadUrl, $downloadPrefix, $buildCivicrmVersion);
      $parts = array_intersect_key(['CIVICRM_VERSION' => $buildCivicrmVersion, 'PHP_VERSION' => 'php' . $phpVersion], array_flip($image['tags']));
      $buildArgs = getBuildArgs($args, $image);
      $tagFlags = getTagFlags("{$imagePrefix}/{$image['dir']}", $parts, $defaults, empty($civicrmVersion));

      $taskr->passthru('docker build ' . __DIR__ . '/' . 'build/{{0}} {{1|@}} {{2|@}} {{3|@}}', [
        $image['dir'],
        $buildArgs,
        $tagFlags,
        $extraFlags,
      ]);

    }
  }
});

/**
 * Each image downloads its own archive flavour, so a single URL cannot serve
 * both the standalone and WordPress builds. --download-prefix replaces just the
 * leading `https://download.civicrm.org/` of the Dockerfile's own default.
 *
 * Returns NULL to leave CIVICRM_DOWNLOAD_URL unset, so the Dockerfile default applies.
 */
function getDownloadUrl($image, $downloadUrl, $downloadPrefix, $buildCivicrmVersion) {
  if (empty($image['download'])) {
    return NULL;
  }
  if ($downloadUrl) {
    return $downloadUrl;
  }
  if ($downloadPrefix) {
    return "{$downloadPrefix}civicrm-{$buildCivicrmVersion}-{$image['download']}";
  }
  return NULL;
}

function getBuildArgs($args, $image) {
  $buildArgs = array_map(
    fn($e) => isset($args[$e])
      ? "--build-arg {$e}={$args[$e]}"
      : NULL, $image['args']
    );
  return array_filter($buildArgs);
}

function getTagFlags($name, $parts, $defaults, $isLatest) {

  // The first 'definitive' tag.
  $tags = [$parts];

  // Add defaults
  foreach ($parts as $key => $value) {
    foreach ($tags as $tag) {
      if ($defaults[$key] == $value) {
        $tags[] = [$key => FALSE] + $tag;
      }
    }
  }

  // Add CiviCRM version aliases if we are building the latest version of CiviCRM
  if (($isLatest) && isset($parts['CIVICRM_VERSION'])) {
    $versionParts = explode('.', $parts['CIVICRM_VERSION']);
    $major = $versionParts[0];
    $minor = "$versionParts[0].$versionParts[1]";

    foreach ($tags as $tag) {
      if (!empty($tag['CIVICRM_VERSION'])) {
        $tags[] = ['CIVICRM_VERSION' => $major] + $tag;
        $tags[] = ['CIVICRM_VERSION' => $minor] + $tag;
      }
    }
  }

  // Format into tag flags
  $tagFlags = [];
  foreach ($tags as $tag) {
    $tagFlag = implode('-', array_filter($tag));
    $tagFlag = !empty($tagFlag) ? $tagFlag : 'latest';
    $tagFlags[] = "-t {$name}:{$tagFlag}";
  }
  return $tagFlags;
}
