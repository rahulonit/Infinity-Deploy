# Troubleshooting

## First-response checklist

1. Record the exact UI error before retrying.
2. Confirm both sites are running and open normally.
3. Test both connections.
4. Confirm Bridge and add-on versions.
5. Open **Tools → Infinity Deploy** on the failed endpoint and inspect recent activity.
6. Check WordPress debug/PHP logs, Local site logs, Local add-on logs, and proxy/WAF logs.
7. Check disk space and all body-size/time limits.
8. If a full migration partially ran, inspect both database and files before retrying.

## Connection problems

### HTTP 401 or incorrect password

- Use the actual WordPress username/email, not the Application Password’s label.
- Generate a new Application Password and paste it; spaces are accepted and removed.
- Do not use the primary WordPress password.
- Confirm application passwords are not blocked by a security plugin or proxy.
- Verify the `Authorization` header reaches PHP. Some Apache/FastCGI configurations strip it.

### HTTP 403 / `rest_forbidden`

- Confirm the account has administrator capabilities.
- Enable authenticated deployments under **Tools → Infinity Deploy**.
- For theme operations, confirm `install_themes` and `update_themes` capabilities.
- Confirm the theme slug is on the Bridge allowlist.

### HTTP 404

- Confirm the Bridge is installed and active on that exact WordPress installation.
- Include a WordPress subdirectory in the configured URL when applicable.
- Re-save permalinks.
- Confirm a security plugin/CDN is not blocking `/wp-json/`.

### Unsafe redirect

Use the canonical WordPress URL so requests do not redirect between `www`/non-`www`, domains, or HTTP/HTTPS. Authenticated redirects to a different host or HTTPS downgrade are rejected by design.

## Transfer failures

### `socket hang up` / `ECONNRESET`

Use Local add-on 1.0.3 or later; earlier streaming uploads could declare a multipart length without writing the file body. If current code still fails:

- Check PHP, Nginx/Apache, CDN, WAF, and load-balancer timeouts.
- Check `client_max_body_size`, `upload_max_filesize`, and `post_max_size`.
- Check memory/disk exhaustion and PHP worker termination.
- Try one component at a time to identify the package causing the reset.
- Bypass a proxy/CDN temporarily only within your authorized maintenance procedure.

### HTTP 413 / `package_too_large`

Increase the Bridge package limit and every smaller upstream/PHP limit, then restart the applicable service. Split full migration components where possible. The largest individual component ZIP—not total site size—must fit.

### Ten-minute timeout

Reduce component size, remove caches/backups from source folders, increase server resources, or use a hosting-native migration path for exceptionally large sites. The client timeout is ten minutes and is not currently configurable in the UI.

### `zip_missing` / `zip_unavailable`

Enable PHP’s Zip extension for the PHP runtime used by WordPress, then restart PHP/the site. CLI PHP and web PHP may load different configurations.

### `unsafe_zip`, `unsafe_zip_scope`, or `unsafe_zip_symlink`

Do not hand-edit site packages. Only uploads/plugins/themes paths are accepted; traversal, absolute paths, symlinks, and Bridge replacement are rejected. Rebuild from a trusted source.

## Database problems

### Serialized-data fatal or `__PHP_Incomplete_Class`

Use Bridge 1.0.1 or later. It falls back to token-level replacement when a serialized object’s class is unavailable on the destination.

### View creation or `wp_wsm_*` errors

Use Bridge 1.0.2 or later. It defers views until base tables exist and removes source-only database qualifiers and definers. If a plugin that owns the views is no longer used, deactivate/remove it and confirm whether its tables/views are needed before cleanup.

### Wrong URLs after import

- Confirm the configured source and destination URLs are canonical and exact.
- Clear page, object, CDN, and browser caches.
- Search for hard-coded or encoded URLs outside standard serialized values.
- Inspect custom plugin tables and generated files; database replacement does not rewrite URLs embedded in filesystem artifacts.

### Cannot log in after clone/push

Restore the latest database snapshot. Normally **Preserve Users/Customers** should remain checked. When cloning to Local it preserves Local users; when pushing to Live it preserves Live users.

### Table-prefix issues

Confirm the destination `wp-config.php` prefix is correct. Infinity Deploy remaps option/usermeta prefix keys, but custom SQL or plugin-specific prefix assumptions may need manual handling.

## Health-check failures

- Open each configured path directly and note HTTP status/redirects.
- Check maintenance mode, fatal errors, missing theme/plugin dependencies, and rewrite rules.
- Confirm WordPress can make loopback requests to its own `home_url`.
- If only optional default paths fail, customize health paths rather than relying on routes the site does not have.
- Treat a critical failure after a production push as an incident; assess the database snapshot and host backup before further changes.

## Logs

- Bridge: **WordPress → Tools → Infinity Deploy → Recent deployment activity** (last 20 displayed, 200 retained).
- WordPress/PHP: hosting error log or `wp-content/debug.log` when authorized and enabled.
- Local site: Local’s site logs for web server, PHP, and database.
- Local add-on: Local application/add-on logs.

Audit log context is intentionally concise and sanitized. It is not a complete transfer transcript.
