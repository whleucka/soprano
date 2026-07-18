FROM php:8.4-fpm

# Build argument to determine dev or prod mode
ARG BUILD_MODE=dev

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    mariadb-client \
    ffmpeg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
       pdo \
       pdo_mysql \
       mysqli \
       zip \
       mbstring \
       exif \
       intl \
       gd \
       bcmath \
       opcache \
    && docker-php-ext-enable pdo_mysql

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Essentia (audio feature extraction for auto-playlist stations) lives in its
# own venv; jobs find it via ESSENTIA_PYTHON (defaults to this path).
RUN apt-get update && apt-get install -y python3 python3-venv \
    && python3 -m venv /opt/essentia \
    && /opt/essentia/bin/pip install --no-cache-dir essentia \
    && rm -rf /var/lib/apt/lists/*

# Install Xdebug and PCOV only in dev mode
RUN if [ "$BUILD_MODE" = "dev" ]; then \
        pecl install xdebug && docker-php-ext-enable xdebug && \
        pecl install pcov && docker-php-ext-enable pcov; \
    fi

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP-FPM pool config
COPY ./fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# OPcache config (loaded first)
COPY ./opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Environment-specific PHP config will be mounted at runtime via docker-compose
# (dev.ini or prod.ini as /usr/local/etc/php/conf.d/zz-env.ini)
