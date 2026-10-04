FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    unzip \
    git \
    vim \
    nano \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    intl \
    mysqli \
    pdo_mysql \
    zip \
    gd \
    mbstring \
    opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN { \
    echo 'zend_extension=xdebug.so'; \
    echo 'xdebug.mode=debug'; \
    echo 'xdebug.start_with_request=yes'; \
    echo 'xdebug.client_host=host.docker.internal'; \
    echo 'xdebug.client_port=9003'; \
    echo 'xdebug.idekey=FAIRYBUS'; \
    echo 'xdebug.log_level=0'; \
} > /usr/local/etc/php/conf.d/99-xdebug.ini

RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/fairy-bus-entrypoint.sh
RUN chmod +x /usr/local/bin/fairy-bus-entrypoint.sh

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/fairy-bus-entrypoint.sh"]
CMD ["apache2-foreground"]
