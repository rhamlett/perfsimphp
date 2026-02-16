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
# Azure PHP 8.x blessed image uses /etc/nginx/conf.d/default.conf
NGINX_CONF="/home/site/wwwroot/default"
if [ -f "$NGINX_CONF" ]; then
    # Detect actual PHP-FPM socket if it exists (override TCP default)
    FPM_SOCK=$(find /run /var/run -name "*.sock" 2>/dev/null | grep -i php | head -1)
    if [ -n "$FPM_SOCK" ]; then
        echo "Found PHP-FPM socket at: $FPM_SOCK"
        sed "s|fastcgi_pass 127.0.0.1:9000;|fastcgi_pass unix:$FPM_SOCK;|" "$NGINX_CONF" > /tmp/nginx_custom.conf
        NGINX_CONF=/tmp/nginx_custom.conf
    fi
    # Copy to conf.d (primary) and sites-available (backup)
    cp "$NGINX_CONF" /etc/nginx/conf.d/default.conf
    cp "$NGINX_CONF" /home/site/default
    # Remove any conflicting sites-enabled configs
    rm -f /etc/nginx/sites-enabled/default 2>/dev/null
    echo "Custom Nginx configuration applied to /etc/nginx/conf.d/default.conf"
    # Reload nginx to pick up the new configuration
    if command -v nginx &> /dev/null; then
        nginx -t 2>&1 && nginx -s reload 2>/dev/null && echo "Nginx reloaded successfully" || echo "Nginx reload skipped (not yet running or config error)"
    fi
fi

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
