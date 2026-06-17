FROM php:8.3-apache

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# Install Datadog PHP tracer
# The tracer sends spans to DD_TRACE_AGENT_URL (default: localhost:8126)
# In Azure App Service, the Datadog sidecar (serverless-init) listens on localhost:8126
RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php \
 && php datadog-setup.php --php-bin=all \
 && rm datadog-setup.php

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application
COPY .htaccess /var/www/html/.htaccess
COPY index.php /var/www/html/index.php

# Apache: send logs to stdout/stderr so Azure App Service (and Datadog) can collect them
RUN ln -sf /proc/1/fd/1 /var/log/apache2/access.log \
 && ln -sf /proc/1/fd/2 /var/log/apache2/error.log

EXPOSE 80
