#!/bin/bash
echo "🔴 === BOOT SCRIPT STARTED ==="
date

# 1. Define paths
NGINX_CONF="/home/site/wwwroot/default"
NGINX_BASE="/etc/nginx"

# 2. Nuclear Option: Clear ALL potential default configs
#    This deletes the 'welcome page' configs commonly found in Nginx images
echo "--- Cleaning existing Nginx configs ---"
rm -fv $NGINX_BASE/conf.d/default.conf
rm -fv $NGINX_BASE/sites-enabled/default
rm -fv $NGINX_BASE/sites-available/default

# 3. Install OUR config
echo "--- Installing custom config ---"
if [ -f "$NGINX_CONF" ]; then
    # Copy to sites-available (standard)
    mkdir -p $NGINX_BASE/sites-available
    cp "$NGINX_CONF" $NGINX_BASE/sites-available/default
    
    # Link to sites-enabled (standard)
    mkdir -p $NGINX_BASE/sites-enabled
    ln -sf $NGINX_BASE/sites-available/default $NGINX_BASE/sites-enabled/default
    
    echo "✅ Config installed to sites-enabled/default"
else
    echo "❌ ERROR: /home/site/wwwroot/default file missing!"
    exit 1
fi

# 4. Forensic: Inspect what is actually active
echo "--- Active Configuration Audit ---"
# Check if nginx.conf has a hardcoded include or server block
grep -n "include" $NGINX_BASE/nginx.conf
grep -n "root" $NGINX_BASE/nginx.conf

# 5. Reload and Start
echo "--- Reloading Nginx ---"
nginx -t && service nginx reload

echo "--- Starting PHP-FPM ---"
php-fpm -F
