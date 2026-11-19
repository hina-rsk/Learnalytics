#!/bin/bash
set -e

# Create upload directories if they don't exist
mkdir -p /var/www/html/uploads/assignments
mkdir -p /var/www/html/uploads/lessons
mkdir -p /var/www/html/uploads/student_profiles
mkdir -p /var/www/html/uploads/submissions

# Set permissions
chown -R www-data:www-data /var/www/html/uploads
chmod -R 755 /var/www/html/uploads

# Start PHP-FPM in background
php-fpm -D

# Start Nginx in foreground (keeps container running)
nginx -g "daemon off;"
