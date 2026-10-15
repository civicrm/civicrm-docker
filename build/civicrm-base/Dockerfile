# Published image: civicrm/civicrm-base
#
# This is a base image that serves as a foundation for the CiviCRM Standalone
# image. It is based on the civicrm-base and includes all dependencies required
# to run CiviCRM Standalone but does not include CiviCRM Standalone itself.
# 
# You may find this image useful as a base image for custom CiviCRM Standalone
# build processes.

ARG PHP_VERSION

ARG IMAGE_PREFIX=civicrm

FROM ${IMAGE_PREFIX}/common-base:php${PHP_VERSION}

# Set the CMS to Standalone (i.e. none)

ENV CIVICRM_UF=Standalone

# Add CiviCRM Standalone specific Apache configuration

COPY apache-conf-civicrm-standalone.conf /etc/apache2/conf-available/civicrm-standalone.conf

RUN a2enconf civicrm-standalone
