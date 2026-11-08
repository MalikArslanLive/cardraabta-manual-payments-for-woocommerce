#!/usr/bin/env bash
# Actual cache/optimizer plugins on one disposable PHP 8.3/Nginx stack.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true ]] || exit 1
wp_opt(){ php /tmp/wp.phar --user=auditor "$@" --path="$CRMP_WP_PATH"; }
dependency_baseline(){
 local phase="$1"
 # Login is uncached and warms WooCommerce after worker/cache changes.
 curl --fail --silent http://127.0.0.1:8089/wp-login.php >/dev/null
 # Warm the generic authenticated dashboard too: optional cache changes can
 # reinitialize WooCommerce admin dependencies independently of CardRaabta screens.
 python3 - <<'PYADMIN'
import requests
session=requests.Session()
base="http://127.0.0.1:8089"
session.get(base+"/wp-login.php",timeout=30).raise_for_status()
response=session.post(base+"/wp-login.php",data={"log":"auditor","pwd":"test-only-local","wp-submit":"Log In","redirect_to":base+"/wp-admin/index.php","testcookie":"1"},timeout=60)
response.raise_for_status()
assert any("wordpress_logged_in" in cookie for cookie in session.cookies.keys()), "Dependency dashboard warmup did not authenticate"
assert "/wp-admin/" in response.url, "Dependency dashboard warmup did not reach admin"
PYADMIN
 cp "$CRMP_WP_PATH/wp-content/debug.log" "/tmp/crmp-optimizer-$phase-debug.log"
 python3 tools/ci/check-dependency-debug.py "/tmp/crmp-optimizer-$phase-debug.log"
 cat "/tmp/crmp-optimizer-$phase-debug.log" >> /tmp/crmp-optimizer-dependency-debug.log
 : > "$CRMP_WP_PATH/wp-content/debug.log"
}
docker run -d --name crmp-redis -p 127.0.0.1:6379:6379 redis:7-alpine
trap 'docker rm -f crmp-redis >/dev/null 2>&1 || true' EXIT
for attempt in {1..30}; do if docker exec crmp-redis redis-cli ping | grep -q PONG; then break; fi; sleep 1; done
# Runtime fixtures deliberately block wp_remote_* outbound calls. Download official
# packages with curl, then install locally without weakening that mail/HTTP isolation.
python3 tools/ci/check-debug-log.py "$CRMP_WP_PATH/wp-content/debug.log"
for slug in autoptimize wp-super-cache redis-cache; do
 curl -fsSL "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" -o "/tmp/crmp-${slug}.zip"
 wp_opt plugin install "/tmp/crmp-${slug}.zip" --activate
done
wp_opt config set WP_HOME http://127.0.0.1:8089
wp_opt config set WP_SITEURL http://127.0.0.1:8089
wp_opt option update home http://127.0.0.1:8089
wp_opt option update siteurl http://127.0.0.1:8089
wp_opt config set WP_CACHE true --raw
# The drop-in needs this before normal plugins load, including CLI installs.
wp_opt config set WPCACHEHOME "$CRMP_WP_PATH/wp-content/plugins/wp-super-cache/"
wp_opt config set WP_REDIS_HOST 127.0.0.1
wp_opt config set WP_REDIS_CLIENT predis
wp_opt config set WP_REDIS_PREFIX crmp-optimization-fixture:
wp_opt redis enable
wp_opt redis status
docker exec crmp-redis redis-cli INFO server | grep redis_version
wp_opt option update permalink_structure '/%postname%/'
wp_opt rewrite flush
# Record optional dependency initialization separately; keep WP_DEBUG enabled for
# all subsequent payment/cache scenarios and retain the unfiltered application gate.
: > /tmp/crmp-optimizer-dependency-debug.log
dependency_baseline installation
if [[ "${CRMP_DEBUG_ONLY:-0}" == 1 ]]; then
 wp_opt option update woocommerce_coming_soon no
 wp_opt option update woocommerce_store_pages_only no
fi
modes=(aggregate)
if [[ "${CRMP_DEBUG_ONLY:-0}" == 1 ]]; then
 modes=(aggregate inline)
elif [[ "${CRMP_CACHE_ONLY:-0}" != 1 ]]; then
 node tests/optimization-transform.cjs
 for test in release-dom admin-audit-dom email-dom email-log-dom checkout-copy-dom proof-policy-dom checkout-mode-dom checkout-design-dom proof-limit-dom customer-form-dom; do
  node -r ./tests/optimized-assets-loader.cjs "tests/$test.cjs"
 done
 node -r ./tests/optimized-assets-loader.cjs tests/release-responsive.cjs
 python3 tools/ci/check-debug-log.py "$CRMP_WP_PATH/wp-content/debug.log"
 modes=(aggregate inline)
fi
for mode in "${modes[@]}"; do
 wp_opt eval-file tests/optimization-config.php "$mode"
 if [[ "${CRMP_CACHE_ONLY:-0}" != 1 && "${CRMP_DEBUG_ONLY:-0}" != 1 ]]; then wp_opt eval-file tests/server-security.php; fi
 # CLI cache configuration changes cannot invalidate the FPM OPcache.
 # Start HTTP checks with workers that have loaded the completed configuration.
 python3 tools/ci/check-debug-log.py "$CRMP_WP_PATH/wp-content/debug.log"
 sudo systemctl restart "php${CRMP_PHP_VERSION}-fpm"
 dependency_baseline "$mode"
 if [[ "${CRMP_DEBUG_ONLY:-0}" == 1 ]]; then
  wp_opt eval 'if(!function_exists("crmp_evidence_directory_name"))throw new RuntimeException("Plugin did not load");echo "PASS focused cache bootstrap\n";'
  curl --fail --silent 'http://127.0.0.1:8089/?crmp_environment_check=1' | python3 -c 'import json,sys; data=json.load(sys.stdin); assert data["php"].startswith("8.3.") and data["woocommerce"]; print("PASS focused HTTP runtime")'
  python3 tools/ci/check-debug-log.py "$CRMP_WP_PATH/wp-content/debug.log"
  continue
 fi
 python3 tests/optimization-http.py
 if [[ "${CRMP_CACHE_ONLY:-0}" == 1 ]]; then continue; fi
 CRMP_EXPECT_OPTIMIZED=1 CRMP_DELAY_SCRIPTS=1 node tests/checkout-upload-browser.cjs
 python3 tests/server-security-http.py
 python3 tests/optimization-cache-audit.py
 python3 tools/ci/check-debug-log.py "$CRMP_WP_PATH/wp-content/debug.log"
done

# Remove the drop-in while Redis is alive; later WordPress checks need a working cache.
wp_opt redis disable
test ! -e "$CRMP_WP_PATH/wp-content/object-cache.php"
dependency_baseline cleanup
