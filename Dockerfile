FROM php:8.3.36-apache

# Install system dependencies
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

# 🔥 Installa APCu per il caching di CodeIgniter (sessioni, cache, ecc.)
RUN pecl install apcu && docker-php-ext-enable apcu

# 🔥 Rimuovi Xdebug (non più installato)
# (Il comando pecl install xdebug è stato rimosso)

# Abilita mod_rewrite e mod_headers (utili per CI4)
RUN a2enmod rewrite headers expires

# Installa Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copia configurazioni
COPY conf/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY bin/entrypoint.sh /usr/local/bin/fairy-bus-entrypoint.sh
RUN chmod +x /usr/local/bin/fairy-bus-entrypoint.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/fairy-bus-entrypoint.sh"]
CMD ["apache2-foreground"]

