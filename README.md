# CardRaabta Manual Payments for WooCommerce

**Version 1.0.0** · Developed by [Malik Arslan](https://malikarslan.com/).

Accept manual bank transfers, mobile wallet payments and Raast payments in WooCommerce. Customers submit payment proof at checkout or from their order page, and store managers verify the transfer before marking the order paid.

[WordPress.org listing](https://wordpress.org/plugins/cardraabta-manual-payments-for-woocommerce/) · [Report an issue](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/issues) · [Latest installable release](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/tag/v1.0.0)

Download the [installable v1.0.0 ZIP](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/download/v1.0.0/cardraabta-manual-payments-for-woocommerce-v1.0.0.zip). GitHub source archives include development files.

## Description

Install the plugin and add your existing bank or wallet receiving details. Customers transfer PKR outside checkout and submit payment proof. Verify the funds in your receiving account before approving the order. Setup requires no payment gateway merchant account, API keys or provider integration approval. You still need an account that can receive the selected transfers.

Independent third-party software; not affiliated with or endorsed by payment providers or WooCommerce. No bank/wallet API integration, automatic fund verification, card processing or automatic refunds.

### Receiving methods

| Method | Receiving details |
| --- | --- |
| JazzCash / Easypaisa / UPaisa | Mobile receiving number |
| SadaPay / NayaPay | Account number or IBAN |
| Bank Transfer / Cash Bank Deposit | Bank account number or IBAN |
| Raast | Raast ID or IBAN |
| Zindigi / HBL Konnect | Receiving account identifier |
| Custom | Merchant-defined receiving identifier |

Configure account title, number/IBAN/identifier, instructions and QR image. Selected methods show matching details. Never include PINs/passwords.

### Customer payment proof

* Classic Checkout and Checkout Blocks; proof is mandatory by default. Merchants can allow unpaid orders without proof and later submission.
* Every proof submission needs an enabled reference or screenshot. Enabled dates must be valid and not in the future.
* Screenshot and date are enabled and required by default; other proof fields start disabled.
* Screenshot selection shows the filename, preparation progress and a ready-to-submit status; selection is separate from server-confirmed submission.
* JPG, PNG and WebP; configurable 1–8 MB limit (default 5 MB), with server-side type, size and dimension checks.
* Responsive forms inherit theme typography/primary colours, falling back to WooCommerce email colours. Order pages retain normal theme header/footer, without duplicate card logos.
* Pending/Rejected forms remain collapsed until Update payment proof is clicked. Pending status explains that admin review is awaited.
* Settings control visibility and required status of screenshot, reference, date, note and confirmation. Keep screenshot or reference enabled; each proof submission needs at least one. A highlighted recommendation suggests making one proof field Required; individual Required choices remain configurable. Order forms open only after an explicit button click.
* Replacement uses the same transaction ID. Previous references, screenshots, methods, dates, notes and decisions remain in manager-only revision history.
* Configurable 1–10 replacements (default 3) after initial submission. Remaining updates are shown; completed rejection resets usage. Changing the limit does not reset usage. Paid orders cannot resubmit.

Registered customers log in; guests use private order-key links and may need billing-email verification. Email buttons show current status.

### Configurable field data and exports

Checkout requires proof by default. Merchants can disable mandatory checkout proof; empty proof then creates an unpaid/On hold order without a fake ledger entry, and customers submit proof later. Entering any proof-field data counts as a submission attempt and triggers the configured rules. Field visibility/requirements can be shared with order-page updates or configured separately. The default is required screenshot + date; reference, note and confirmation are disabled. At least screenshot or reference must remain enabled. Replacements are complete submissions, so omitted fields clear the current submission while prior values stay in revision history.

Each new submission records its applicable field settings, checkout/order-page source and confirmation state. Transaction details, revision details and both manager-only CSVs use these recorded values, never today's settings. CSV columns remain fixed when settings change; uncollected values are blank, and unknown legacy audit fields say “Not recorded”. No confirmation is inferred for old records. Screenshots remain private attachment IDs in CSV, without file contents or public URLs. Customer confirmation is an assertion, not verification of received funds.

Disabled fields are ignored (disabled screenshot payloads are rejected). Date/note/confirmation data is not added to notification emails, public order summaries or order notes; the existing limited reference/order/amount/reviewer-note email placeholders remain. Private screenshot access is manager-only. Permanent purge clears customer notes, dates and recorded policy/confirmation/context and deletes proof revisions under the existing retention rules. CSV formula protection, Unicode/newline quoting, nonces and manager access still apply.

### Transaction dashboard

* Search by order/reference and filter by status. Actions preserve manual search; order numbers are never inserted into search automatically.
* All transactions and Trash default to 20 rows; Screen Options saves 1–100 per page.
* WordPress-style counts/pagination above and below the list; inline View/Hide Details and separate selection/record-number columns.
* Bulk Approve, Reject, Trash, Restore and permanent deletion with confirmation. Selection is bound to the displayed page.
* Bulk Reject requires a shared customer-visible reason; approval accepts an optional shared note. Ineligible, stale or paid records are skipped with a notice.
* Pending proof for an existing order cannot be trashed/deleted before review. The single-action prompt offers Review Payment; mixed bulk actions skip protected records.
* Trash/Restore change visibility only, not payment, stock or refunds. Permanent removal deletes proof, revisions and notes. Minimal receipt/reference and decision metadata remain to prevent duplicate approval.
* Paid-through receipt previews include administrative Trash/removal labels. Customers see current payment status without these labels or hidden proof details.

### CSV exports

Two manager-only, nonce-protected exports: retained current transactions (including Trash) and current/previous proof history. Include references, methods, amounts/currencies, screenshot IDs, dates, customer/reviewer notes, versions, decisions, timestamps, submission source, confirmation and recorded field settings. Current export includes closure/source metadata: **Closed at** is the site-timezone timestamp when a sibling Pending/Rejected submission was closed because its order was already paid. **Paid through transaction** is the winning verified ledger record ID, not a bank reference. It is blank if the order was paid externally and no matching ledger receipt is known; winning/open records normally leave both columns blank. These audit columns retain the link to the paid receipt and prevent a closed sibling from being mistaken for verified proof. Missing legacy fields are not invented; purged records are excluded. Images are not embedded. Spreadsheet formula-prefix protection applies.

### Email notifications

Customer: proof received, updated proof, payment verified/rejected and missing-proof reminder. All five default ON; existing saved choices are preserved.

Admin: new/replaced proof, approved/rejected payment, interrupted review needs attention and missing-proof alert. Approval/rejection copies default OFF; others default ON.

* Billing-email customer recipient; 1–20 comma-separated admin addresses.
* Independent notification switches.
* Same-page subject, heading, rich-text message, additional text, button text and reviewer-note highlight customization.
* Logo, colours, footer and sender use WooCommerce email branding.
* Desktop/Mobile sample preview, restore defaults and logged Send test email. Tests use sample data/homepage links, not private customer links.
* Real reviewer notes populate highlights; empty notes hide the highlight. Sample notes are preview-only.
* Missing-proof reminder defaults to 15 minutes, configurable 1 minute–7 days. Once per eligible new unpaid order; skip when proof has ever arrived or the order is paid, cancelled or refunded.
* Background jobs recheck eligibility, skip outdated notifications and suppress duplicate events.

Dispatch timing depends on Action Scheduler/WP-Cron. Configure and test the site's mail/SMTP transport. Transport acceptance does not confirm inbox delivery; provider support is required for delivery confirmation. WooCommerce may also send order emails.

### Email Logs

Manager-only logs include notifications/tests, masked recipients, transport status, attempts and timestamps. Search/filter, default 20-row pagination, Screen Options, single/bulk clearing and Clear all. Retry definite failures after fixing mail setup; accepted/uncertain sends are not resent automatically.

Clearing visible logs does not cancel queued work or remove duplicate-send protection. Visible-log cleanup after 30 days defaults ON and can be disabled.

### Security, privacy and retention

Manager mutations require manage_woocommerce and nonces; AJAX/review operations require POST. Customer proof requires order authorization and a nonce. State/version checks, database locks and durable reservations protect payment decisions.

Screenshots use AES-256-GCM encryption and protected manager-only access; OpenSSL is required. Back up crmp_evidence_key with private evidence files; key loss makes screenshots unreadable. Use HTTPS and protect backups/exports. Legacy plaintext migrates in bounded manager-only batches. Restrict direct access to wp-content/uploads/crmp-private; configure a server-level rule for Nginx.

Stored data includes accounts, proof, customer/reviewer notes, dates, settings/templates and email operational data. Queue recipients/message snapshots are scrubbed after 30 days independently of visible-log preferences; minimal deduplication metadata remains. Permanent transaction removal also scrubs its queued private data. Proof has no automatic expiry. No dedicated personal-data exporter/eraser; managers control deletion through Trash. Uninstall retains data/key/evidence; deactivation clears scheduled email work.

No analytics/tracking, remote executable code or provider API calls. Readable production source is included.

## Verified deployment requirements

**Runtime verification covers 13 configurations.** Coverage includes PHP 8.1–8.5, Apache/Nginx, OpenLiteSpeed LSAPI, minimum/current WordPress and WooCommerce, HPOS, server-side proof validation, uploads, permissions, CSV exports, notifications and responsive Chromium layouts. The workflow includes official Plugin Check, plus representative Autoptimize, WP Super Cache and Redis checks. Exact coverage and limitations are in [the release report](reports/COMPATIBILITY-1.0.0.md).

New evidence directories receive Apache/OpenLiteSpeed rewrite guards, a denying index and an IIS configuration guard. Existing administrator rules are preserved. OpenLiteSpeed must load `.htaccess` rules. IIS is not a tested target.

Nginx does not read `.htaccess`. Add the following inside the site's `server` block, before the generic PHP regex location, then reload and verify direct private-file URLs return 403/404:

```nginx
location ~* /crmp-private(?:/|$) { return 403; }
```

The authenticated WordPress evidence endpoint still serves authorized decrypted images. New files remain encrypted; keep the evidence key with protected backups. Apply equivalent protection if a proxy or custom upload mapping serves files elsewhere.

For native uploads, PHP’s `upload_max_filesize` must allow the configured screenshot limit. PHP’s `post_max_size` and proxy request-body limits must also allow the full request: browser-prepared screenshots add roughly one-third base64 overhead. The test servers use an 8 MB upload limit and 16 MB POST/body limits; lower hosting limits can reject a valid image before WordPress handles it.

### Cache, minification and CDN configuration

Keep Cart, Checkout, My Account, order-received/order-key pages, Store API cart/checkout, `wc-ajax` requests and private proof/CSV endpoints out of full-page caches. Bypass known WooCommerce sessions and logged-in cookies. Receiving QR codes are public instructions; encrypted payment evidence under `wp-content/uploads/crmp-private` must remain blocked at the web server.

Use one JS/CSS optimizer at a time and preserve WordPress script dependency order. Minification, aggregation, CSS inlining and ordinary `defer` differ from delaying scripts until interaction. For interaction-delay systems (including Rocket Loader), exclude CardRaabta's `checkout.js` and `blocks.js` together with their WooCommerce checkout/Blocks, jQuery and `wp-element` dependencies. Keep admin settings/review scripts out of interaction delays too. Security is enforced on the server even if client scripts fail, but a broken dependency order can hide a gateway or prevent its UI from initializing.

When using Remove Unused CSS, safelist CardRaabta selectors/classes/IDs matching `crmp-` or `crmp_`, including dynamic status attributes, `[hidden]`, error/success, progress and expanded form states. Keep the plugin stylesheet available on checkout/order pages. An initial empty or hidden form is not evidence that its styles are unused. Purge optimizer/CDN caches after a same-version ZIP replacement; plugin asset URLs use content hashes.

Representative integration checks use Autoptimize (aggregate/minify/defer/inline CSS), WP Super Cache with WooCommerce/private-page exclusions, and Redis Object Cache on a disposable PHP 8.3/Nginx stack. Standalone transforms cover Terser, Lightning CSS, a PurgeCSS safelist, delayed asset delivery, and gzip/Brotli byte round trips. The release report records the checks verified for this release. These scenarios do not certify every commercial cache plugin, unsafelisted CSS removal, arbitrary script reordering or a live Cloudflare account. A CDN must respect private/no-store headers and explicit commerce bypass rules; compression does not replace access control.

## Installation

1. Minimum supported: WordPress 6.5, PHP 8.1 and WooCommerce 8.3; MySQL/MariaDB named locks and OpenSSL are required. Use the latest stable WordPress/WooCommerce updates for new installations.
2. Upload the ZIP through Plugins > Add New Plugin > Upload Plugin and activate.
3. Add your receiving details under CardRaabta Payments > Payment Methods.
4. Enable the gateway in WooCommerce > Settings > Payments; use PKR currency.
5. Review CardRaabta Settings, notifications and site mail transport.
6. Test checkout, proof/reviews, exports and email delivery on staging before live use.

## Support

Issues: https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/issues
Include versions and redacted steps. Never publish credentials, customer information, screenshots or private order links.
