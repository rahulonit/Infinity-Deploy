=== Infinity Deploy Bridge ===
Contributors: infinity
Tags: deployment, local, migration, staging, backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure WordPress endpoint for the Infinity Deploy Local add-on, with selective sync, full migration, backups, health checks, and rollback.

== Description ==

Infinity Deploy Bridge is the WordPress half of the Infinity Deploy deployment system. Install it on both a Local WordPress site and the connected Live or staging site. The separate Infinity Deploy Local add-on provides the desktop interface and coordinates transfers.

= Selective workflows =

* Compare allowlisted content using stable UUIDs and SHA-256 hashes.
* Push selected Pages, Posts, configured custom post types, metadata, taxonomies, and related media.
* Pull allowlisted content and related media from Live into Local.
* Detect destination edits made after comparison before overwriting content.
* Deploy an allowlisted theme ZIP with checksum validation, backup, health checks, and rollback.

= Full migration workflows =

* Clone a Live database, themes, optional plugins, and optional uploads into Local.
* Push a Local database and site files to Live after a mandatory Live database snapshot.
* Preserve destination users/usermeta by default.
* Rewrite source URLs, filesystem paths, table prefixes, and PHP-serialized data.
* Recreate database views after base tables and remove source-only database qualifiers/definers.

= Safety =

* Dedicated WordPress Application Password authentication; primary account passwords are not accepted.
* Administrator capability checks and a deployment enable/disable switch.
* Theme and content-type allowlists.
* Package size limits and ZIP path/scope/symlink validation.
* Automatic pre-import database snapshots and pre-replacement theme backups.
* Critical route health checks, automatic database/theme rollback where supported, and a bounded audit log.
* Recovery files stored in a randomly suffixed, guarded directory outside the media library.

Full file migration is an overlay, not an exact mirror: files are added/replaced but stale destination files are not deleted. A database rollback does not roll back files. Keep a host-level backup before a production push.

The plugin does not contact an external service and contains no telemetry. It communicates only with an authenticated Infinity Deploy client and WordPress loopback health-check URLs.

== Installation ==

1. Upload and activate the plugin on both Local and Live WordPress sites.
2. Open Tools > Infinity Deploy on each site.
3. Enable authenticated deployments and configure allowed themes, content types, package size, and retained backups.
4. Create a dedicated Application Password in the deployment user's WordPress profile on each site.
5. Install Infinity Deploy Local add-on 1.0.5 or later in Local.
6. Enter both URLs, usernames, Application Passwords, and the theme slug in Local's Infinity Deploy panel.
7. Test both connections before any deployment.

Requirements: WordPress 6.2+, PHP 7.4+, PHP ZipArchive, REST API access, writable database/wp-content, loopback HTTP access, sufficient disk space, and host upload/time limits. A non-local Live URL must use HTTPS.

== Frequently Asked Questions ==

= Does this accept my normal WordPress password? =

No. Version 1.0.3 and later accepts dedicated WordPress Application Passwords only. They can be individually revoked without changing the account password.

= What happens when I disable deployments? =

The status endpoint remains readable to an administrator for connection diagnostics. Content export and all mutation endpoints are blocked.

= What does Preserve Users/Customers do? =

It retains the destination WordPress users and usermeta tables. During Live-to-Local clone it preserves Local users; during Local-to-Live push it preserves Live users. Customer records stored in other plugin tables are not covered.

= Are full deployments atomic? =

No. Database imports have safety snapshots and automatic rollback on import/critical-health failure, but file overlays are separate and are not reverted by a database restore. Use a hosting-level backup for complete recovery.

= Where are backups stored? =

In a randomly suffixed dot-directory below wp-content. Apache/IIS guards are created. Nginx administrators should explicitly deny web access to dot-prefixed Infinity Deploy backup directories.

= Why does a large upload fail? =

The Bridge's package limit, PHP upload_max_filesize/post_max_size, execution limits, web-server/proxy body limits, timeouts, free disk space, and memory can all be limiting factors. The smallest limit wins.

= Does selective sync delete content? =

No. It adds or updates allowlisted content and media; it does not propagate deletions.

== Privacy ==

Depending on the chosen operation, this plugin processes content, media, source code, and complete WordPress databases. Databases can contain personal information. No data is sent to the plugin author or an external service. Operators are responsible for authorization, backups, retention, and applicable privacy obligations.

== Upgrade Notice ==

= 1.0.3 =
Security release: deployment-disable enforcement, Application-Password-only fallback authentication, package-scope/symlink validation, and size enforcement. Upgrade both endpoints before distribution.

== Changelog ==

= 1.0.3 =
* Enforces the deployment-enabled setting on export and mutation endpoints.
* Accepts Application Passwords only in the Bridge fallback authenticator.
* Restricts site packages to uploads, plugins, and themes.
* Rejects out-of-scope paths, symlinks, traversal, and Bridge replacement.
* Applies the configured size limit to database and site-package imports.
* Raises the configurable limit ceiling to 2048 MB; default remains 200 MB.

= 1.0.2 =
* Recreates database views after base tables and removes source-only database qualifiers and definers.

= 1.0.1 =
* Safely rewrites serialized settings containing plugin classes unavailable on the destination.

= 1.0.0 =
* Initial release with selective sync, theme backup/rollback, full migration, snapshots, health checks, and activity logs.
