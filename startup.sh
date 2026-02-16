#!/bin/bash
# PerfSimPhp - Custom startup script for Azure App Service
# This script runs when the container starts on the PHP blessed image
#
# To duplicate for another PHP app: update paths and any
# language-specific initialization steps

echo "=== PerfSimPhp Startup Script ==="
echo "PHP Version: $(php -v | head -1)"
echo "Starting at: $(date)"

# Create storage directory for file-based SharedStorage fallback
# This is used when APCu is not available
STORAGE_DIR="/home/site/wwwroot/storage"
if [ ! -d "$STORAGE_DIR" ]; then
    mkdir -p "$STORAGE_DIR"
    echo "Created storage directory: $STORAGE_DIR"
fi
chmod 777 "$STORAGE_DIR"

# Copy custom Nginx configuration
# Note: Oryx copies our 'default' file to /etc/nginx/nginx.conf automatically
# via the NGINX_CONF_FILE app setting. No manual copy needed here.
# The startup.sh is passed as the startup command and Oryx appends php-fpm.

# Copy .user.ini to the document root (public/) where PHP-FPM reads it
USER_INI_SRC="/home/site/wwwroot/.user.ini"
USER_INI_DST="/home/site/wwwroot/public/.user.ini"
if [ -f "$USER_INI_SRC" ]; then
    cp "$USER_INI_SRC" "$USER_INI_DST"
    echo "PHP .user.ini copied to public directory"
fi

# Check if APCu extension is available
if php -m | grep -qi apcu; then
    echo "APCu extension: AVAILABLE (primary storage backend)"
else
    echo "APCu extension: NOT AVAILABLE (using file-based storage fallback)"
fi

# Check if exec() is available (needed for CPU stress workers)
if php -r "echo function_exists('exec') ? 'yes' : 'no';" | grep -q "yes"; then
    echo "exec() function: AVAILABLE (CPU stress workers enabled)"
else
    echo "exec() function: DISABLED (CPU stress workers will not work)"
fi

# Log PHP configuration
echo "=== PHP Configuration ==="
echo "memory_limit: $(php -r "echo ini_get('memory_limit');")"
echo "max_execution_time: $(php -r "echo ini_get('max_execution_time');")"
echo "display_errors: $(php -r "echo ini_get('display_errors');")"
echo "opcache.enable: $(php -r "echo ini_get('opcache.enable');")"
echo "========================="

echo "PerfSimPhp startup complete"
