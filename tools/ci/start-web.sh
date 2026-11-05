#!/usr/bin/env bash
# Disposable GitHub runner only. Never run against a production host.
set -euo pipefail
exec > >(tee /tmp/crmp-web-start.log) 2>&1
trap 'failed=$BASH_COMMAND; sleep 0.2; echo "::error title=Web startup command::$failed"; tail -n 16 /tmp/crmp-web-start.log | while IFS= read -r line; do line="${line//%/%25}"; echo "::error title=Web server startup::$line"; done' ERR
: "${CRMP_WP_PATH:?}" "${CRMP_WEB_SERVER:?}" "${CRMP_PHP_VERSION:?}"
[[ "${GITHUB_ACTIONS:-}" == true ]] || { echo 'Only run on an isolated GitHub Actions runner.' >&2; exit 1; }
# setup-php can install CLI from its cache without enabling the FPM package source.
if ! apt-cache show "php${CRMP_PHP_VERSION}-fpm" >/dev/null 2>&1; then
 sudo add-apt-repository --yes ppa:ondrej/php
fi
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "php${CRMP_PHP_VERSION}-fpm" "$CRMP_WEB_SERVER"
sudo systemctl stop apache2 nginx 2>/dev/null || true
pool="/etc/php/${CRMP_PHP_VERSION}/fpm/pool.d/www.conf"
sudo sed -i -E "s/^user = .*/user = $(id -un)/;s/^group = .*/group = $(id -gn)/;s|^listen = .*|listen = 127.0.0.1:9000|" "$pool"
printf '\nphp_admin_value[upload_max_filesize] = 8M\nphp_admin_value[post_max_size] = 16M\nphp_admin_value[memory_limit] = 256M\n' | sudo tee -a "$pool" >/dev/null
# CLI-created /tmp fixtures must be visible to both server services.
# Change only this disposable runner's unit isolation, never a production host.
for unit in "php${CRMP_PHP_VERSION}-fpm" apache2; do
 sudo mkdir -p "/etc/systemd/system/${unit}.service.d"
 printf '[Service]\nPrivateTmp=false\n' | sudo tee "/etc/systemd/system/${unit}.service.d/crmp-test.conf" >/dev/null
done
sudo systemctl daemon-reload
sudo systemctl restart "php${CRMP_PHP_VERSION}-fpm"
if [[ "$CRMP_WEB_SERVER" == apache2 ]]; then
 sudo a2enmod proxy_fcgi setenvif rewrite
 echo 'Listen 8089' | sudo tee /etc/apache2/ports.conf >/dev/null
 cat > /tmp/crmp-vhost.conf <<EOF
<VirtualHost *:8089>
 DocumentRoot "$CRMP_WP_PATH"
 DirectoryIndex index.php
 <Directory "$CRMP_WP_PATH">
  AllowOverride All
  Require all granted
 </Directory>
 <FilesMatch "\\.php$">
  SetHandler "proxy:fcgi://127.0.0.1:9000"
 </FilesMatch>
 ErrorLog /tmp/crmp-web-error.log
 CustomLog /tmp/crmp-web-access.log combined
</VirtualHost>
EOF
 sudo cp /tmp/crmp-vhost.conf /etc/apache2/sites-available/000-default.conf
 sudo a2ensite 000-default
 sudo apache2ctl configtest
 sudo apache2ctl -S
 sudo systemctl restart apache2
 apache2 -v
elif [[ "$CRMP_WEB_SERVER" == nginx ]]; then
 cat > /tmp/crmp-nginx.conf <<EOF
pid /tmp/crmp-nginx.pid;
error_log /tmp/crmp-web-error.log;
events { worker_connections 256; }
http {
 include /etc/nginx/mime.types;
 access_log /tmp/crmp-web-access.log;
 client_body_temp_path /tmp/crmp-nginx-body;
 fastcgi_temp_path /tmp/crmp-nginx-fastcgi;
 client_max_body_size 16m;
 server {
  listen 127.0.0.1:8089;
  root $CRMP_WP_PATH;
  index index.php;
  location ~* /crmp-private(?:/|$) { return 403; }
  location / { try_files \$uri \$uri/ /index.php?\$args; }
  location ~ \\.php$ {
   include /etc/nginx/fastcgi_params;
   fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
   fastcgi_pass 127.0.0.1:9000;
  }
 }
}
EOF
 nginx -t -c /tmp/crmp-nginx.conf
 nginx -c /tmp/crmp-nginx.conf
 nginx -v
else
 echo "Unsupported web server: $CRMP_WEB_SERVER" >&2; exit 1
fi
for attempt in {1..30}; do
 if curl --fail --silent http://127.0.0.1:8089/ >/dev/null; then exit 0; fi
 sleep 1
done
curl --silent --show-error --max-time 10 -o /tmp/crmp-readiness.html -w 'Final readiness HTTP status: %{http_code}\n' http://127.0.0.1:8089/index.php || true
head -c 1200 /tmp/crmp-readiness.html || true
cat /tmp/crmp-web-error.log >&2
exit 1
