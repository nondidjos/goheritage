#!/usr/bin/env bash
#
# AWS Lightsail provisioning script for goheritage.
#
# Run ONCE on a fresh Lightsail "LAMP_PHP_8" instance, before deploying code.
# It installs Node.js (required by the model-converter / hotspot-detector
# plugins which exec() out to obj2gltf, gltf-transform and sharp-cli),
# bumps PHP limits to match .user.ini, and writes the Apache vhost.
#
# Usage (on the Lightsail instance, after `ssh bitnami@<static-ip>`):
#     curl -fsSL https://raw.githubusercontent.com/<your>/<repo>/main/deploy/provision.sh \
#         | bash
#   …or scp this file up and run:  bash provision.sh
#
set -euo pipefail

DOMAIN="${GOHERITAGE_DOMAIN:-goheritage.govr.eu}"
DOCROOT="/opt/bitnami/apache/htdocs"

echo "==> goheritage Lightsail provisioner"
echo "    Domain : $DOMAIN"
echo "    DocRoot: $DOCROOT"
echo

# ── 1. System packages ──────────────────────────────────────────────────────
echo "==> Updating apt cache"
sudo apt-get update -y

echo "==> Installing Node.js 20.x (for model-converter / hotspot-detector)"
if ! command -v node >/dev/null || ! node --version | grep -qE '^v(2[0-9])\.'; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
    sudo apt-get install -y nodejs
fi

echo "==> Installing rsync, git, unzip"
sudo apt-get install -y rsync git unzip

# ── 2. Composer ─────────────────────────────────────────────────────────────
if ! command -v composer >/dev/null; then
    echo "==> Installing Composer"
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

# ── 3. PHP runtime tweaks ───────────────────────────────────────────────────
echo "==> Setting PHP limits (matches the project .user.ini)"
sudo tee /opt/bitnami/php/etc/conf.d/zz-goheritage.ini >/dev/null <<'INI'
; goheritage runtime limits — large 3D model uploads
upload_max_filesize = 5G
post_max_size       = 5G
memory_limit        = 512M
max_execution_time  = 600
max_input_time      = 600
INI

# ── 4. Apache vhost ─────────────────────────────────────────────────────────
echo "==> Writing Apache vhost for $DOMAIN"
sudo tee /opt/bitnami/apache/conf/vhosts/goheritage-vhost.conf >/dev/null <<CONF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot "$DOCROOT"
    <Directory "$DOCROOT">
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<IfDefine SSL>
<VirtualHost *:443>
    ServerName $DOMAIN
    DocumentRoot "$DOCROOT"
    SSLEngine on
    SSLCertificateFile      "/opt/bitnami/apache/conf/bitnami/certs/server.crt"
    SSLCertificateKeyFile   "/opt/bitnami/apache/conf/bitnami/certs/server.key"
    <Directory "$DOCROOT">
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
</IfDefine>
CONF

# Make sure the vhost is included
if ! grep -q 'goheritage-vhost.conf' /opt/bitnami/apache/conf/bitnami/bitnami.conf 2>/dev/null; then
    sudo sed -i '$a Include "/opt/bitnami/apache/conf/vhosts/goheritage-vhost.conf"' \
        /opt/bitnami/apache/conf/bitnami/bitnami.conf || true
fi

# ── 5. Wipe Bitnami's default index.html so / serves Kirby ─────────────────
sudo rm -f "$DOCROOT/index.html" "$DOCROOT/bitnami.png" 2>/dev/null || true

# ── 6. Create writable runtime dirs Kirby expects ──────────────────────────
echo "==> Pre-creating Kirby runtime directories with daemon group write access"
sudo mkdir -p "$DOCROOT/site/accounts" "$DOCROOT/site/sessions" \
              "$DOCROOT/site/cache"    "$DOCROOT/site/logs"   \
              "$DOCROOT/content"       "$DOCROOT/media"
sudo chown -R bitnami:daemon "$DOCROOT"
sudo find "$DOCROOT/site/accounts" "$DOCROOT/site/sessions" \
          "$DOCROOT/site/cache"    "$DOCROOT/site/logs"     \
          "$DOCROOT/content"       "$DOCROOT/media"          \
    -type d -exec chmod 2775 {} \;

# ── 7. Restart services ────────────────────────────────────────────────────
echo "==> Restarting Apache + PHP-FPM"
sudo /opt/bitnami/ctlscript.sh restart apache
sudo /opt/bitnami/ctlscript.sh restart php-fpm

# ── 8. Final notes ─────────────────────────────────────────────────────────
cat <<NOTES

==> Provision complete.

NEXT STEPS:

  1. Attach a Lightsail static IP to this instance and point your DNS:
         A   $DOMAIN   ->   <static IP>

  2. Once DNS resolves, request a Let's Encrypt cert (interactive):
         sudo /opt/bitnami/bncert-tool
     Use $DOMAIN when prompted. Choose "redirect HTTP to HTTPS".

  3. From your laptop, run:  ./deploy/deploy.sh

NOTES
