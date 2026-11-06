# Security policy and operational notes

See README.md for full payment, storage and review recovery behavior. This plugin collects evidence; only the merchant can independently establish that funds arrived.

Verification uses durable verifying/rejecting ledger reservations before WooCommerce transitions. Completion is checked before the ledger is finalized. Interrupted verification can be resumed with the same decision when the order's amount/currency/method and transaction ID match. Duplicate checks include reserved references and receiving-method locks, including database collation variants.

Checkout screenshots sent as encoded data are decoded and validated in memory. The plugin writes the encrypted evidence envelope directly and removes incomplete writes. New evidence uses authenticated AES-256-GCM encryption with random IVs and filenames, a non-executable .crmp envelope and a private database key. Manager-only retrieval checks capabilities/nonces, confines real paths and sends no-store/nosniff/sandbox headers. Bound image sizes/dimensions and evidence reads limit malformed-file resource use.

Retain direct server access-denial rules for the private evidence directory as defense in depth. Keep the encryption key with database-and-file backups and resolve administrator notices promptly.

Checkout proof is mandatory by default. Only a merchant setting can permit an empty-proof unpaid order; request parameters cannot waive that requirement. Any actual submission needs an enabled screenshot or reference and must meet its recorded field policy. Hidden fields cannot remain required, and order-page submissions always require actual proof. Classic Checkout and Store API validation share the server-side rules.

Order proof requires ownership/order key and an order-specific nonce. Administrative reviews, exports and email management require the `manage_woocommerce` capability and action-specific nonces. WooCommerce authenticates Classic Checkout and Store API requests before invoking gateway callbacks. MySQL/MariaDB named locks must be supported. Third-party order changes and side effects are outside plugin locks; reconcile conflicting states manually. HTTPS, least-privilege administrator accounts, patched WordPress/WooCommerce, protected backups and appropriate server configuration remain site responsibilities.

No card PAN/CVV, wallet MPIN or OTP is collected. No payment-provider API or telemetry is used. Records survive order deletion/uninstall; merchants define and implement retention and privacy cleanup.

Report suspected vulnerabilities privately via GitHub private vulnerability reporting if available. Otherwise request a private contact through the author's WordPress.org profile before sending details. Do not post customer/payment data in public issues. Automated tests are not a zero-vulnerability certificate.
