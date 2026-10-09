# Infinity Deploy documentation

This documentation covers Infinity Deploy Local add-on 1.0.5 and Infinity Deploy Bridge 1.0.3.

## Audience map

| If you are… | Start here |
|---|---|
| A site administrator installing Infinity Deploy | [Installation and configuration](INSTALLATION.md) |
| A content editor or deployer | [User guide](USER-GUIDE.md) |
| Diagnosing a failed transfer | [Troubleshooting](TROUBLESHOOTING.md) |
| Reviewing implementation or integrating with the Bridge | [Architecture](ARCHITECTURE.md) and [API reference](API-REFERENCE.md) |
| Performing a security review | [Security and privacy](SECURITY.md) |
| Packaging a public or private release | [Release and distribution guide](RELEASE-GUIDE.md) |

## Product terminology

- **Local**: the selected WordPress site running in the Local desktop application.
- **Live**: the remote WordPress site configured in the add-on. The same workflow can target a staging site.
- **Bridge**: the WordPress plugin installed on both endpoints.
- **Add-on**: the Local desktop integration that coordinates transfers.
- **Selective sync**: item-level content/media synchronization or theme-only deployment.
- **Full migration**: database and selected `wp-content` components transferred between endpoints.
- **Safety snapshot**: a retained SQL export made before a potentially destructive database import.
- **Theme backup**: a ZIP of the destination theme made before theme-only replacement.

## Support information to collect

When reporting an issue, include component versions, Local version, WordPress/PHP versions, source and destination environment types, the exact visible error, the failed operation, approximate package size, and relevant Bridge activity-log entries. Redact URLs if necessary and never include Application Passwords, database dumps, or backup-directory secrets.
