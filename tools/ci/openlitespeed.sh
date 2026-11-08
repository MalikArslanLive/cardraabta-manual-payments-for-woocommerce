#!/usr/bin/env bash
# Disposable GitHub runner: actual OpenLiteSpeed LSAPI, not a reverse proxy.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true ]] || exit 1
root="$GITHUB_WORKSPACE"
image=litespeedtech/openlitespeed@sha256:91ca324386be3fe8cd91591112dbdd8e41af48cccbe4a48e3e8673a2921c8504
sudo chown -R 65534:65534 /tmp/crmp-wp
# Host networking is solely for this disposable runner's loopback MySQL service.
# No dependency downloads run inside this container and no proxy is bypassed.
docker run -d --name crmp-ols --network host \
 --mount type=bind,src=/tmp,dst=/tmp \
 --mount "type=bind,src=$root,dst=$root,readonly" \
 --mount "type=bind,src=$root/tools/ci/ols-httpd.conf,dst=/usr/local/lsws/conf/httpd_config.conf,readonly" \
 --mount "type=bind,src=$root/tools/ci/ols-vhost.conf,dst=/usr/local/lsws/conf/vhosts/crmp.conf,readonly" \
 --entrypoint /bin/sh "$image" -c 'set -e; /usr/local/lsws/bin/lswsctrl start; exec tail -F /tmp/crmp-ols-error.log'
wp_ols(){ docker exec -u 65534 -e WP_CLI_CONFIG_PATH=/tmp/crmp-wp-cli.yml crmp-ols /usr/local/lsws/lsphp82/bin/php /tmp/wp.phar "$@" --path=/tmp/crmp-wp; }
for attempt in {1..30}; do
 if curl --silent --fail http://127.0.0.1:8089/ >/dev/null; then break; fi
 sleep 1
done
curl --fail --silent http://127.0.0.1:8089/ >/dev/null
docker exec crmp-ols /usr/local/lsws/bin/openlitespeed -v
for test in security-regression extended-security evidence-capabilities asset-cache checkout-mode hardening-regression release-regression email-regression email-log-regression; do
 wp_ols eval-file "$root/tests/$test.php"
done
wp_ols wc hpos sync
wp_ols option update woocommerce_custom_orders_table_enabled yes
for test in hardening-regression release-regression email-regression email-log-regression; do
 wp_ols eval-file "$root/tests/$test.php"
done
wp_ols option update siteurl http://127.0.0.1:8089
wp_ols option update home http://127.0.0.1:8089
wp_ols user update auditor --user_pass=test-only-local --role=administrator
python3 tools/ci/login-probe.py
for test in release-http email-http email-log-http; do python3 "tests/$test.py"; done
for test in release-dom admin-audit-dom email-dom email-log-dom checkout-copy-dom customer-form-dom; do node "tests/$test.cjs"; done
wp_ols eval-file "$root/tests/checkout-design.php"
wp_ols eval-file "$root/tests/export-fields-fixture.php"
python3 tests/export-fields-http.py
wp_ols eval-file "$root/tests/proof-policy.php"
node tests/proof-policy-dom.cjs
node tests/checkout-mode-dom.cjs
node tests/checkout-design-dom.cjs
wp_ols eval-file "$root/tests/proof-limit.php"
node tests/proof-limit-dom.cjs
# Screenshots are written in the checkout by the existing responsive runner.
node tests/release-responsive.cjs
python3 tests/proof-limit-http.py
wp_ols eval-file "$root/tests/server-security.php"
node tests/checkout-upload-browser.cjs
python3 tests/server-security-http.py
python3 tests/performance-probe.py
wp_ols plugin check cardraabta-manual-payments-for-woocommerce --format=strict-json --require=/tmp/crmp-wp/wp-content/plugins/plugin-check/cli.php | tee /tmp/crmp-plugin-check.log
python3 tools/ci/check-plugin-report.py /tmp/crmp-plugin-check.log

wp_ols eval-file "$root/tests/uninstall-retention.php" setup
wp_ols plugin uninstall cardraabta-manual-payments-for-woocommerce --deactivate --skip-delete
wp_ols eval-file "$root/tests/uninstall-retention.php" inactive
wp_ols plugin activate cardraabta-manual-payments-for-woocommerce
wp_ols eval-file "$root/tests/uninstall-retention.php" reactivated
