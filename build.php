#!/usr/bin/env php
<?php

use function Clippy\clippy;
use function Clippy\plugins;

require_once __DIR__ . '/vendor/autoload.php';
$c = clippy()->register(plugins());

$c['app']->main('[--dry-run] [--step] [--image-prefix=] [--image-filter=] [--php-version=] [--civicrm-version=] [--download-url=] [--download-prefix=] [--builder=] [--platform=] [--skip-push]  [--no-cache]', function(
  Clippy\Taskr $taskr,
  $imagePrefix,
  $imageFilter,
  $phpVersion,
  $civicrmVersion,
  $downloadUrl,
  $downloadPrefix,
  $builder,
  $platform,
  $skipPush,
  $noCache
) {

  // Create an array of all potential build arguments
  $args = [];

  // CiviCRM version. Latest stable is tracked separately because the
  // unversioned tags alias it, whichever version this run builds.
  $latestVersion = trim(file_get_contents('https://latest.civicrm.org/stable.php'));
  $civiVersion =
    $args['CIVICRM_VERSION'] =
    $civicrmVersion ?: $latestVersion;

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

  $defaults = ['CIVICRM_VERSION' => $latestVersion, 'PHP_VERSION' => 'php8.4', 'WORDPRESS_VERSION' => $wpVersion];
  // The default image prefix is the official one.
  $imagePrefix ??= 'civicrm';

  // Make sure we have the latest base image before we get started.
  foreach ($phpVersions as $phpVersion) {
    $taskr->passthru('docker pull php:{{0}}-apache-bookworm', [
      $phpVersion,
    ]);
  }

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
        'PHP_VERSION',
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
        'CIVICRM_VERSION'
      ]
    ]
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
      $args['CIVICRM_DOWNLOAD_URL'] = getDownloadUrl($image, $downloadUrl, $downloadPrefix, $civiVersion);
      $parts = array_intersect_key(['CIVICRM_VERSION' => $civiVersion, 'PHP_VERSION' => 'php' . $phpVersion], array_flip($image['tags']));
      $buildArgs = getBuildArgs($args, $image);
      $tagFlags = getTagFlags("{$imagePrefix}/{$image['dir']}", $parts, $defaults);

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
function getDownloadUrl($image, $downloadUrl, $downloadPrefix, $civiVersion) {
  if (empty($image['download'])) {
    return NULL;
  }
  if ($downloadUrl) {
    return $downloadUrl;
  }
  if ($downloadPrefix) {
    return "{$downloadPrefix}civicrm-{$civiVersion}-{$image['download']}";
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

function getTagFlags($name, $parts, $defaults) {

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

  // Add Civi version aliases
  $isLatest = FALSE;
  if (isset($parts['CIVICRM_VERSION'])) {
    $versionParts = explode('.', $parts['CIVICRM_VERSION']);
    $major = $versionParts[0];
    $minor = "$versionParts[0].$versionParts[1]";
    $isLatest = ($parts['CIVICRM_VERSION'] === $defaults['CIVICRM_VERSION']);
  }

  foreach ($tags as $tag) {
    if (!empty($tag['CIVICRM_VERSION'])) {
      // The bare major alias tracks latest stable, so an older release must not
      // claim it. The minor alias is series-specific and always safe.
      if ($isLatest) {
        $tags[] = ['CIVICRM_VERSION' => $major] + $tag;
      }
      $tags[] = ['CIVICRM_VERSION' => $minor] + $tag;
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
