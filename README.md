# CardRaabta Manual Payments for WooCommerce

[![Latest release](https://img.shields.io/github/v/release/MalikArslanLive/cardraabta-manual-payments-for-woocommerce?label=release)](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/latest)
[![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress)](docs/SETUP.md#requirements)
[![WooCommerce 8.3+](https://img.shields.io/badge/WooCommerce-8.3%2B-96588A)](docs/SETUP.md#requirements)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](docs/SETUP.md#requirements)
[![GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-3d5816)](LICENSE)
[![Security and WordPress checks](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/actions/workflows/security.yml/badge.svg?branch=main)](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/actions/workflows/security.yml)
[![GitHub issues](https://img.shields.io/github/issues/MalikArslanLive/cardraabta-manual-payments-for-woocommerce)](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/issues)
[![GitHub stars](https://img.shields.io/github/stars/MalikArslanLive/cardraabta-manual-payments-for-woocommerce)](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/stargazers)

Accept manual bank transfers, mobile wallet payments and Raast in WooCommerce. Customers send PKR to your existing receiving account and submit proof. You check the funds in that account before approving the WooCommerce order.

[Download installable ZIP](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/download/v1.0.0/cardraabta-manual-payments-for-woocommerce-v1.0.0.zip) · [All releases](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases) · [Store setup](docs/SETUP.md) · [Support](SUPPORT.md)

## Get started

1. Upload the release ZIP under **Plugins → Add New Plugin → Upload Plugin** and activate it.
2. Open **CardRaabta Payments → Payment Methods** and add your bank, wallet or Raast receiving details.
3. Set the store currency to **PKR** and enable the gateway under **WooCommerce → Settings → Payments**.
4. Review the proof fields and notifications under **CardRaabta Payments → Settings**. Complete the [hosting checks](docs/HOSTING-SETUP.md) before accepting screenshots.
5. Place a staging order, confirm the transfer in your receiving account, and review the proof under **CardRaabta Payments → Transactions**.

Use the installable release asset for WordPress. See the [store setup and troubleshooting guide](docs/SETUP.md) for prerequisites and a staging checklist.

## What the plugin supports

| Area | Features |
| --- | --- |
| Receiving methods | JazzCash, Easypaisa, UPaisa, SadaPay, NayaPay, bank transfer or cash deposit, Raast, Zindigi, HBL Konnect and custom methods |
| Checkout | Classic Checkout and Checkout Blocks, with configurable proof fields and optional later submission from the order page |
| Payment review | Approve or reject proof, review revisions, and export current transactions or proof history as CSV |
| Notifications | Customer and manager emails, missing-proof reminders, templates and delivery logs |
| Storage | Encrypted screenshots, manager-only retrieval, and retained evidence on uninstall |

## Documentation

| Guide | Covers |
| --- | --- |
| [Store setup](docs/SETUP.md) | Requirements, first order, staging checklist and troubleshooting |
| [Feature reference](docs/FEATURES.md) | Proof rules, revisions, transaction review, CSVs, email and retention |
| [Hosting setup](docs/HOSTING-SETUP.md) | Evidence folder protection, caching and server-specific setup |
| [Testing](docs/TESTING.md) | Local checks, WordPress fixtures and the GitHub Actions matrix |
| [Compatibility report](reports/COMPATIBILITY-1.0.0.md) | Release-specific platform coverage and limits |
| [Data storage report](reports/DATA-STORAGE-1.0.0.md) | Stored data, backups and cleanup |
| [Contributing](CONTRIBUTING.md) and [release packaging](release-assets/README.md) | Development workflow, review and reproducible packaging |
| [Changelog](CHANGELOG.md), [support](SUPPORT.md) and [security policy](SECURITY.md) | Release history and how to report problems |

Developed by [Malik Arslan](https://malikarslan.com/). Licensed under [GPL-2.0-or-later](LICENSE).
