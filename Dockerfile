FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        openssh-client \
        socat \
        ca-certificates \
    && docker-php-ext-install curl \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && printf '<LocationMatch "^/(config|runtime)(/|$)">\n    Require all denied\n</LocationMatch>\n' > /etc/apache2/conf-available/app-security.conf \
    && a2enconf app-security \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/config /opt/ros7-monitor \
    && chown -R www-data:www-data /var/www/html /opt/ros7-monitor

EXPOSE 80
