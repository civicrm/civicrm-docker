# Published image: civicrm/civicrm-standalone (aliased to civicrm/civicrm)
#
# This is a ready to use CiviCRM standalone image.

ARG PHP_VERSION=8.3

ARG IMAGE_PREFIX=civicrm

FROM ${IMAGE_PREFIX}/civicrm-base:php${PHP_VERSION} AS build

# Specify a CiviCRM version

ARG CIVICRM_VERSION

# Specify a tarball URL to download (overrides CIVICRM_VERSION)

ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-standalone.tar.gz"

# Download the tarball as the admin user

USER admin

RUN curl --fail --location ${CIVICRM_DOWNLOAD_URL} --output civicrm-standalone.tar.gz \
    && tar -xf civicrm-standalone.tar.gz \
    && rm civicrm-standalone.tar.gz

FROM ${IMAGE_PREFIX}/civicrm-base:php${PHP_VERSION}

# Copy the downloaded tarball to /var/www/html as the admin user

USER admin

COPY --from=build /var/www/html/civicrm-standalone /var/www/html

# Add our own entrypoint

COPY docker-civicrm-entrypoint /usr/local/bin/

# Add an installation script

COPY docker-civicrm-install /usr/local/bin/

# Redirect logs to /dev/stderr

ENV CIVICRM_LOG_HASH=0

ENV CIVICRM_UF=Standalone

# Point the settings file to civicrm.standalone.php
#
# This may not be necessary in the future.

ENV CIVICRM_SETTINGS_PATH=/var/www/html/civicrm.standalone.php


RUN mkdir /var/www/html/private/log && ln -sfT /dev/stderr /var/www/html/private/log/CiviCRM.1.log

# Set ENTRYPOINT

ENTRYPOINT ["docker-civicrm-entrypoint"]

# Set CMD
#
# Necessary because we redefined CMD. See
# https://docs.docker.com/reference/dockerfile/#understand-how-cmd-and-entrypoint-interact

CMD ["apache2-foreground"]
