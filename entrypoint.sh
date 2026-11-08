#!/usr/bin/env bash
set -e

echo "Running configuration and migrations..."
php artisan config:clear
php artisan migrate --force

echo "Starting Laravel server..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}