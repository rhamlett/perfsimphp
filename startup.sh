#!/bin/bash
# PerfSimPhp - Custom startup script for Azure App Service
# Per Microsoft docs: https://docs.microsoft.com/azure/app-service/configure-language-php?pivots=platform-linux#change-site-root
#
# This script copies our custom nginx site config to the correct location
# and reloads nginx, then performs app-specific initialization.

echo "=== PerfSimPhp Startup Script ==="
echo "PHP Version: $(php -v | head -1)"
echo "Starting at: $(date)"

# --- Nginx site config override ---
# The 'default' file is a server{} block (not a full nginx.conf).
# Per the docs, copy it to /etc/nginx/sites-available/default.
# We also try sites-enabled and conf.d as fallbacks in case the
# image version uses a different include path.
NGINX_CUSTOM="/home/site/wwwroot/default"
COPIED=false
if [ -f "$NGINX_CUSTOM" ]; then
    # Primary target per Microsoft docs (Sept 2025)
    if [ -d /etc/nginx/sites-available ]; then
        cp "$NGINX_CUSTOM" /etc/nginx/sites-available/default
        echo "Copied nginx site config to /etc/nginx/sites-available/default"
        COPIED=true
    fi
    # Also copy to sites-enabled (per 2021 AZOSS blog post)
    if [ -d /etc/nginx/sites-enabled ]; then
        cp "$NGINX_CUSTOM" /etc/nginx/sites-enabled/default
        echo "Copied nginx site config to /etc/nginx/sites-enabled/default"
        COPIED=true
    fi
    # Fallback: conf.d pattern (some image versions)
    if [ -d /etc/nginx/conf.d ]; then
        cp "$NGINX_CUSTOM" /etc/nginx/conf.d/default.conf
        echo "Copied nginx site config to /etc/nginx/conf.d/default.conf"
        COPIED=true
    fi
    if [ "$COPIED" = false ]; then
        echo "WARNING: No nginx include directory found!"
        echo "Listing /etc/nginx/:"
        ls -la /etc/nginx/
    fi

    # Test and reload nginx
    nginx -t 2>&1 && echo "nginx config test: OK" || echo "nginx config test: FAILED"
    service nginx reload
    echo "nginx reloaded"
else
    echo "WARNING: Custom nginx config not found at $NGINX_CUSTOM"
fi

# --- App initialization ---
# Create storage directory for file-based SharedStorage fallback
STORAGE_DIR="/home/site/wwwroot/storage"
if [ ! -d "$STORAGE_DIR" ]; then
    mkdir -p "$STORAGE_DIR"
    echo "Created storage directory: $STORAGE_DIR"
fi
chmod 777 "$STORAGE_DIR"

# Copy .user.ini to the document root (public/) where PHP-FPM reads it
USER_INI_SRC="/home/site/wwwroot/.user.ini"
USER_INI_DST="/home/site/wwwroot/public/.user.ini"
if [ -f "$USER_INI_SRC" ]; then
    cp "$USER_INI_SRC" "$USER_INI_DST"
    echo "PHP .user.ini copied to public directory"
fi

# Log diagnostics
echo "=== Diagnostics ==="
echo "APCu: $(php -m 2>/dev/null | grep -qi apcu && echo 'available' || echo 'not available')"
echo "exec(): $(php -r "echo function_exists('exec') ? 'available' : 'disabled';" 2>/dev/null)"
echo "memory_limit: $(php -r "echo ini_get('memory_limit');" 2>/dev/null)"
echo "Nginx dirs: $(ls -d /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null || echo 'none found')"
echo "========================="
echo "PerfSimPhp startup complete"
