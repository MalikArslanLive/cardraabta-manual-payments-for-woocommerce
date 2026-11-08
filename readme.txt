=== CardRaabta Manual Payments for WooCommerce ===
Contributors: malikarslan
Tags: manual payments, bank transfer, woocommerce, payment proof, raast
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept manual bank, wallet and Raast payments in WooCommerce. Collect payment proof without a gateway merchant account or API keys.

== Description ==

Set up manual payments for PKR orders. Display your bank account, mobile wallet or Raast receiving details at checkout, then collect screenshots or transaction references for review. Use an account that can receive transfers; no provider API keys or merchant integration are needed. Verify actual funds before approving an order.

Independent third-party software; not affiliated with or endorsed by payment providers or WooCommerce. No bank/wallet API integration, automatic fund verification, card processing or automatic refunds.

= Receiving methods =
JazzCash, Easypaisa, SadaPay, NayaPay, Bank Transfer (IBFT), Raast, Zindigi, UPaisa, HBL Konnect, cash bank deposit and a custom method. Configure account title, number/IBAN/identifier, instructions and QR image. Selected methods show matching details. Never include PINs/passwords.

= Customer payment proof =

Classic Checkout, Checkout Blocks and HPOS are supported. Proof is required by default; merchants can allow unpaid orders and later submission. Configure screenshot, reference, date, note and confirmation independently for checkout/order updates or reuse checkout settings. Hidden fields cannot be required. Screenshot and date start required; other fields start disabled. Each actual submission always needs a screenshot or reference.

JPG, PNG and WebP screenshots support a configurable 1–8 MB limit (default 5 MB), with server-side type, size and dimension validation. The picker shows filename, preparation progress and ready status; server confirmation is separate. Dates must be valid and not in the future.

Pending/Rejected proof updates retain the same transaction ID and earlier versions in manager-only history. Choose 1–10 replacements between completed reviews (default 3); changing the limit does not reset usage. Paid orders cannot resubmit. Order forms open on an explicit customer action. Registered customers log in; guests use private order-key links and may need billing-email verification.

= Transaction dashboard =

Search order/reference, filter status, expand details and use paginated All/Trash lists (20 rows by default; Screen Options 1–100). Confirm bulk Approve, Reject, Trash, Restore and permanent deletion. Selection is page-bound; stale/ineligible records are skipped. Bulk Reject requires a customer-visible reason; approval accepts an optional note.

Pending proof cannot be trashed before review. Trash/Restore change visibility, not payment, stock or refunds. Permanent removal deletes proof, revisions and notes; minimal receipt/reference/decision data remains to prevent duplicate approval. Customers do not see admin Trash/removal labels.

= CSV exports =

Two manager-only, nonce-protected exports: retained current transactions (including Trash) and current/previous proof history. Include references, methods, amounts/currencies, screenshot IDs, dates, customer/reviewer notes, versions, decisions, timestamps, proof source, confirmation and recorded field settings. Closed at records when a sibling submission was closed because its order was paid. Paid through transaction identifies the winning ledger record, not the bank reference; it is blank when no matching receipt is known. Missing audit fields are not invented; purged records are excluded. Images are not embedded. Spreadsheet formula-prefix protection applies.

= Notifications and Email Logs =

Eleven configurable customer/admin notifications cover proof received/updated, review decisions, missing proof and interrupted reviews. All five customer types default ON; saved choices are preserved. Choose 1–20 admin recipients and customize subjects, headings, messages, buttons and reviewer-note highlights using WooCommerce email branding. Sample desktop/mobile previews, restore defaults and logged test sends are included.

Missing-proof reminders default to 15 minutes (configurable 1 minute–7 days), recheck eligibility and avoid duplicate events. Dispatch depends on Action Scheduler/WP-Cron. Configure and test mail/SMTP; transport acceptance does not confirm inbox delivery.

Manager-only Email Logs provide masked recipients, transport status, attempts, filters, pagination, retry for definite failures and single/bulk clearing. Clearing logs does not cancel queued work or duplicate-send protection. Optional 30-day visible-log cleanup defaults ON.

= Hosting setup guide =

Use CardRaabta Payments > Setup Guide for server-specific requirements, automatic directory guards, Nginx/IIS setup, missing-rule consequences and a direct-access verification checklist. It opens the GitHub guide in a new tab and sends no payment/customer data. Apache/OpenLiteSpeed may need no extra rule when directory rules are enforced; Nginx needs a hosting rule and IIS needs URL Authorization with permitted configuration.

= Security, privacy and retention =

Manager mutations require manage_woocommerce and nonces; AJAX/review operations require POST. Customer proof requires order authorization and a nonce. State/version checks, database locks and durable reservations protect payment decisions.

Screenshots use AES-256-GCM encryption and protected manager-only access; OpenSSL is required. Back up crmp_evidence_key with private evidence files; key loss makes screenshots unreadable. Use HTTPS and protect backups/exports. New evidence directories receive an empty index.php and server access rules; no executable PHP is generated. Restrict direct access to wp-content/uploads/cardraabta-manual-payments-for-woocommerce; configure a server-level rule for Nginx. The base directory is resolved with wp_upload_dir(), including custom and multisite upload paths. IIS requires URL Authorization and permission for site-level authorization rules; its guard was runtime-tested on IIS 10 with PHP FastCGI. Empty index.php alone does not protect evidence files.

Stored data includes accounts, proof, customer/reviewer notes, dates, settings/templates and email operational data. Queue recipients/message snapshots are scrubbed after 30 days independently of visible-log preferences; minimal deduplication metadata remains. Permanent transaction removal also scrubs its queued private data. Proof has no automatic expiry. No dedicated personal-data exporter/eraser; managers control deletion through Trash. Uninstall retains data/key/evidence; deactivation clears scheduled email work.

No analytics/tracking, remote code or provider APIs. Readable source is included. Exclude private commerce pages from page caches; see GitHub README for optimizer delay exclusions and dynamic CSS safelists. Development/source/support: https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce

== Installation ==

1. Minimum: WordPress 6.5, PHP 8.1, WooCommerce 8.3; MySQL/MariaDB named locks and OpenSSL required. Latest stable updates recommended.
2. Upload the ZIP through Plugins > Add New Plugin > Upload Plugin and activate.
3. Add your receiving details under CardRaabta Payments > Payment Methods.
4. Enable the gateway in WooCommerce > Settings > Payments; use PKR currency.
5. Review CardRaabta Settings, notifications and site mail transport.
6. Test checkout, proof/reviews, exports and email delivery on staging before live use.

== Frequently Asked Questions ==

= Does this verify funds automatically? =
No. A manager must check received funds before approval. No payment-provider API is used.

= Is checkout proof mandatory? =
Yes by default. Merchants can allow empty-proof unpaid orders and later submission. Every actual submission still needs valid proof.

= Can I accept a reference without an image? =
Yes. Enable Reference and make it Required; disable Screenshot or leave it optional. Keep one proof option enabled. If both are optional, each submission still needs one.

= Can checkout and order fields differ? =
Yes. Configure them separately or reuse checkout settings. Screenshot and date are required by default; other fields start disabled.

= Can proof be updated? =
Pending/Rejected proof can be replaced under the same ID, with earlier versions retained. Default 3 updates; choose 1–10 between completed reviews. Paid orders cannot resubmit.

= Do CSVs contain screenshots? =
Only attachment IDs, without embedded images or public URLs. Managers view screenshots through authorized dashboard links.

= Why has an accepted email not arrived? =
Transport acceptance does not prove inbox delivery. Check SMTP/provider logs and spam. Scheduled work needs Action Scheduler/WP-Cron.

= Can I use cache/minification plugins? =
Exclude private commerce pages/endpoints, preserve JS dependencies and safelist dynamic crmp- / crmp_ CSS. See GitHub deployment instructions.

= What happens on uninstall? =
Records, settings, encrypted files and key remain. Deactivation cancels scheduled email work. Managers can remove proof beforehand; minimal receipts remain for duplicate-payment protection.

= What is the license? =
GPL-2.0-or-later. The complete GPL v2 text is included in LICENSE.

== Support ==

[Source code](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce) · [Report an issue](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/issues)
Share versions and redacted steps. Keep credentials and customer data private.
