FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        socat \
        ca-certificates \
    && docker-php-ext-install curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/config /opt/ros7-monitor \
    && chown -R www-data:www-data /var/www/html /opt/ros7-monitor

EXPOSE 80
