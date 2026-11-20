# shellcheck shell=bash
# Shared helpers for tests/standalone.sh and tests/wordpress.sh.
# The calling script sets IMAGE, BUILD, PORT and COMPOSE_FILE before sourcing this file.

# No "set -e": a failing check must not stop the checks after it.
set -uo pipefail

cd "$(dirname "$0")" || exit 2
PHP_VERSION="${PHP_VERSION:-8.5}"
export IMAGE PORT COMPOSE_FILE
export COMPOSE_PROJECT_NAME="civicrm-docker-test-$PORT"
BASE="http://localhost:$PORT"
COOKIES="$(mktemp)"
LOG="$(mktemp)"
FAILURES=0
# Prefix for images built by build_image; per port, so that parallel runs do not remove each other's images.
BUILT_PREFIX=""

# Remove containers, volumes and temp files however the script ends.
cleanup() {
  docker compose down --volumes --remove-orphans >/dev/null 2>&1
  rm -f "$COOKIES" "$LOG"
  if [ -n "$BUILT_PREFIX" ]; then
    docker images --filter reference="$BUILT_PREFIX/*" --format '{{.Repository}}:{{.Tag}}' \
      | xargs docker image rm >/dev/null 2>&1
  fi
}
trap cleanup EXIT

# --- Reporting -------------------------------------------------------------

pass() {
  echo "ok   $1"
}

fail() {
  echo "FAIL $1"
  if [ -n "${2:-}" ]; then echo "     $2"; fi
  FAILURES=$((FAILURES + 1))
}

# Ends the test: exit code 1 if any check failed.
finish() {
  if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES checks failed"
    exit 1
  fi
  echo "all checks passed"
  exit 0
}

# Stops the test when setup fails, printing the output of the failed step.
die() {
  echo "ERROR $1"
  cat "$LOG"
  exit 2
}

# --- Running things --------------------------------------------------------

# Runs a shell command in the app container as the web server user.
run_in_app() {
  docker compose exec -T -u www-data app sh -c "$1"
}

# Prints the response body of a path, sending the login cookie.
get() {
  curl --silent --max-time 30 --cookie "$COOKIES" "$BASE/$1"
}

# Prints the HTTP status code of a path, or 000 if there was no response.
status_of() {
  curl --silent --max-time 30 --cookie "$COOKIES" --output /dev/null --write-out '%{http_code}' "$BASE/$1"
}

# Builds the images listed in BUILD (dependencies first) from this checkout; the last one is tested
# and all of them are removed again at the end.
build_image() {
  if [ ! -f ../vendor/autoload.php ]; then die "build.php needs 'composer install' first"; fi
  BUILT_PREFIX="$COMPOSE_PROJECT_NAME"
  ../build.php --skip-push --image-prefix="$BUILT_PREFIX" --php-version="$PHP_VERSION" \
    --image-filter="$BUILD" || die "build.php failed"
  IMAGE="$BUILT_PREFIX/${BUILD##*,}:php$PHP_VERSION"
}

start_and_install() {
  if [ -z "$IMAGE" ]; then build_image; fi
  echo "Image: $IMAGE"
  docker compose up --detach --quiet-pull >"$LOG" 2>&1 || die "docker compose up failed"
  docker compose exec -T -u www-data app civicrm-docker-install >"$LOG" 2>&1 || die "civicrm-docker-install failed"
}

# Waits up to a minute for a path to answer.
wait_for() {
  for _ in $(seq 30); do
    if [ "$(status_of "$1")" != 000 ]; then return; fi
    sleep 2
  done
  die "the site did not answer within a minute"
}

# Puts a PHP file into the docroot that reports settings as the web server sees them,
# one "name=value" per line.
install_probe() {
  run_in_app 'cat > /var/www/html/zz-test-probe.php' <<'PHP'
<?php
header('Content-Type: text/plain');
$opcache = function_exists('opcache_get_status') ? opcache_get_status(FALSE) : [];
$stats = $opcache['opcache_statistics'] ?? [];
$strings = $opcache['interned_strings_usage'] ?? [];
echo 'display_errors=', ini_get('display_errors') ? 'on' : 'off', "\n";
echo 'opcache=', empty($opcache['opcache_enabled']) ? 'off' : 'on', "\n";
echo 'opcache_full=', empty($opcache['cache_full']) ? 'no' : 'yes', "\n";
echo 'opcache_restarts=', ($stats['oom_restarts'] ?? 0) + ($stats['hash_restarts'] ?? 0), "\n";
echo 'interned_strings_free=', $strings['free_memory'] ?? 0, "\n";
echo 'interned_strings_size=', $strings['buffer_size'] ?? 0, "\n";
PHP
  if [ -z "$(probe opcache)" ]; then die "the PHP probe returned nothing"; fi
}

# Prints one value from the probe: "probe opcache" prints "on" or "off".
probe() {
  get zz-test-probe.php | grep "^$1=" | cut -d= -f2
}

# --- Checks ----------------------------------------------------------------

expect_status() {
  local path="$1" expected="$2" name="$3"
  local actual
  actual="$(status_of "$path")"
  if [ "$actual" = "$expected" ]; then
    pass "$name"
  else
    fail "$name" "GET /$path returned $actual, expected $expected"
  fi
}

expect_equal() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass "$name"
  else
    fail "$name" "got '$actual', expected '$expected'"
  fi
}

# Checks that a response header is present ("yes") or absent ("no").
expect_header() {
  local name="$1" headers="$2" header="$3" present="$4"
  if grep -qi "^$header" <<<"$headers"; then
    expect_equal "$name" yes "$present"
  else
    expect_equal "$name" no "$present"
  fi
}

# Writes PHP into a web-writable directory (given relative to the docroot) and checks that it
# does not run: neither as a .php file, nor as an image that a .htaccess hands to PHP.
# Run by PHP, the code prints 42; served as a plain file, it shows the source.
expect_no_php_in() {
  local dir="$1"
  local full="/var/www/html/$dir"
  run_in_app "echo '<?php echo 6*7;' > $full/zz-test.php" || die "could not write to $full"
  run_in_app "mkdir -p $full/zz-test"
  run_in_app "echo '<?php echo 6*7;' > $full/zz-test/image.jpg"
  run_in_app "echo 'SetHandler application/x-httpd-php' > $full/zz-test/.htaccess"

  expect_status "$dir/zz-test.php" 403 "PHP files in the writable $dir/ directory are not executed"
  if [ "$(get "$dir/zz-test/image.jpg")" = 42 ]; then
    fail "a .htaccess in $dir/ cannot make other files run as PHP"
  else
    pass "a .htaccess in $dir/ cannot make other files run as PHP"
  fi
}

check_version_headers() {
  local headers
  headers="$(curl --silent --head --max-time 30 "$BASE/$1")"
  if [ -z "$headers" ]; then die "no response headers from $BASE/$1"; fi
  expect_header "Server header hides the Apache version" "$headers" 'server: apache/' no
  expect_header "no X-Powered-By header" "$headers" 'x-powered-by:' no
}

check_php_settings() {
  expect_equal "display_errors is off" "$(probe display_errors)" off
  expect_equal "OPcache is enabled" "$(probe opcache)" on
}

# Prints the links on a page that start with a prefix, once each, leaving out
# links that would log out or delete something.
links_on() {
  local page="$1" prefix="$2"
  get "$page" \
    | grep -oE "href=\"${prefix}[^\"#]*" \
    | sed -e 's/^href="//' -e 's/&amp;/\&/g' -e 's/&#038;/\&/g' \
    | grep -viE 'logout|delete|disable|action=trash' \
    | sort -u
}

# Requests each path (one per line) with the login cookie. A path fails if it returns 4xx/5xx,
# gives no response, or redirects to a login page because the session was lost.
crawl() {
  local paths="$1"
  local path response code curl_exit redirect errors=0
  while read -r path; do
    if [ -z "$path" ]; then continue; fi
    path="${path#/}"
    response="$(curl --silent --max-time 30 --cookie "$COOKIES" --output /dev/null \
      --write-out '%{http_code} %{exitcode} %{redirect_url}' "$BASE/$path")"
    read -r code curl_exit redirect <<<"$response"
    if [ "$code" -ge 400 ] || [ "$code" = 000 ] || [[ "$redirect" == *login* ]]; then
      echo "     $path: HTTP $code, curl exit code $curl_exit, redirect '$redirect'"
      errors=$((errors + 1))
    fi
  done <<<"$paths"
  expect_equal "warm-up pages answer without errors" "$errors" 0
}

# After warm-up OPcache must still hold everything: a quarter of the interned strings
# buffer free, the cache not full, no restarts.
check_opcache_headroom() {
  local free size
  free="$(probe interned_strings_free)"
  size="$(probe interned_strings_size)"
  if [ "$free" -gt $((size / 4)) ]; then
    pass "interned strings buffer keeps a quarter free after warm-up ($free of $size bytes free)"
  else
    fail "interned strings buffer keeps a quarter free after warm-up" "$free of $size bytes free"
  fi
  expect_equal "OPcache is not full" "$(probe opcache_full)" no
  expect_equal "OPcache did not restart" "$(probe opcache_restarts)" 0
}
