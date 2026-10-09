# Published image: civicrm/civicrm
#
# This is a ready to use CiviCRM standalone image.

ARG PHP_VERSION

ARG IMAGE_PREFIX=civicrm

FROM ${IMAGE_PREFIX}/civicrm-base:php${PHP_VERSION} AS build

# Specify a CiviCRM version

ARG CIVICRM_VERSION

# Specify a tarball URL to download (overrides CIVICRM_VERSION)

ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-standalone.tar.gz"

# Download the tarball

RUN curl --fail --location ${CIVICRM_DOWNLOAD_URL} --output civicrm-standalone.tar.gz \
  && tar -xf civicrm-standalone.tar.gz \
  && rm civicrm-standalone.tar.gz

FROM ${IMAGE_PREFIX}/civicrm-base:php${PHP_VERSION}

# Copy the downloaded tarball to /var/www/html as the www-data user

COPY --from=build  --chown=www-data:www-data /var/www/html/civicrm-standalone /var/www/html

# Add an installation script

COPY civicrm-docker-install /usr/local/bin/

# Report healthy while Apache and PHP serve the login page

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
  CMD curl -fsS -o /dev/null http://localhost:${APACHE_PORT}/civicrm/login || exit 1
