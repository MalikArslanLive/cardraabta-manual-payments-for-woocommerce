# Hosting setup guide

Open this guide from **CardRaabta Payments → Setup Guide** in WordPress. The link opens GitHub in a new tab for users with `manage_woocommerce`, including administrators and WooCommerce shop managers. Opening the guide does not change settings or send payment/customer data to GitHub.

This guide explains private screenshot storage for **CardRaabta Manual Payments for WooCommerce 1.0.0**. Transfers are still verified manually by the merchant; these rules do not verify a payment.

## Do I need extra configuration?

| Your web server | What the plugin does automatically | What you or your hosting provider must check |
| --- | --- | --- |
| Apache | Creates `.htaccess` access-denial rules in the evidence folder when the first screenshot is saved. | Usually no manual rule is needed if `mod_rewrite` and directory overrides are enabled. Verify direct access is denied. Ask your host to enable the required override/rewrite support if it is ignored or fails. |
| OpenLiteSpeed | Creates the same `.htaccess` rules. | Usually no additional rule is needed when rewrite handling and automatic `.htaccess` loading are enabled. Your host may need to enable these in the virtual host. Verify direct access is denied. |
| Nginx, including Nginx serving uploads in front of Apache | Creates the evidence folder and encrypted files; `.htaccess` does not configure Nginx. | A hosting administrator must add an access-denial rule to the Nginx configuration that actually serves uploads, reload it and verify it. See the example below. |
| IIS | Creates `web.config` authorization rules in the evidence folder. | IIS URL Authorization must be installed and authorization configuration must be allowed at site/directory level. Your host may need to enable these. Verify direct access is denied. |

**Installing the plugin does not edit or reload server-wide configuration.** The plugin cannot grant itself Nginx/IIS administrative permissions. It creates directory guards alongside the first valid screenshot and preserves existing administrator-maintained guard files. Merely finding `.htaccess`, `web.config` or `index.php` is not proof that your server enforces them. The plugin does not automatically test effective HTTP protection or display a verified-protection status.

If you use reference-only proof, have screenshots disabled in both checkout and after-order proof policies, and have never stored screenshots, there are no private evidence files requiring these directory rules. If screenshots are enabled, arrange protection before accepting screenshot submissions. Disabling screenshots later or uninstalling the plugin does not delete retained evidence; keep its storage protected.

## Why these rules are needed

Payment screenshots are private. The plugin validates images in memory and stores authenticated AES-256-GCM ciphertext in `.crmp` files, not publicly viewable image previews. Authorized managers retrieve the decrypted image through WordPress capability/nonce checks.

Server rules add direct-request protection for the storage folder. Without effective rules, a server may allow directory listing or downloading the encrypted files. Encryption still applies; downloading ciphertext alone does not reveal the original screenshot without its database key. Public access to private storage is nevertheless unwanted and should be blocked. Normal payment screens may still work when a server rule is missing; working uploads are not proof of private HTTP storage.

The generated **`index.php` is empty**. It is not an access-denial rule and cannot prevent a request for a known file. No executable PHP is generated in the evidence directory.

## Find the folder that needs protection

The plugin resolves the base with `wp_upload_dir()` and uses exactly one evidence subdirectory:

```text
<WordPress uploads base>/cardraabta-manual-payments-for-woocommerce/
```

With default WordPress uploads, its URL path is:

```text
/wp-content/uploads/cardraabta-manual-payments-for-woocommerce/
```

WordPress multisite or custom uploads mappings can change the base. Your hosting administrator can inspect it using WP-CLI from the correct WordPress installation; on multisite select the site with `--url`:

```sh
wp eval '$uploads = wp_upload_dir(); echo $uploads["basedir"] . PHP_EOL . $uploads["baseurl"] . PHP_EOL;'
```

Protect the **uploads** directory above. Do not block `/wp-content/plugins/cardraabta-manual-payments-for-woocommerce/`, which contains public checkout styles and scripts. Public receiving-account QR images use normal WordPress media and do not belong in the private evidence folder.

## Apache

The plugin writes these rules inside its evidence folder:

```apache
RewriteEngine On
RewriteRule ^ - [F,L]
```

Your host must enable `mod_rewrite` and allow these directives through directory overrides, such as the appropriate `AllowOverride FileInfo` policy or equivalent server-level protection. No plugin-file editing is required. If overrides are disabled, ask the host to deny direct requests to the evidence folder in the site's Apache configuration instead.

After the first screenshot has created the folder, verify denial using the harmless test below. A server error is a configuration problem to fix, not a successful privacy check.

## OpenLiteSpeed

Enable rewrite handling and automatic `.htaccess` loading for the WordPress virtual host. For a hosting control panel, ask the provider to confirm these settings and that the private folder's rewrite rules are enforced. The plugin already creates the directory-level rules; it cannot change the host's virtual-host permissions/settings.

Verification covered actual OpenLiteSpeed LSAPI. This does not certify every LiteSpeed Enterprise or hosting-panel configuration. Always perform the direct-request check on your site.

## Nginx

Ask your hosting provider to place an equivalent rule in the site's active `server` block. This example covers default uploads URLs and default multisite site folders:

```nginx
location ~* /wp-content/uploads/(?:sites/[0-9]+/)?cardraabta-manual-payments-for-woocommerce(?:/|$) {
    return 403;
}
```

Place the denial rule before other matching regex locations, including the generic PHP location. Existing `^~` uploads locations, `alias` mappings or other routing rules may bypass regex matching; the host must integrate the denial into the location that actually handles the request. For a custom uploads URL, adapt the URL prefix. A filesystem path is not an Nginx URL-location pattern.

An authorized hosting administrator should validate and reload the configuration using the server's deployment process, for example:

```sh
nginx -t
# Only after validation succeeds:
systemctl reload nginx
```

Managed hosting may require its control panel or support team instead. If a CDN, proxy or separate media host serves uploads, enforce equivalent denial there and remove any previously cached private responses. An Apache backend guard alone is insufficient when Nginx serves the file directly.

## IIS

The plugin writes this `web.config` into the evidence directory:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
```

Ask the hosting administrator to:

1. Install/enable the IIS **URL Authorization** role service under Web Server → Security.
2. Allow site/directory-level configuration for `system.webServer/security/authorization`, using appropriate IIS Feature Delegation or an equivalent server-level denial.
3. Check for inherited authorization rules and verify that every direct evidence-file request is denied.

A locked configuration section can cause an IIS configuration error, while an unavailable/ineffective authorization module can leave the intended protection unenforced. Have the host resolve either condition; do not treat an HTTP 500 response as a completed setup.

The production guard was exercised on Windows Server 2022 / IIS 10 / PHP FastCGI, including positive controls before guard creation and denial afterward. This is evidence-guard coverage; full WordPress/WooCommerce checkout on IIS is outside that test.

## Verify protection on your own site

Ask your host to perform this check after the evidence folder exists, preferably on a staging site before accepting real screenshot submissions:

1. Use the hosting file manager or an authorized server account to create a temporary, harmless text file named `crmp-access-test.txt` inside the evidence folder. Put a unique non-sensitive marker in it; do not use customer data. Ensure the web-server account could otherwise read this test file, so file permissions alone are not producing a false pass.
2. Request its actual public URL in a logged-out/private browser window. Apache/OpenLiteSpeed/Nginx should return **403 or 404**; IIS may return **401, 403 or 404**. The marker must never appear. An HTTP 200 response, a blank folder index or a cached response does not establish protection. An HTTP 500 is a server configuration error to investigate.
3. Check the same file with a query string and check the directory URL. They must not expose the marker or list files. If a CDN/proxy serves uploads, verify that route too.
4. In WordPress, confirm an authorized manager can still view a valid screenshot from Transactions, and confirm checkout styles/scripts and normal public QR images remain accessible.
5. Delete the temporary test file. Keep the effective denial rules in place.

Use a harmless marker because this check deliberately tests whether a direct request can expose file contents. Never publish real evidence filenames or screenshots in a public support ticket.

## Message to send your hosting provider

> I use CardRaabta Manual Payments for WooCommerce. Please block all direct HTTP access and directory listing for the `cardraabta-manual-payments-for-woocommerce` subdirectory under this site's actual WordPress uploads base. Please check which server/proxy actually serves uploads. Apache/OpenLiteSpeed must enforce the generated rewrite rules; Nginx needs an equivalent server rule; IIS needs URL Authorization with permitted authorization configuration. Please verify with a temporary non-sensitive marker file and confirm denial, while keeping the plugin's public assets, normal receiving QR images and authorized WordPress screenshot endpoint working. The server-specific examples are in this guide.

## Other hosting limits and backups

- The PHP worker needs write access to its WordPress uploads storage. Ask your host for appropriate ownership/permissions; broad world-writable permissions are not a privacy solution.
- PHP `upload_max_filesize` must allow your configured screenshot limit. `post_max_size` and proxy request-body limits must allow the complete request, including roughly one-third base64 overhead for browser-prepared screenshots. See [deployment requirements](../README.md#verified-deployment-requirements).
- Keep checkout, order-key pages and private endpoints out of page/CDN caches. See [cache and optimization guidance](../README.md#cache-minification-and-cdn-configuration).
- Back up the database, `crmp_evidence_key` and encrypted evidence together in protected backups. Losing the key makes retained screenshots unreadable. Server denial rules do not replace encryption or secure backups.
