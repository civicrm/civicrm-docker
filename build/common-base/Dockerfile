# Published image: civicrm/civicrm-base
#
# This is a base image that serves as a foundation for other CiviCRM images. It
# includes all the core dependencies required to run CiviCRM but does not
# include CMS or Standalone specific dependencies.
#
# It is based on the official Docker php on apache images. It used the Debian
# variant and hardcodes a Debian version.
#
# There is no fpm variant at the moment though we may support that in the
# future. Please submit an issue if you want to support this effort.
#
# Images are generated for all supported minor releases of PHP that are
# recommended by CiviCRM.

ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-apache-bookworm

# Install required packages (including those required for PHP extensions).
# 
# Please document the reason for each installed package below.
#
# libicu is required for the PHP intl extension
# libzip-dev is required for the PHP zip extension
# libpng-dev is required for the PHP gd extension
# imagemagick, libmagickwand-dev are required for PHP imagick extension

RUN set -eux; \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    libicu-dev \
    libpng-dev \
    libzip-dev \
    imagemagick \
    libmagickwand-dev \
    && \
    rm -rf /var/lib/apt/lists/*

# Configure and install php extensions.
#
# Based on https://docs.civicrm.org/installation/en/latest/general/requirements/#php-extensions
#
# Tip: you can check what extensions have been installed with:
# `php -r 'echo implode("\n",get_loaded_extensions());'`.

# imagick is required for eg. Mosaico
RUN set -eux && \
    pecl install imagick && \
    docker-php-ext-enable imagick

RUN set -eux && \
    docker-php-ext-install bcmath && \
    docker-php-ext-install gd && \
    docker-php-ext-install intl && \
    docker-php-ext-install mysqli && \
    docker-php-ext-install zip

# Download cv (https://github.com/civicrm/cv)

RUN curl -LsS https://download.civicrm.org/cv/cv.phar -o /usr/local/bin/cv \
    && chmod +x /usr/local/bin/cv

# Enable apache rewrite module

RUN a2enmod rewrite remoteip

# Add CiviCRM specific Apache configuration 

COPY apache-conf-civicrm.conf /etc/apache2/conf-available/civicrm.conf

# Overwrite default virtual host file

COPY apache-sites-available-default.conf /etc/apache2/sites-available/000-default.conf

# Enable CiviCRM specific configuration

RUN a2enconf civicrm

# Add CiviCRM specific PHP configuration

COPY php-conf-civicrm.ini ${PHP_INI_DIR}/conf.d/civicrm.ini

RUN chown www-data:www-data /var/www
