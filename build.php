#!/usr/bin/env php
<?php

use function Clippy\clippy;
use function Clippy\plugins;

require_once __DIR__ . '/vendor/autoload.php';
$c = clippy()->register(plugins());

$c['app']->main('[--dry-run] [--step] [--image-prefix=] [--image-filter=] [--php-version=] [--download-url=] [--builder=] [--platform=] [--skip-push]  [--no-cache]', function(
  Clippy\Taskr $taskr,
  $imagePrefix,
  $imageFilter,
  $phpVersion,
  $downloadUrl,
  $builder,
  $platform,
  $skipPush,
  $noCache
) {

  // Create an array of all potential build arguments
  $args = [];

  // CiviCRM version
  $civiVersion =
    $args['CIVICRM_VERSION'] =
    file_get_contents('https://latest.civicrm.org/stable.php');

  // If a PHP version is supplied build for that version, else build all
  // recommended PHP versions.
  if ($phpVersion) {
    $phpVersions = [$phpVersion];
  }
  else {
    $phpVersions = [
      '8.1',
      '8.2',
      '8.3',
      //'8.4'
    ];
  }

  $defaults = ['CIVICRM_VERSION' => $civiVersion, 'PHP_VERSION' => 'php8.3'];
  // The default image prefix is the official one.
  $imagePrefix ??= 'civicrm';

  // Make sure we have the latest base image before we get started.
  foreach ($phpVersions as $phpVersion) {
    $taskr->passthru('docker pull php:{{0}}-apache-bookworm', [
      $phpVersion,
    ]);
  }

  $args['IMAGE_PREFIX'] = $imagePrefix;

  if ($downloadUrl) {
    $args['CIVICRM_DOWNLOAD_URL'] = $downloadUrl;
  }

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
  if (isset($parts['CIVICRM_VERSION'])) {
    $versionParts = explode('.', $parts['CIVICRM_VERSION']);
    $major = $versionParts[0];
    $minor = "$versionParts[0].$versionParts[1]";
  }

  foreach ($tags as $tag) {
    if (!empty($tag['CIVICRM_VERSION'])) {
      $tags[] = ['CIVICRM_VERSION' => $major] + $tag;
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
