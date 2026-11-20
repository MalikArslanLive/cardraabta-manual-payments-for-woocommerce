# Data storage and filesystem review — 1.0.0

## Database

All table names use the current site's WordPress table prefix; `wp_` is only an example.

| Location | Data |
| --- | --- |
| `options` | Receiving account configuration, public QR attachment IDs, gateway title/enabled state, proof field rules, upload/replacement limits, email templates/recipients/preferences, schema version, reconciliation cursors and scheduling metadata. |
| `options.crmp_evidence_key` | Random 32-byte AES key represented as 64 hexadecimal characters, created without autoload. This is a secret stored in the database, not a separately encrypted key vault. Protect database backups together with evidence backups. |
| `crmp_transactions` | Order ID, method, amount/currency, reference, configured customer date/note/confirmation, recorded field policy/source, screenshot attachment ID, versions/replacement count, submission/review status and timestamps, reviewer/note, trash/purge/closure metadata. Evidence uses attachment IDs; no screenshot_path column or plaintext migration is included. |
| `crmp_proof_revisions` | Earlier proof references/attachment IDs, field values and recorded policy, proof version, submission/replacement dates and past review decisions/reviewer/notes. |
| `crmp_email_queue` | Event deduplication key, notification template, order/transaction/version IDs, actual recipient, masked recipient, message snapshot, pending/accepted/failed status, attempts/errors and timestamps. UI masking does not mean full recipients are absent from the database. |
| `posts` / `postmeta` | Private evidence attachment records, `_wp_attached_file` relative path and `_crmp_private_evidence` marker; public QR images use normal WordPress media records. |
| WooCommerce order storage | Standard order/billing data and plugin order metadata such as selected method, verified transaction ID and missing-proof reminder state. WooCommerce chooses HPOS or legacy post storage. |
| WordPress scheduler / Action Scheduler | Job scheduling and order/job arguments; no screenshot image bytes. |
| User metadata / transients | Manager pagination preferences and temporary review notices. |

The plugin uses WordPress's Options API (`get_option`, `update_option`, `add_option`) for settings. Existing custom save handlers use capability/nonce checks and sanitization. This review fix does not convert them to a new Settings API form architecture. Transaction ledgers use custom tables rather than settings rows.

Screenshot image bytes are not stored in the transaction database tables. Most database fields, including notes/receiving details/email snapshots, are ordinary database values, not application-encrypted fields.

## Files

The uploads base is obtained from `wp_upload_dir()` at runtime. The plugin does not hard-code the site's filesystem uploads path. A default installation produces:

```
wp-content/uploads/cardraabta-manual-payments-for-woocommerce/
  .htaccess
  index.php
  web.config
  evidence-<random-identifier>.crmp
```

`index.php` is zero bytes: no PHP code is generated. `.htaccess` denies Apache/OpenLiteSpeed direct requests and `web.config` contains IIS access rules. Administrator-maintained existing files are preserved. The actual guard passed on Windows Server 2022 / IIS 10 with PHP 8.3 FastCGI. IIS needs URL Authorization installed and authorization rules delegated to the site; this is focused guard coverage, not full WordPress checkout coverage.

Each evidence file contains a CRMP1 marker and a base64-encoded IV, authentication tag and AES-256-GCM ciphertext. Base64 is only the envelope: the underlying screenshot is encrypted. Files use restrictive permissions. The original JPG/PNG/WebP is validated and decrypted in memory for an authorized manager response; no public preview thumbnails are generated for this encrypted storage flow.

All evidence belongs to the plugin-slug directory. The reader accepts only authenticated encrypted .crmp files in that directory. Alternate folders and plaintext image files are rejected. This initial release has no previous-version evidence reader or migration process. Private attachments use the _crmp_private_evidence metadata marker; titles alone do not classify ordinary media as evidence.

Custom uploads bases are exercised by regression tests. This verifies runtime path resolution; it is not a full WordPress multisite deployment certification.

For Nginx, use the server rule in README.md and adapt its URL prefix for custom upload mappings. The rule is restricted to uploads URLs so it does not block similarly named plugin assets. Apache/OpenLiteSpeed require enabled rewrite/htaccess handling. File permissions and encryption do not replace direct-request protection.

Receiving QR codes are public payment instructions selected through the WordPress media uploader. They use normal media storage, not private screenshot storage. Their attachment IDs are stored in receiving settings.

CSV exports stream through `php://output` to the authorized manager's browser; the plugin does not save export CSV files on the server. Exception logging delegates to WooCommerce's configured logger; its storage is controlled by WooCommerce.

## Filesystem boundaries and retention

Production code creates encrypted evidence and guard files inside runtime uploads storage. It does not write plugin source, WordPress core files, another plugin/theme, or a private data folder directly under wp-content. Developer tests/build scripts are excluded from the installable ZIP; some tests intentionally modify a disposable installed copy and are never production runtime code.

Uninstall retains records, settings, encrypted files and their key. The actual uninstall/reactivation regression verifies retained ledger/settings/key/ciphertext and successful decryption after reactivation. Deactivation cancels scheduled notification work. Managers can explicitly purge proof through the protected transaction workflow; minimal payment receipt metadata remains for duplicate-payment protection. Evidence has no automatic expiry. Email snapshot/recipient cleanup runs after 30 days independently of visible-log preferences; visible-log cleanup defaults on. Protect backups/exports and restore database/key together with evidence files.

Version and Stable tag remain 1.0.0. The GPL license and uninstall policy are unchanged. No changelog was added.
