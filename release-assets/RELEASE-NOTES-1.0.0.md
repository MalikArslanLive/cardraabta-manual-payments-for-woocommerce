## Manual payments with configurable proof, review history and notifications

**CardRaabta Manual Payments for WooCommerce** lets Pakistani stores accept manual bank, wallet and Raast transfers in **PKR** through one WooCommerce gateway. Show receiving instructions, collect payment proof at checkout or later according to merchant settings, review the actual funds received and manage the complete proof/review history from your dashboard.

No payment-provider merchant integration or API keys are needed. You need a receiving bank/wallet account and a store manager who verifies each transfer independently.

### Supported receiving methods

JazzCash, Easypaisa, SadaPay, NayaPay, Bank Transfer (IBFT), Raast, Zindigi, UPaisa, HBL Konnect, Cash Bank Deposit and a custom receiving method. Configure account titles, account numbers/IBANs/identifiers, instructions and optional QR images.

### Configurable checkout proof and later submission

- Supports **Classic Checkout**, **Checkout Blocks** and **HPOS**.
- **Mandatory checkout proof is ON by default.** Merchants can disable it to allow unpaid/On hold orders with empty proof and later submission. Invalid or incomplete proof attempts are still rejected, and later submissions always need actual proof.
- **Default fields: screenshot and payment date, both required.** Reference, customer note and confirmation checkbox start disabled.
- Highlighted settings guidance recommends requiring a proof field and clearly explains that every proof submission needs a screenshot or reference, even when both are individually optional.
- Independently enable/disable each proof field and choose whether it is optional or required.
- Use the same fields at checkout and after order placement, or configure a separate order-update policy.
- At least **Screenshot or Reference** must stay enabled. Hidden fields cannot remain required. If both are individually optional, the customer must still supply at least one.
- To accept a reference and date without an image, enable reference and date and disable screenshot, or leave screenshot optional when both proof choices are enabled.
- Order-page submission/update forms remain collapsed until the customer opens them explicitly.

The screenshot picker shows the filename, preparation progress and a ready-to-submit status. Locally selected files are distinguished from server-confirmed submissions.

Screenshot uploads support **JPG, PNG and WebP**, a configurable **1–8 MB** limit (default **5 MB**) and server-side type, size and dimension validation. Enabled payment dates must be valid and not in the future.

### Proof updates and transaction history

- Replace pending/rejected proof under the **same transaction ID**, with a new proof version.
- Preserve earlier references, screenshots, methods, dates, customer notes and review decisions in manager-only revision history.
- Configure **1–10 proof replacements** between completed reviews; default **3**. Paid orders cannot resubmit.
- Record each submission's source, actual confirmation state and field settings at submission time. Later settings changes do not reinterpret old records; unknown legacy values remain **Not recorded**.
- Reject stale submissions/reviews and preserve the cart when checkout proof cannot be stored.

### Dashboard and CSV exports

- Inline View/Hide Details, order/reference search, status filters, paginated All/Trash lists and per-user page size.
- Bulk Approve/Reject with confirmations, current-proof checks and clear skipped-record results.
- Pending proof is protected from Trash/permanent deletion before review.
- **Two manager-only CSV exports:** current retained transactions and current/previous proof history.
- Fixed columns include references, methods, amounts/currencies, payment dates, separate customer/reviewer notes, versions, decisions, proof source, confirmation and recorded field settings.
- Screenshots appear as **attachment IDs**, without embedded image files or public image URLs. Exports retain Unicode/newline quoting and spreadsheet formula-prefix protection.

### Notifications and Email Logs

- **Eleven notification types** across customers and administrators, each with its own enable/disable switch.
- Proof-received/updated, verified/rejected, missing-proof and interrupted-review notifications as applicable.
- Configurable recipients, subject/heading/message/button text and reviewer-note highlights using WooCommerce email branding.
- Desktop/mobile sample previews, restore defaults and logged test sends.
- Missing-proof reminders, eligibility rechecks and duplicate-event protection.
- Manager-only Email Logs with masked recipients, filters, pagination, retry for definite failures and retention/clearing controls.

Mail-server acceptance does not confirm inbox delivery; configure and test your site's mail transport.

### Security and reliability improvements

- AES-256-GCM private screenshot storage, manager-only evidence access and Media/REST permission hardening.
- Order authorization, nonces, payment-state/proof-version checks and database locks around sensitive operations.
- Safe handling of unusable encryption keys, temporary-upload cleanup and interrupted payment reviews without repeating payment completion.
- Automatic private-directory guards, explicit PHP 8.4/8.5 CSV escaping, Unicode handling and content-based asset cache versions.
- Responsive checkout/order forms, clearer receiving/QR details, file-reader race handling and accessible clipboard fallback.

**Hosting requirement:** Nginx needs a server-level rule denying direct access to the private evidence directory. OpenLiteSpeed must load the supplied `.htaccess` guards. See the [deployment instructions](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce#verified-deployment-requirements). Protect the evidence key and backups; proof does not have automatic expiry.

### Requirements and installation

**Minimum: WordPress 6.5 · WooCommerce 8.3 · PHP 8.1**

Use the latest stable WordPress/WooCommerce updates for new installations, HTTPS, PKR currency, OpenSSL/Fileinfo and MySQL/MariaDB with named-lock support.

1. Download **`cardraabta-manual-payments-for-woocommerce-v1.0.0.zip`** from **Assets** below. GitHub source archives also contain development files.
2. Upload through **Plugins → Add New Plugin → Upload Plugin** and activate.
3. Configure **CardRaabta Payments → Payment Methods**, then enable the gateway under **WooCommerce → Settings → Payments**.
4. Review proof-field settings, recipients, notification templates and hosting protection.
5. Test checkout, payment review and email transport on staging before accepting live payments.

Keep the database, private evidence and encryption key together in backups. Review the field and notification defaults before enabling checkout.

### Verified release checks

The release workflow covers **13 runtime configurations**, including:

- **PHP 8.1–8.5** on Apache/PHP-FPM and Nginx/PHP-FPM.
- **OpenLiteSpeed 1.9.4 / PHP 8.2 LSAPI**.
- MySQL and a MariaDB scenario.
- Minimum **WordPress 6.5 / WooCommerce 8.3**, plus the current stable versions observed in testing: **WordPress 7.1.4 / WooCommerce 11.2.2**.
- Official Plugin Check, including the WordPress 6.5 target; real checkout/upload/privacy/export/email/HPOS tests; and responsive Chromium checks at **320, 360, 390, 768 and 1365 px**.

The [compatibility report](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/blob/v1.0.0/reports/COMPATIBILITY-1.0.0.md) documents exact coverage and limits; this does not certify every host/theme or guarantee the absence of vulnerabilities.

### Frequently asked questions

#### Does this automatically verify transfers?

No. Check the actual funds in your receiving account before approving payment. The plugin does not connect to payment-provider APIs.

#### Is payment proof required at checkout?

Yes, by default. Merchants can allow unpaid/On hold orders with empty proof and later submission. Any actual submission must meet its configured rules.

#### Can I accept a reference without a screenshot?

Yes. Enable Reference and make it Required; disable Screenshot or leave it optional. Keep at least one proof option enabled. If both are optional, each submission still needs one.

#### Can checkout and order-page fields differ?

Yes. Configure them separately or reuse checkout settings. Screenshot and date are required by default; reference, note and confirmation start disabled.

#### Can customers replace payment proof?

Pending/Rejected proof can be replaced under the same transaction ID. Earlier versions remain in manager-only history. The default limit is 3 updates between completed reviews; choose 1–10. Paid orders cannot resubmit.

#### Are screenshots included in CSV exports?

Exports contain attachment IDs, not image files or public URLs. Authorized managers view private screenshots from the dashboard.

#### What do Closed at and Paid through transaction mean?

Closed at records when a sibling submission was closed because its order was paid. Paid through transaction identifies the winning ledger record; it is blank if no matching receipt is known.

#### Why does an email say accepted but not arrive?

Mail-transport acceptance is not inbox delivery. Check SMTP/provider logs and the recipient spam folder. Scheduled reminders depend on Action Scheduler/WP-Cron.

#### Can I use caching or minification?

Exclude private commerce pages and endpoints from page caches, preserve script dependencies, and safelist dynamic crmp- / crmp_ CSS. See the GitHub deployment instructions.

#### What happens when I uninstall?

Plugin records, settings, encrypted evidence and its key are retained. Deactivation cancels scheduled email work. Use manager-approved proof deletion before uninstalling if needed; minimal receipts remain for duplicate-payment protection.

#### What license applies?

GPL-2.0-or-later. The unmodified official GNU GPL v2 text is included in LICENSE; the plugin permits version 2 or any later version. The FSF copyright and example names belong to that standard license document; plugin authorship is identified in the plugin header.

### Links

[Source and documentation](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce) · [WordPress.org listing](https://wordpress.org/plugins/cardraabta-manual-payments-for-woocommerce/). The installable package includes the WordPress.org readme and GPL license.

### Package integrity

The installable ZIP contains **20 production files** and matches the tagged source and verified release package byte for byte.

**SHA-256**
```text
{{ZIP_SHA256}}
```

**Manual verification:** check the actual received funds before approval. References, screenshots and customer confirmations are assertions, not automatic proof of settlement. This plugin does not connect to provider payment APIs, process cards or initiate refunds. It is independent third-party software, without provider or WooCommerce endorsement.

**License:** GPL-2.0-or-later. For support, [open an issue](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/issues) with versions and redacted steps; never post customer information, screenshots, credentials or private order links publicly.
