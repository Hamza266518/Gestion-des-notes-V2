FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader

# Don't cache config — it would hardcode build-time env values
# Railway env vars are injected at runtime

EXPOSE 8080

CMD php -S 0.0.0.0:${PORT:-8080} -t public
