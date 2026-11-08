# WordPress.org verification — 1.0.0

The production package uses a zero-byte private-directory index and a plugin-slug evidence folder under the runtime WordPress uploads base. Evidence is restricted to encrypted .crmp files in that folder; previous-version folder/plaintext support and its migration have been removed. Version and Stable tag are 1.0.0; the GPL license and uninstall retention are unchanged. No changelog is included.

## Clean installation and debug checks

A fresh WordPress filesystem and database were prepared from the official pinned WordPress container. Official core checksums passed. WooCommerce and Plugin Check were installed as dependencies. WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3.36, Apache 2.4.69 and MariaDB 10.11 were used locally.

WP_DEBUG and WP_DEBUG_LOG were true, and WP_DEBUG_DISPLAY was false during CardRaabta activation and the integration tests. The complete final test run produced a zero-byte debug log. Repeated database installation and upgrade assertions passed without database errors, and complete email-log indexes were verified.

WooCommerce/Jetpack's first dependency initialization can emit an early translation notice before CardRaabta loads. That dependency-only baseline was traced and recorded separately before measuring CardRaabta activation. Test-only core update hooks are removed and update endpoints receive an empty up-to-date fixture response because application outbound HTTP is intentionally blocked. CLI scanners may read authentic public WordPress.org plugin metadata; that metadata is never fabricated. The optional WooCommerce remote-feed cache is seeded as an empty array for minimum-version compatibility. Official WP-CLI artifacts are pinned and SHA-512 verified; PHP 8.5 uses the official pinned nightly containing the upstream cli-tools null-offset fix. Color output is disabled for plain CI logs. Optional cache-plugin initialization is recorded separately; only the specifically traced WooCommerce first-run translation notice is accepted in that dependency baseline, and all other diagnostics fail. Subsequent payment/cache scenarios retain the strict empty-log gate. Plugin diagnostics are not suppressed. The fixture files are excluded from the installable ZIP.

## Validation

- The real uninstall hook and reactivation preserve ledger rows, receiving settings, the encryption key and ciphertext bytes; scheduled email work is cancelled and retained evidence decrypts after activation. LICENSE and uninstall.php were reviewed and intentionally left unchanged.
- Main PHP regressions, HPOS, payment locking, stale requests, history/retention, authorization, media permissions, encrypted evidence and asset caching passed.
- Real payment-review, email/log management, CSV field/privacy and proof-limit HTTP checks passed.
- Classic and Blocks proof policy/date/note handoff, native multipart and encoded screenshot uploads, optional checkout, later submission and review transitions passed.
- Chromium screenshot refresh persistence, filename/progress states, responsive layouts at 320/360/390/768/1365px, correction toggles and clipboard fallbacks passed.
- Minification, dynamic CSS safelisting, and gzip/Brotli byte round trips passed.
- Official Plugin Check including runtime checks: zero errors/warnings, using the strict report gate.
- PHPCS with WPCS security and prepared-SQL sniffs: zero errors/warnings. This is a focused security scan, not a claim that every optional formatting/documentation sniff passes.
- PHP/JavaScript syntax checks, deterministic production ZIP creation and package/source byte checks passed.
- A clean database creates screenshot attachment IDs without the removed screenshot_path column or evidence-migration state; repeated installation succeeds.
- Storage checks also passed with the installed plugin directory read-only; new evidence uses the runtime uploads base and the exact plugin slug. Unsupported directories, plaintext, renamed plaintext and outside/traversal paths are denied.
- Focused Nginx rule validation denied plugin-slug/default-multisite evidence URLs while retaining plugin CSS access. A separate native Windows Server 2022 / IIS 10 / PHP 8.3.36 FastCGI test also passed: the production function created zero-byte indexes and authorization guards for the plugin-slug evidence root. Before guard creation the fixture was readable; afterward direct/query/encoded file and directory requests were denied, public plugin CSS remained accessible, existing guards were preserved and the PHP error log was empty. URL Authorization and delegated authorization configuration are prerequisites. Full WordPress/WooCommerce checkout on IIS was not tested.
- Browser-tool dependency audit reported zero known vulnerabilities; those dependencies are not distributed in the production ZIP.

## Runtime matrix and limits

After removal of previous-version evidence support, all 13 Linux/WordPress configurations described in COMPATIBILITY-1.0.0.md passed a fresh full run. The separate native IIS guard test also passed with only the plugin-slug evidence root configured. Every final matrix job enables WP_DEBUG for the plugin checks, runs focused PHPCS/WPCS checks and rejects a missing or nonempty debug log. The current workflow remains available for future validation even when historical Actions runs are removed. Its failed_checks_only mode retries only the three PHP 8.5 scanner/bootstrap cases and the PHP 8.3 cache/debug case, without rerunning passing functional suites.

The disposable mu-plugin prevents WooCommerce's first-run onboarding redirect from interrupting WP-CLI 3 plugin commands, and setup explicitly asserts that CardRaabta is active. Generic authenticated dashboard initialization after optional cache changes is recorded in the dependency baseline before plugin screen assertions; payment/cache scenarios still reject any nonempty debug log. These fixtures do not ship in the production ZIP.

Tests use disposable orders and intercepted mail; they do not verify real fund receipt or inbox delivery. A successful test run is not a guarantee against every vulnerability, theme/host combination or a future manual review finding. Nginx/custom upload mappings require the documented server configuration.

## Setup Guide navigation follow-up

The subsequent Setup Guide addition changes admin navigation and documentation. It adds a manager-only external link after Email Logs, opening the GitHub hosting guide in a new tab with noopener/noreferrer. The guide explains automatic directory guards, required Nginx/IIS hosting configuration, when no additional rule is needed, consequences of missing rules, a harmless-marker verification procedure and a hosting-support message. It does not claim automatic server configuration or verified protection status.

Focused validation passed: 55 admin regression assertions; actual administrator and shop-manager browser navigation; Editor menu exclusion; desktop and 390px mobile menu display; dashboard availability; correct HTTPS destination and isolated new-tab opener; Plugin Check including runtime checks with zero reported errors/warnings; focused WPCS security/prepared-SQL checks; PHP syntax and an empty WP_DEBUG log. The full matrix above validates the storage/payment implementation; this navigation-only follow-up uses these focused checks. Documentation links and the rebuilt/downloaded production package are verified separately. No database setting or payment/storage behavior is changed by opening the guide.

## Package

The installable ZIP contains 20 production files selected by tools/build-release.py, including the WordPress readme and GPL license. Tests, reports and development dependencies are excluded. Packaged file bytes are compared with the final Git commit and the downloaded GitHub release asset.
