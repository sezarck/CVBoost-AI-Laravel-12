FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libzip-dev \
    libpng-dev \
    zip \
    && docker-php-ext-install pdo pdo_pgsql mbstring xml curl zip bcmath gd opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Installer les dépendances sans exécuter les scripts automatiques (--no-scripts)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# Assurer les permissions sur le stockage
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 10000

# Exécuter les scripts, migrations et démarrage au runtime (avec les variables d'environnement)
CMD php artisan package:discover --ansi && php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}