# Release package

Build the WordPress.org installable ZIP with `python3 tools/build-release.py`. The current release is v1.0.0. The builder includes only the 20 production files and verifies their bytes against the checkout; development files are excluded. GitHub source archives are not the installable release package.
