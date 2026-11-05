# Contributing

Use WordPress Coding Standards. Do not commit payment screenshots, transaction CSV exports, credentials, customer details, or WordPress uploads. Changes must be reviewed for authorization, CSRF, SQL injection, output escaping, and secure evidence handling. Test both WooCommerce classic and Blocks checkout, PHP compatibility, and payment review transitions. Report security issues privately.

Build the production-only ZIP with `python3 tools/build-release.py` and preserve source/ZIP byte parity. The release workflow covers the declared minimum and current supported runtimes. Plugin Check must report zero errors/warnings: its default CLI exit status alone is insufficient; use the strict report gate in `tools/ci/check-plugin-report.py`. Keep dynamic proof states in CSS safelists and verify private commerce pages remain uncached.

Keep README, WordPress readme FAQs and release notes consistent with actual behavior. Do not change the GPL license text or remove retained evidence/audit data as part of uninstall without a separately reviewed deletion design. Version headers and Stable tag must agree. Documentation-only changes do not require repeating unrelated payment tests; validate changed files, package integrity and relevant checks.
