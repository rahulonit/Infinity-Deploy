# User guide

## Before every deployment

1. Confirm the direction: **Local → Live** changes production; **Live → Local** changes only the selected Local site.
2. Take a hosting-level backup before a full production push.
3. Confirm adequate disk space and upload limits.
4. Test both connections.
5. Avoid editing the same content on both endpoints during the operation.
6. Keep Local and the selected site running until completion.

## Test both connections

This calls `/status` on Local and Live in parallel. It validates the URL, TLS policy, Application Password, administrator access, Bridge availability, and basic environment metadata. It does not prove that the host accepts a large upload or can build a ZIP.

## Compare portfolio content

The add-on exports allowlisted Local content and reads the Live manifest. Items are matched by `_infinity_deploy_uuid`; hashes determine whether each item is new, changed, or unchanged.

Changed/new items are selected by default. Review the list before pushing. Run Compare again if someone edits Live after the comparison; the expected Live hash prevents overwriting a changed item.

Selective synchronization:

- Handles published, draft, and private items.
- Transfers allowlisted post fields, taxonomies, metadata, and referenced media.
- Rewrites the source origin to the destination origin in content, excerpts, and supported metadata.
- Adds or updates; it does not delete destination items missing from the source.
- Does not synchronize arbitrary options, users, plugin tables, comments, orders, or form submissions.

## Push changed content and media

1. Run Compare.
2. Select the intended changed/new items.
3. Choose **Push changed content & media** and confirm.

Media uploads run with three concurrent workers. Content requests are sent in batches of 25. Each content item has an independent result; inspect errors rather than assuming all items succeeded because the request completed.

## Pull portfolio data from Live

This compares the complete allowlisted Live export to the Local manifest, downloads changed media, then adds/updates changed content in Local. It does not delete local-only items and does not use the selection list from a prior Local-to-Live comparison.

Use this before beginning local work when Live may contain newer editorial changes.

## Push theme with backup

The add-on packages the configured Local theme directory and computes a SHA-256 checksum. Live then:

1. Verifies the theme slug is allowlisted.
2. Verifies the ZIP extension, size, checksum, safe paths, top-level folder, and `style.css`.
3. Creates a retained ZIP backup of the current destination theme.
4. Installs/replaces the theme.
5. Flushes rewrites/caches and runs health checks.
6. Restores the prior theme automatically if installation or a critical health check fails.

Successful backups appear under **Theme rollbacks**. **Restore** replaces the current theme files with that backup and runs health checks.

Theme-only rollback does not revert database changes or uploads.

## Clone complete site: Live → Local

This operation can replace the Local database and overlay Local files. The default options are Database on, Uploads on, Plugins off, and Preserve Users/Customers on. Themes are always transferred.

Sequence:

1. Validate the Live connection.
2. Export the Live database, optionally excluding Live user tables.
3. Download it to a temporary desktop file and remove the server-side transfer package.
4. Create a pre-import Local database snapshot.
5. Import into Local with domain, path, and table-prefix remapping.
6. Transfer themes, optionally plugins, and optionally uploads as separate ZIP packages.
7. Flush permalinks/caches and run Local health checks.

If **Preserve Users/Customers** is checked, Local `users` and `usermeta` stay intact. This helps preserve the Local login. Uncheck only when you explicitly want source users and can recover access.

Themes are always included. File transfers overlay the destination: old Local files absent from Live are not deleted.

If the database completes but a file component fails, the add-on reports a partial failure and names the component. Do not treat that as a clean clone.

## Push complete site: Local → Live

This is the highest-risk operation. The default options preserve Live users/customers, exclude plugins, and include database/uploads; themes are always included.

Sequence:

1. Validate Live.
2. Create a mandatory `pre-push-full` Live database snapshot before any file transfer, even if Database is unchecked.
3. Transfer selected uploads/plugins and themes to Live.
4. Export and transfer the Local database if selected.
5. Create another automatic pre-import snapshot before database replacement.
6. Import with URL/path/prefix remapping, preserving destination users when selected.
7. Run Live maintenance and health checks.

When **Preserve Users/Customers** is checked, Live `users` and `usermeta` are retained. This does not preserve customers stored by a plugin in different custom tables; assess the plugin schema before a production push.

The file phase occurs before the database phase and is an overlay, not an atomic release. A database rollback does not roll back files. Always retain a host backup for complete recovery.

## Restore a Live database snapshot

Use **Revert DB** beside a listed snapshot. Restoration imports the selected SQL snapshot and runs health checks. It changes only the database; it does not revert themes, plugins, or uploads.

Snapshot retention shares the Bridge’s configured retained-backups count. Old database snapshots are pruned independently from theme backups.

## Reading results

- Green success means the operation and final critical health check completed.
- A WordPress error code in parentheses comes from the Bridge and can be searched in [Troubleshooting](TROUBLESHOOTING.md).
- A partial clone error means the database may already have changed even though one or more file packages failed.
- Non-critical route failures appear in health-check details/logs but do not automatically roll back; `/` is the only critical route by default.
