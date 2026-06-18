FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions for MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Datadog PHP tracer
RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php \
 && php datadog-setup.php --php-bin=all \
 && rm datadog-setup.php

RUN a2enmod rewrite

COPY .htaccess      /var/www/html/.htaccess
COPY index.php      /var/www/html/index.php
COPY src/           /var/www/html/src/

# Route Apache logs to stdout/stderr for container log collection
RUN ln -sf /proc/1/fd/1 /var/log/apache2/access.log \
 && ln -sf /proc/1/fd/2 /var/log/apache2/error.log

EXPOSE 80
