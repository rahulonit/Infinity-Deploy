# Infinity Deploy

Infinity Deploy is a paired WordPress deployment system for [Local](https://localwp.com/) and a remote WordPress site. It combines a Local desktop add-on with an authenticated WordPress bridge so an administrator can compare and synchronize selected content, deploy a theme with rollback, clone a live site into Local, or push a complete Local site to production.

Current components:

| Component | Version | Source | Release archive |
|---|---:|---|---|
| Infinity Deploy Local add-on | 1.0.5 | `local-addon/` | `dist/infinity-deploy-local-addon.zip` |
| Infinity Deploy Bridge | 1.0.3 | `wordpress-plugin/infinity-deploy-bridge/` | `dist/infinity-deploy-bridge.zip` |

## Capabilities

- Tests authenticated Local and Live connections.
- Compares allowlisted posts by stable UUID and SHA-256 content hash.
- Pushes selected content and related media with conflict detection.
- Pulls allowlisted content and related media from Live to Local.
- Packages and deploys an allowlisted theme with checksum validation, backup, health checks, and rollback.
- Clones a database, uploads, plugins, and themes from Live to Local.
- Pushes a Local database and site files to Live after creating a mandatory Live database snapshot.
- Rewrites domains, filesystem paths, table prefixes, and PHP-serialized data during database migration.
- Keeps bounded deployment audit logs and retained theme/database backups.

## Requirements

- Local 9.0 or later.
- WordPress 6.2 or later on both endpoints.
- PHP 7.4 or later, PHP `ZipArchive`, and writable `wp-content` storage.
- WordPress permalinks and REST API access.
- A dedicated administrator account or administrator Application Password on both sites.
- HTTPS for a non-local Live URL.
- Hosting limits large enough for the selected package (`upload_max_filesize`, `post_max_size`, execution time, disk space, and any reverse-proxy body-size limit).

## Quick start

1. Build the archives with `./build.sh`, or obtain the two signed release ZIPs from your distributor.
2. Install and activate `infinity-deploy-bridge.zip` on both Local and Live WordPress.
3. In each dashboard, open **Tools → Infinity Deploy**, configure the allowlists, and enable authenticated deployments.
4. Create a dedicated WordPress Application Password on each site.
5. Install `infinity-deploy-local-addon.zip` in Local and restart Local.
6. Open the selected site’s **Tools/Utilities → Infinity Deploy**, enter both connections, and choose **Test both connections**.

Do not enter a primary WordPress account password. Bridge 1.0.3 and later accepts Application Passwords only.

## Documentation

- [Documentation index](docs/README.md)
- [Installation and configuration](docs/INSTALLATION.md)
- [User guide](docs/USER-GUIDE.md)
- [Architecture and data flows](docs/ARCHITECTURE.md)
- [REST API reference](docs/API-REFERENCE.md)
- [Security and privacy](docs/SECURITY.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Release and distribution guide](docs/RELEASE-GUIDE.md)
- [Changelog](CHANGELOG.md)
- [Security reporting policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

## Build

```bash
./build.sh
```

The build validates JavaScript, JSON, and PHP (when PHP CLI is available), stages clean component folders, includes the license, creates both ZIP files, synchronizes the expanded Local add-on under `dist/`, and verifies archive integrity.

## Important behavior

- Full file transfers are overlays: included files are added or replaced, but destination files missing from the source package are not deleted.
- Themes are always included in a full migration so the migrated database has matching theme code. The Plugins option controls plugins only.
- Selective sync adds or updates content; it does not propagate deletions.
- Preserve Users/Customers is enabled by default. It keeps the destination `users` and `usermeta` tables instead of replacing them with the source tables.
- Infinity Deploy snapshots are an additional recovery layer, not a replacement for a host-level backup.

## License

Infinity Deploy is licensed under GPL-2.0-or-later. See [LICENSE.md](LICENSE.md).
