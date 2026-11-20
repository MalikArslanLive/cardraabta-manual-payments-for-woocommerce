# Release package

Build the WordPress.org installable ZIP with `python3 tools/build-release.py`. The current release is v1.0.0. The builder includes only the 20 production files and verifies their bytes against the checkout; development files are excluded. GitHub source archives are not the installable release package.

## Verify a build

From the repository root, build to a temporary path and compare it with the ZIP tracked in the matching release checkout.

```bash
python3 tools/build-release.py /tmp/cardraabta-build.zip
cmp /tmp/cardraabta-build.zip release-assets/cardraabta-manual-payments-for-woocommerce-v1.0.0.zip
```

Download the installable ZIP and its matching `.sha256` from [GitHub Releases](https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/releases/tag/v1.0.0). With both files in the same directory, run `sha256sum -c cardraabta-manual-payments-for-woocommerce-v1.0.0.zip.sha256`.

The [manual publishing workflow](../.github/workflows/publish-package.yml) builds from the `v1.0.0` tag and replaces that release's ZIP, checksum and notes. Confirm the tag and intended release before dispatching it.
