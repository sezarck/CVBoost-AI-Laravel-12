#!/usr/bin/env bash
set -e

echo "Création et vérification des dossiers de stockage..."
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chmod -R 775 storage bootstrap/cache

echo "Running configuration and migrations..."
php artisan config:clear
php artisan migrate --force

echo "Starting Laravel server..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}