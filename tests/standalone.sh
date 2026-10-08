#!/usr/bin/env bash
# Installs CiviCRM Standalone from an image and checks how Apache and PHP serve it.
# Usage: IMAGE=civicrm/civicrm:6.19-php8.5 tests/standalone.sh
IMAGE="${IMAGE:-civicrm/civicrm:latest}"
PORT="${PORT:-8762}"
COMPOSE_FILE=compose.yaml
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

start_and_install
install_probe

# private/ holds the settings file, logs and uploads: nothing in it may be served.
run_in_app 'echo test > /var/www/html/private/zz-test.txt' || die "could not write to private/"
run_in_app 'test -f /var/www/html/private/civicrm.settings.php' || die "private/civicrm.settings.php is missing"
expect_status private/zz-test.txt 403 "files in private/ are not served"
expect_status private/civicrm.settings.php 403 "private/civicrm.settings.php is not executed"
expect_no_php_in public

# Front controller, static assets and response headers.
expect_status civicrm/login 200 "front controller serves /civicrm/login"
expect_status core/js/Common.js 200 "static asset core/js/Common.js is served"
headers="$(curl --silent --head --max-time 30 "$BASE/core/js/Common.js")"
expect_header "static assets carry an Expires header (.htaccess ExpiresDefault)" "$headers" 'expires:' yes
expect_header "responses carry X-Content-Type-Options (.htaccess Header)" "$headers" 'x-content-type-options: nosniff' yes
check_version_headers civicrm/login
check_php_settings

# Log in as admin.
curl --silent --max-time 30 --cookie-jar "$COOKIES" --output /dev/null "$BASE/civicrm/login"
curl --silent --max-time 30 --cookie "$COOKIES" --cookie-jar "$COOKIES" --output /dev/null \
  --header 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode 'params={"identifier":"admin","password":"password"}' \
  "$BASE/civicrm/ajax/api4/User/login"

# Load every page linked from the administer page, so that OPcache holds a realistic share of CiviCRM.
links="$(links_on 'civicrm/admin?reset=1' /civicrm/)"
count="$(echo "$links" | grep -c .)"
if [ "$count" -gt 50 ]; then
  pass "admin session sees $count admin links"
else
  fail "admin session sees more than 50 admin links" "found $count"
fi
crawl "civicrm/dashboard
civicrm/contact/view?reset=1&cid=2
civicrm/contact/add?reset=1&ct=Individual
civicrm/a/
$links"
check_opcache_headroom

finish
