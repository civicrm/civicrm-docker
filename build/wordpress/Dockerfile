# Published image: civicrm/wordpress
#
# This is a ready to use CiviCRM on WordPress image.

ARG PHP_VERSION

ARG IMAGE_PREFIX=civicrm

FROM ${IMAGE_PREFIX}/wordpress-base:php${PHP_VERSION} AS build

# Specify a WordPress version

ARG WORDPRESS_VERSION

# Download WordPress

RUN php -d memory_limit=-1 /usr/local/bin/wp core download --version=${WORDPRESS_VERSION} --allow-root

# Create standard directories

RUN mkdir wp-content/languages wp-content/uploads

# Specify a CiviCRM version

ARG CIVICRM_VERSION

# Specify a tarball URL to download (overrides CIVICRM_VERSION)

ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-wordpress.zip"

# Download the CiviCRM tarball

RUN curl --fail --location ${CIVICRM_DOWNLOAD_URL} --output /tmp/civicrm-wordpress.zip \
  && unzip /tmp/civicrm-wordpress.zip -d /var/www/html/wp-content/plugins \
  && rm /tmp/civicrm-wordpress.zip

FROM ${IMAGE_PREFIX}/wordpress-base:php${PHP_VERSION}

# Copy the downloaded tarball to /var/www/html as the www-data user

COPY --from=build  --chown=www-data:www-data /var/www/html /var/www/html

# Add custom WordPress config file

COPY --chown=www-data:www-data wp-config.php /var/www/html

# Create private directory for config file

RUN mkdir /var/www/private && chown www-data:www-data /var/www/private

# Add an installation script

COPY civicrm-docker-install /usr/local/bin/

ENV WORDPRESS_CONFIG_FILE=/var/www/private/wp-config.php

# Report healthy while Apache and PHP serve the login page

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
  CMD curl -fsS -o /dev/null http://localhost:${APACHE_PORT}/wp-login.php || exit 1
