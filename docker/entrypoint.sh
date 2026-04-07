#!/bin/bash
# docker/entrypoint.sh

# Set proper permissions
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Run database migrations (if DATABASE_URL is set)
if [ ! -z "$DATABASE_URL" ]; then
    echo "Running database migrations..."
    php artisan migrate --force
fi

# Clear and cache config in production
if [ "$APP_ENV" = "production" ]; then
    echo "Caching Laravel configurations..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Start supervisord
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf