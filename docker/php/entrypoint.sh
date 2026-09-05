#!/bin/sh
set -e

# ✅ Clear all legacy configurations safely
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled

# ✅ Discover packages dynamically now that the runtime environment is active
echo "Discovering Laravel packages..."
php artisan package:discover --ansi

# Optimize configurations for production using actual environment variables
echo "Caching Laravel configurations..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute the main container command (php-fpm)
exec "$@"
