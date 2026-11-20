# CardRaabta 1.0.0 compatibility and security review

CardRaabta Manual Payments for WooCommerce 1.0.0 uses in-memory validation and encryption for checkout screenshot evidence. The production ZIP contains 20 files and the build verifies source parity.

## Required matrix

| Runtime | Server | Database | WordPress / WooCommerce |
| --- | --- | --- | --- |
| PHP 8.1, 8.2, 8.3, 8.4, 8.5 | Apache + matching PHP-FPM | MySQL 8.0 | Latest stable official downloads |
| PHP 8.1, 8.2, 8.3, 8.4, 8.5 | Nginx + matching PHP-FPM | MySQL 8.0 | Latest stable official downloads |
| PHP 8.5 | Nginx + PHP-FPM | MariaDB 10.11 | Latest stable official downloads |
| PHP 8.1 | Nginx + PHP-FPM | MySQL 8.0 | Declared minimum: WordPress 6.5 / WooCommerce 8.3.0 |
| Image-provided PHP 8.2.35 LSAPI | OpenLiteSpeed 1.9.4 | MySQL 8.0 | Latest stable official downloads |

The official latest downloads observed during this review were WordPress **7.1.4** and WooCommerce **11.2.2**. Each job records actual PHP/WordPress/WooCommerce/server versions, rather than assuming the requested label equals the running version. OpenLiteSpeed uses official image digest `sha256:91ca324386be3fe8cd91591112dbdd8e41af48cccbe4a48e3e8673a2921c8504`; this is real LSAPI execution, not a reverse proxy to PHP's development server. The minimum stack uses WooCommerce's older `cot` CLI command for HPOS. Official Plugin Check, including runtime checks, is required on the minimum WordPress installation as well as the current stable installations.

After previous-version evidence support was removed, the final review passed all 13 Linux configurations, including Plugin Check, focused WPCS, actual uninstall/reactivation retention and empty WP_DEBUG log gates. The separate native IIS guard test also passed. The workflow records the status of each configuration in GitHub Actions; historical runs may be removed after retaining local evidence. The production ZIP is checked locally with PHP syntax checks, WordPress regression tests, HTTP and DOM checks, and official Plugin Check.

## Functional, security and browser checks

- PHP/JavaScript syntax and a deterministic production-only ZIP with source byte parity.
- Database lifecycle, payment decisions, duplicate references, proof versions/revisions, stale requests, closure and named-lock exclusion.
- Classic order storage and HPOS, notification queues, email privacy/retention/retries, admin permissions and CSV fields/escaping.
- Real authenticated and anonymous HTTP operations, real multipart PNG uploads, executable-file rejection, incorrect nonce/key rejection, stale-upload rejection and server-side confirmation.
- Real Classic AJAX add-to-cart and checkout, plus Checkout Blocks' Store API cart/checkout, creating unpaid manual-payment orders with captured proof.
- Anonymous direct access denied for encrypted evidence, a readable test-only sentinel, directory URLs, query strings and encoded path variants. Managers receive the decrypted original with no-store/nosniff headers.
- Core WordPress capabilities and real Media REST requests must deny an Editor reading/deleting private evidence, while ordinary uploads retain normal permissions.
- Chromium responsive checks at 320, 360, 390, 768 and 1365px, correction toggles, file-reader races, clipboard success/denial/unavailability, and DOM/XSS safety checks. These are representative layouts, not every theme/device.
- Merchant Required choices remain configurable. Both optional proof fields still require one actual proof for each submission; mandatory checkout can be disabled explicitly, in which case empty proof defers submission and leaves the order unpaid/On hold. Highlighted settings guidance explains this; screenshot feedback distinguishes selection/preparation from server-confirmed submission.
- Same-version asset cache test changes file contents while preserving its size and timestamp across two fresh WordPress processes.
- Small warm dashboard/settings/log latency samples are recorded as artifacts. They are measurements on a small CI fixture database, not a production load test or capacity guarantee. No unmeasured speed improvement is claimed.

- Field policy checks cover defaults, either/or proof, individual required fields, disabled-data rejection/omission, separate checkout/order policies, inheritance and atomic settings saves.

## Scope limits and deployment requirements

- PHP 7.4/8.0 are below the declared minimum and are not supported. Every patch release, every WordPress/WooCommerce combination and every commercial hosting configuration is not tested.
- OpenLiteSpeed coverage is PHP 8.2 LSAPI only. It does not certify LiteSpeed Enterprise, all LSAPI versions, cPanel/CloudLinux combinations, Caddy, all CDN/cache configurations, multisite, proxies or every theme. A separate Windows Server 2022 / IIS 10 / PHP 8.3.36 FastCGI runtime test exercises the actual production guard function and real HTTP authorization for the plugin-slug evidence root, with a readable positive control, encoded/query requests, directory blocking, public CSS access, guard preservation and an empty PHP log. It requires IIS URL Authorization and delegated authorization configuration; full WordPress/WooCommerce checkout on IIS is outside that test.
- Nginx requires a server-level private-directory denial rule; OpenLiteSpeed requires `.htaccess` loading. See README. Tests prove the supplied configurations, not an unknown host's configuration.
- Full WordPress integration tests execute on disposable hosted GitHub runners. No production customer data or real transfers are used.
- Duplicate references are scoped to a receiving method. Reconcile transfers across methods sharing a bank account; only a merchant can establish that funds arrived. No provider API verification is claimed.
- Proof storage has no automatic expiry/site-wide quota or dedicated WordPress personal-data exporter/eraser. Define retention, monitor storage/guest abuse, and protect backups and the evidence key.
- Isolated mail transport intercepts test messages; inbox delivery is not verified. No review guarantees absence of vulnerabilities.

## Submission audit data

Per-submission audit fields record source, effective field policy and actual confirmation state, append fixed columns to both CSVs, survive replacements/reviews and are cleared by permanent purge. Historical settings are never inferred from current options. Legacy unknown values remain explicitly unrecorded. Audit details stay manager-only and are not added to email snapshots.

Checkout-mode coverage includes server-authoritative default enforcement, optional empty-proof orders without fake ledger entries, partial/invalid proof rejection, later-proof enforcement, native multipart checkout, malformed/oversized references, hidden required flags, manager settings saves, Classic/Blocks empty-proof checkout and closure CSV metadata.

Actual browser coverage forces WooCommerce payment-fragment replacement to verify screenshot retention, prevents base64 payloads on address/cart updates and other gateways, and checks screenshot feedback at responsive widths.

Optimization coverage is a representative PHP 8.3/Nginx/MySQL scenario with actual Autoptimize aggregation/defer/inline CSS, WP Super Cache and Redis Object Cache. Versions and transport headers are recorded by the runner. Minification and a safelisted unused-CSS simulation run existing DOM regressions; actual checkout is also exercised with delayed script delivery. Live Cloudflare, commercial WP Rocket/LiteSpeed optimization options, arbitrary interaction-delay order and unsafelisted CSS removal are not certified.

The isolated public-cache regression also passed with Autoptimize 3.1.17, WP Super Cache 3.1.5 and Redis Object Cache 3.0.0: a second HTTP request returned the same saved render and the header `Served supercache gzip file from PHP`. The fixture sets the cache slash policy to match its permalink structure, waits for the completed cache file, and refreshes PHP-FPM after CLI configuration changes. The cache-hit assertion requires an actual saved render and matching response.

Plugin Check output is validated explicitly, rather than trusting its default exit status. The release gate rejects both reported errors and warnings. Atomic exclusive creation of local evidence-directory guards, WooCommerce-verified nonce boundaries and the standard page-cache interoperability constant have narrowly scoped, reasoned scanner annotations; no security check category is disabled.

Browser/minifier test dependencies are locked, including Playwright 1.64.1 and PurgeCSS 8.0.0. These dependencies are not included in the installable plugin ZIP. The installation was checked with `npm ci --ignore-scripts`, and the dependency audit reported zero known vulnerabilities. GitHub code-scanning alert inventory could not be independently queried because the integration lacks that permission; CodeQL workflow completion is not a zero-alert guarantee.


