# WordPress

The WordPress + CiviCRM images provide CiviCRM running as a WordPress plugin.

If you are looking for a **ready to use** WordPress + CiviCRM application, use `civicrm/wordpress`. If you are building a custom image, use `civicrm/wordpress-base`.

## Quick start

**Note**: These instructions are for testing purposes, not production deployment.

### Running the image

```shell
docker run --detach --publish 8000:80 civicrm/wordpress
```

You must complete the installation process (see below) before WordPress and CiviCRM are usable.

### With docker compose

A complete example is in the [`example/wordpress`](example/wordpress) directory.

1. Clone this repository: `git clone https://github.com/civicrm/civicrm-docker`
2. Change directory: `cd civicrm-docker/example/wordpress`
3. Copy `.env-example` file to `.env` and add the required environment variables (see below)
4. Start containers: `docker compose up -d`
5. Wait for database initialization: `docker compose logs -f db` (wait for "ready for connections")
6. Install WordPress and CiviCRM: `docker compose exec -u www-data app civicrm-docker-install`
7. Visit http://localhost:8760/wp-login.php and log in with credentials `CIVICRM_ADMIN_USER` and `CIVICRM_ADMIN_PASS` from `.env`
8. When finished: `docker compose down`

### Using WP-CLI

The image includes the WP-CLI. Make sure that you run this as the `www-data` user rather than `root`. For example:

```shell
docker compose exec -u www-data app wp plugin list
```

## Development

For local CiviCRM development, use the compose override to bind-mount your local code:

```shell
docker compose -f compose.yaml -f compose.dev.yaml up -d
```

This overrides the image's CiviCRM plugin with your local copy — changes appear immediately in the container. See [`example/wordpress/compose.dev.yaml`](example/wordpress/compose.dev.yaml) for details.

## Environment variables

The following environment variables should be set in either the `compose.yaml` file or `.env`.

**CiviCRM database**:

- `CIVICRM_DB_HOST` - Database host (e.g. `db`)
- `CIVICRM_DB_PORT` - Database port
- `CIVICRM_DB_NAME` - Database name
- `CIVICRM_DB_USER` - Database user
- `CIVICRM_DB_PASSWORD` - Database password
- OR `CIVICRM_DSN` (e.g. `mysql://user:pass@host:3306/database`)

**WordPress database**:

- `WORDPRESS_DB_HOST` - Database host (e.g. `db`)
- `WORDPRESS_DB_NAME` - Database name (can be the same as CiviCRM database)
- `WORDPRESS_DB_USER` - Database user (typically the same as CiviCRM database user)
- `WORDPRESS_DB_PASSWORD` - Database password

**Site configuration**:

- `CIVICRM_UF_BASEURL` - Site URL (e.g. `http://localhost:8760`)
- `WORDPRESS_CONFIG_FILE` - Path to `wp-config.php` (default: `/var/www/private/wp-config.php`)
- `WORDPRESS_SITE_TITLE` - WordPress site title

**User credentials**:

- `CIVICRM_ADMIN_USER` - Admin username
- `CIVICRM_ADMIN_PASS` - Admin password
- `CIVICRM_ADMIN_EMAIL` - Admin email

**Optional**:

- `APACHE_PORT` - Override Apache port inside container (default: 80)
- `PHP_MEMORY_LIMIT` - PHP memory limit (default: 256M)

## Installation

The `civicrm/wordpress` image includes a `civicrm-docker-install` script that:

1. Creates a `wp-config.php` file at `WORDPRESS_CONFIG_FILE`
2. Installs WordPress core
3. Installs CiviCRM

**Prerequisites**: All environment variables must be set (see above).

```shell
docker compose exec -u www-data app civicrm-docker-install
```

**Important**: Run as `www-data` user to ensure correct file permissions.

See [/build/wordpress/civicrm-docker-install](build/wordpress/civicrm-docker-install) for details.

## Volumes

The following volumes should be persisted:

```yaml
volumes:
  - wpcontent:/var/www/html/wp-content  # Plugins, themes, and uploads
  - private:/var/www/private            # WordPress config
```

On first run, Docker automatically populates the `wpcontent` volume with the image contents (including CiviCRM). Plugins and themes installed via WordPress admin are persisted across container restarts.

## Tags

WordPress images use the same tagging strategy as CiviCRM Standalone:

- `civicrm/wordpress:latest` - Latest stable CiviCRM + recommended PHP
- `civicrm/wordpress:6` - Latest CiviCRM 6.x + recommended PHP
- `civicrm/wordpress:6.0` - Latest CiviCRM 6.0.x + recommended PHP
- `civicrm/wordpress:6.0-php8.3` - CiviCRM 6.0.x with PHP 8.3
- `civicrm/wordpress:php8.3` - Latest stable CiviCRM with PHP 8.3

These images always have the latest version of WordPress unless you create a custom build.

### Custom builds

For custom WordPress + CiviCRM images, extend `civicrm/wordpress-base`:

```Dockerfile
FROM civicrm/wordpress-base:php8.3

# Install additional WordPress plugins
RUN wp plugin install custom-plugin --activate --allow-root

# Download specific CiviCRM version
ARG CIVICRM_VERSION=6.0.3
RUN curl -L https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-wordpress.zip \
  -o /tmp/civicrm.zip && \
  unzip /tmp/civicrm.zip -d /var/www/html/wp-content/plugins && \
  rm /tmp/civicrm.zip
```
