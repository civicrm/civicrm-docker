# Published image: civicrm/civicrm-wordpress
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

# Use the default production PHP configuration

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php-cli.ini" 

# Set PHP memory limit

RUN echo 'memory_limit = ${PHP_MEMORY_LIMIT:-"256M"}\n'  >> $PHP_INI_DIR/php.ini

RUN echo 'memory_limit = -1\n' >> $PHP_INI_DIR/php-cli.ini

# Copy the downloaded tarball to /var/www/html as the www-data user

COPY --from=build  --chown=www-data:www-data /var/www/html /var/www/html

# Add custom WordPress config file

COPY --chown=www-data:www-data wp-config.php /var/www/html

# Add our own entrypoint

COPY civicrm-docker-entrypoint /usr/local/bin/

# Add an installation script

COPY civicrm-docker-install /usr/local/bin/

ENV CIVICRM_UF=WordPress

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
