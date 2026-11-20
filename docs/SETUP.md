# Store setup and troubleshooting

## Requirements

- WordPress 6.5+, PHP 8.1+ and WooCommerce 8.3+.
- OpenSSL for encrypting and reading payment screenshots.
- MySQL or MariaDB with named-lock support for coordinating payment reviews.
- PKR store currency and a receiving account for each payment method you enable.
- HTTPS and a [protected evidence uploads directory](HOSTING-SETUP.md).

## Configure your store

1. Install and activate the ZIP from [GitHub Releases](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/latest).
2. In **CardRaabta Payments → Payment Methods**, add the account title, receiving identifier and transfer instructions. Add a QR image if you use one.
3. Enable the gateway in **WooCommerce → Settings → Payments**.
4. In **CardRaabta Payments → Settings**, choose the checkout and order-page proof rules. Screenshot and date are required by default. If you allow orders without proof at checkout, the customer can submit it later from the order page.
5. Configure recipients and test mail delivery through your WordPress mail transport. Review the background notification queue and **CardRaabta Payments → Email Logs**.

## Verify on staging

- Place an unpaid order with a configured receiving method. Check the displayed amount, currency and receiving details. Test both Classic Checkout and Checkout Blocks if your store uses both.
- Submit sample proof as a customer. If you accept guest orders or later proof, test the corresponding order link and submission flow.
- Find the proof in **CardRaabta Payments → Transactions**. Check the order and independently confirm receipt in the receiving account before approving a real payment. Use separate staging orders to check approval and rejection, and confirm the customer sees the expected status.
- Verify screenshot access as a manager and direct-file denial using the [hosting guide](HOSTING-SETUP.md). Check that the expected notifications arrive and that a CSV export contains the expected test fields.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Gateway missing at checkout | Confirm PKR, an enabled receiving method, and the WooCommerce gateway setting. Review the hosting guide if script optimization is enabled. |
| Screenshot rejected | Use JPG, PNG or WebP within the configured size limit. Check PHP upload and POST limits and the proxy request-body limit. |
| Payment remains on hold | Open the Transactions screen and review the proof against the receiving account. An order placed without proof stays unpaid until the merchant verifies payment. |
| Email missing | Inspect Email Logs and check Action Scheduler/WP-Cron and mail transport. An accepted send can still be filtered or rejected downstream. |
| Proof disappears after a restore | Restore the encrypted evidence files together with the original database key. See the [storage report](../reports/DATA-STORAGE-1.0.0.md). |

See the [feature reference](FEATURES.md) for full payment, review and retention behavior, or [support](../SUPPORT.md) if the issue persists.
