#!/bin/bash
echo "🔴 === BOOT SCRIPT STARTED ==="
date

# 1. Define paths
NGINX_CONF="/home/site/wwwroot/default"
NGINX_BASE="/etc/nginx"

# 2. Nuclear Option: Clear ALL potential default configs
#    This deletes the 'welcome page' configs commonly found in Nginx images
echo "--- Cleaning existing Nginx configs ---"
echo "Existing conf.d contents:"
ls -R $NGINX_BASE/conf.d
echo "Existing sites-enabled contents:"
ls -R $NGINX_BASE/sites-enabled

# Forcefully overwrite the default index.html so even if config fails, we don't see "Welcome to Nginx"
echo "<h1>PerfSimPhp Booting...</h1><p>If you see this, the Nginx config swap hasn't taken effect yet, but the boot script ran.</p>" > /usr/share/nginx/html/index.html

# Delete EVERYTHING in conf.d to ensure no stowaways
rm -rf $NGINX_BASE/conf.d/*
# Delete defaults in sites-*
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
    echo "--- Verified Content of sites-enabled/default ---"
    cat $NGINX_BASE/sites-enabled/default
    echo "-------------------------------------------------"
else
    echo "❌ ERROR: /home/site/wwwroot/default file missing!"
    exit 1
fi

# 4. Forensic: Inspect what is actually active
echo "--- Active Configuration Audit ---"
# Check if nginx.conf has a hardcoded include or server block
echo "Checking nginx.conf for server blocks:"
grep -n "server" $NGINX_BASE/nginx.conf
echo "Checking nginx.conf includes:"
grep -n "include" $NGINX_BASE/nginx.conf
echo "Checking nginx.conf root:"
grep -n "root" $NGINX_BASE/nginx.conf

# 5. Reload and Start
echo "--- Reloading Nginx ---"
# Kill any existing Nginx processes to ensure clean slate
pkill nginx
# Wait a moment
sleep 2
# Test config
nginx -t
# Start Nginx service (or reload if it survived)
service nginx start || service nginx reload

echo "--- Process Check (netstat) ---"
# Check what is listening
netstat -tulpn || ss -tulpn

echo "--- Internal Connectivity Check ---"
curl -I http://localhost:8080 || echo "Curl to 8080 failed"
curl -I http://localhost:80 || echo "Curl to 80 failed"

echo "--- Starting PHP-FPM ---"
php-fpm -F
