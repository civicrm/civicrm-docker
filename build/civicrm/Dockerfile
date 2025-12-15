# Published image: civicrm/civicrm
#
# This is a ready to use CiviCRM standalone image.

ARG PHP_VERSION=8.3

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

# Use the default production PHP configuration

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php-cli.ini" 

# Set PHP memory limit

RUN echo 'memory_limit = ${PHP_MEMORY_LIMIT:-"256M"}\n'  >> $PHP_INI_DIR/php.ini

RUN echo 'memory_limit = -1\n' >> $PHP_INI_DIR/php-cli.ini

# Copy the downloaded tarball to /var/www/html as the www-data user

COPY --from=build  --chown=www-data:www-data /var/www/html/civicrm-standalone /var/www/html

# Add our own entrypoint

COPY civicrm-docker-entrypoint /usr/local/bin/

# Add an installation script

COPY civicrm-docker-install /usr/local/bin/

ENV CIVICRM_UF=Standalone

# Allow Apache port to be configurable

RUN sed -i 's/80/${APACHE_PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

ENV APACHE_PORT=80

# Set ENTRYPOINT

ENTRYPOINT ["civicrm-docker-entrypoint"]

# Set CMD
#
# Necessary because we redefined CMD. See
# https://docs.docker.com/reference/dockerfile/#understand-how-cmd-and-entrypoint-interact

CMD ["apache2-foreground"]
