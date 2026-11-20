# Testing CardRaabta

Run commands from the repository root. The [GitHub Actions workflow](../.github/workflows/security.yml) defines the complete setup, fixtures and test sequence.

## Local syntax and package checks

Use PHP 8.1+, Node.js and Python 3.

```bash
set -e
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n 1 php -l
for file in assets/*.js; do node --check "$file"; done
python3 tools/build-release.py /tmp/cardraabta-test.zip
```

The builder verifies a 20-file installable ZIP against the production files in the checkout. See [packaging](../release-assets/README.md) for the shipped package.

## WordPress integration checks

Use a disposable WordPress installation with WP-CLI, active WooCommerce, and this checkout installed and activated as the plugin. Enable `WP_DEBUG` and `WP_DEBUG_LOG`. These scripts create and modify orders, settings, evidence and notifications.

```bash
wp eval-file tests/evidence-directory.php --path=/path/to/disposable-wordpress
wp eval-file tests/release-regression.php --path=/path/to/disposable-wordpress
wp eval-file tests/email-regression.php --path=/path/to/disposable-wordpress
```

Use the workflow's setup and execution order for end-to-end runs. HTTP and Chromium checks expect its local site URL, fixture account and dependencies. The workflow installs the pinned browser dependencies from `tools/ci/browser` and Chromium via Playwright.

## GitHub Actions

| Area | Coverage |
| --- | --- |
| WordPress matrix | 13 Linux configurations covering PHP 8.1–8.5, Apache, Nginx and OpenLiteSpeed, including minimum WordPress and WooCommerce |
| Payment workflow | Classic Checkout, Checkout Blocks, legacy order storage and HPOS, proof validation, payment review, exports, notifications and retention |
| Browser and server checks | Real HTTP requests, responsive Chromium layouts, evidence access controls and representative caching/optimization |
| Analysis | WordPress Plugin Check, focused WPCS checks, and an empty WordPress debug-log gate |
| IIS | Separate native Windows/PHP FastCGI check for evidence directory access rules |

Open [Security and WordPress checks](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/actions/workflows/security.yml) to inspect the job logs and artifacts. Maintainers can run the default full suite manually or use `linux_only` and `iis_only` to focus a run. See the [v1.0.0 compatibility report](../reports/COMPATIBILITY-1.0.0.md) for historical results and test limitations.
