#!/usr/bin/env bash
# Installs WordPress and CiviCRM from an image and checks how Apache and PHP serve them.
# Usage: IMAGE=civicrm/wordpress:6.19-php8.5 tests/wordpress.sh
IMAGE="${IMAGE:-civicrm/wordpress:latest}"
PORT="${PORT:-8763}"
COMPOSE_FILE=compose.yaml:compose.wordpress.yaml
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

CIVICRM_FILES=/var/www/html/wp-content/uploads/civicrm

start_and_install
install_probe

# CiviCRM keeps its settings file and logs in wp-content/uploads/civicrm/: they may not be served.
run_in_app "echo test > $CIVICRM_FILES/ConfigAndLog/zz-test.log" || die "could not write to ConfigAndLog/"
run_in_app "test -f $CIVICRM_FILES/civicrm.settings.php" || die "civicrm.settings.php is missing"
expect_status wp-content/uploads/civicrm/ConfigAndLog/zz-test.log 403 "CiviCRM's ConfigAndLog/ is not served"
expect_status wp-content/uploads/civicrm/civicrm.settings.php 403 "civicrm.settings.php is not executed"
expect_no_php_in wp-content/uploads

# Front end, response headers and PHP settings.
expect_status sample-page/ 200 "pretty permalinks serve /sample-page/"
expect_status wp-content/plugins/civicrm/civicrm/js/Common.js 200 "static asset of the CiviCRM plugin is served"
check_version_headers wp-login.php
check_php_settings

# Log in to WordPress as admin. The first request sets WordPress's test cookie.
curl --silent --max-time 30 --cookie-jar "$COOKIES" --output /dev/null "$BASE/wp-login.php"
curl --silent --max-time 30 --cookie "$COOKIES" --cookie-jar "$COOKIES" --output /dev/null \
  --data-urlencode log=admin --data-urlencode pwd=password \
  --data-urlencode 'wp-submit=Log In' --data-urlencode testcookie=1 \
  "$BASE/wp-login.php"

# Load every page linked from CiviCRM's administer page, so that OPcache holds a realistic share of CiviCRM.
links="$(links_on 'wp-admin/admin.php?page=CiviCRM&q=civicrm/admin&reset=1' '/wp-admin/admin\.php\?page=CiviCRM')"
count="$(echo "$links" | grep -c .)"
if [ "$count" -gt 50 ]; then
  pass "admin session sees $count admin links"
else
  fail "admin session sees more than 50 admin links" "found $count"
fi
crawl "wp-admin/
wp-admin/admin.php?page=CiviCRM&q=civicrm/dashboard&reset=1
wp-admin/admin.php?page=CiviCRM&q=civicrm/contact/view&reset=1&cid=2
$links"
check_opcache_headroom

docker compose up --detach --force-recreate app >"$LOG" 2>&1 || die "recreating the app container failed"
wait_for wp-login.php
expect_status sample-page/ 200 "pretty permalinks still work after recreating the container"

finish
