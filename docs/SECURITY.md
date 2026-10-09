# Security and privacy

## Security model

- Dedicated, revocable WordPress Application Passwords.
- WordPress capability checks on every route.
- A Bridge enable/disable switch gating exports and mutations.
- Required HTTPS for non-local Live URLs.
- Same-host redirects only; no HTTPS downgrade.
- Theme allowlist and content-type/metadata allowlists.
- SHA-256 validation for theme packages and selective media.
- ZIP path, scope, top-level folder, null-byte, traversal, and symlink validation.
- Site packages restricted to uploads, plugins, and themes; they cannot replace the Bridge.
- Pre-import database snapshots and theme pre-replacement backups.
- Critical post-operation health checks and bounded audit records.

Infinity Deploy is still a privileged deployment tool. A compromised administrator Application Password can read/export content and change the site while the Bridge is enabled. Revoke credentials immediately when a workstation or account is suspected compromised.

## Credential storage

Saved connections are stored in Local’s Electron user-data directory at `infinity-deploy/connections.json`. The file is written with owner-only mode `0600` where the OS supports POSIX permissions.

When Electron `safeStorage` encryption is available, Application Passwords are encrypted with the operating system’s credential protection. If it is unavailable, the add-on falls back to base64 encoding. Base64 is not encryption. On such a system, avoid saving long-lived credentials, secure the OS account/disk, and revoke Application Passwords after use.

Credentials are sent only to the configured Local/Live hosts. They are not included in Bridge audit logs.

## Data processed

Depending on the selected operation, Infinity Deploy may process WordPress content, metadata, taxonomies, media, themes, plugins, uploads, and the full database. A database can include personal or regulated information, paths, URLs, user/customer records, and application data in custom tables.

Infinity Deploy has no telemetry, analytics, licensing server, or external SaaS dependency in the current implementation. Server, CDN, WAF, and hosting-provider logs may record requests according to their own policies.

## Recovery storage

Recovery files live in a randomly suffixed dot-directory under `wp-content`. Apache and IIS denial files are created. For Nginx or other servers, explicitly deny web access to dot-prefixed directories below `wp-content`, for example:

```nginx
location ~* /wp-content/\.infinity-deploy-backups- { deny all; }
```

Backups can contain source code and complete databases. Protect filesystem backups, exclude them from public artifact collection, define a retention policy, and securely remove them when no longer needed.

## Recommended operating procedure

1. Use dedicated deployment accounts and separate Local/Live Application Passwords.
2. Keep the Bridge disabled outside deployment windows.
3. Restrict WordPress administrator access with MFA at the account/SSO layer.
4. Back up at the hosting layer before full production pushes.
5. Verify release ZIP checksums from a trusted channel.
6. Review Bridge logs after each production operation.
7. Revoke and rotate Application Passwords periodically.
8. Never send credentials, database dumps, or package files in a public issue.

## Known boundaries

- Full file transfers are not atomic and are not rolled back by database restoration.
- A safety snapshot depends on the same WordPress/database/filesystem environment; host-level backups provide stronger disaster recovery.
- Local HTTP traffic is unencrypted. Do not expose a Local site to an untrusted network.
- Only `/` is critical in the default health policy. Customize critical paths for checkout, authentication, APIs, or other business-critical routes.
- Preserving `users`/`usermeta` does not preserve customer data stored in other plugin tables.

See the root [security reporting policy](../SECURITY.md) for vulnerability reports.
