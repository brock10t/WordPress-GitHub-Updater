# 10T GitHub Updater

A standalone WordPress plugin for centralized management of plugins hosted in public GitHub repositories. Currently implements the foundation only; registration, administration screens, installation and updates will arrive in later milestones.

Requires **PHP 8.2+** and **WordPress 6.6+**. On Multisite, network activation is required. There are no mandatory third-party dependencies and production does not require Composer.

## Foundation

- `wordpress-github-updater.php` declares plugin metadata, registers the activation guard and checks requirements before loading the coordinator on `plugins_loaded`.
- `includes/class-environment.php` checks versions and network activation and provides escaped administrative diagnostics. This file and the bootstrap deliberately avoid modern PHP syntax that would prevent an older runtime from reaching the version check (syntax baseline PHP 5.6).
- `includes/class-plugin.php` coordinates component hooks. It has no operational hooks yet.

Classes use the `TenT\GitHubUpdater` namespace and WordPress-style `class-*.php` filenames. Components load through explicit requires. Future components will separate registry persistence, GitHub requests, native update integration, package validation, administration and installation. No repository requests or database writes occur during foundation initialization.

`Update URI: false` prevents accidental WordPress.org update matching while the real self-update repository is unconfigured. It will be replaced with the confirmed repository URL when self-update support is added. No repository URL is assumed.

## Development checks

Run from the plugin directory:

```sh
php -l wordpress-github-updater.php
php -l includes/class-environment.php
php -l includes/class-plugin.php
php tests/foundation.php
git diff --check
```

The foundation checks use WordPress function doubles and separate PHP processes to exercise loading, activation guards and notices. They do not establish real WordPress activation or old-PHP runtime compatibility. Use WordPress Coding Standards when development tooling is available; no standards runner is bundled yet.

In disposable WordPress environments, verify:

1. On WordPress 6.6 with PHP 8.2 and on current supported versions, confirm the Plugins screen recognizes the name, version and requirements; activate and reload the administration and front end without errors.
2. On an unsupported WordPress/PHP version, confirm native activation rejection. For an already-active plugin after an environment downgrade, confirm a useful diagnostic for authorized administrators and no coordinator loading or fatal error.
3. On Multisite, confirm network-only activation, successful Network Admin activation, and rejection of programmatic site-only activation. An incorrectly site-active copy must not initialize the coordinator.
4. Confirm no GitHub requests, registry data, update offers or management screens are introduced by this foundation.

Later logic tests will cover repository normalization, versions, releases, registry validation and package identity with mocked HTTP. Installation, replacement, rollback and self-updates require disposable single-site and Multisite integration environments. Never test destructive update behavior on production.

## Release and trust boundaries

Future releases accept explicitly registered public repositories and uploaded WordPress-ready `plugin-slug.zip` assets. GitHub repository ownership is a trust decision: identity checks and HTTPS cannot establish that plugin code is safe. Package validation must precede any installable update integration. Private repositories and themes are deferred.

Release ZIPs must contain the `wordpress-github-updater/` root directory and runtime files. Omit Git metadata, development tests and local planning documents (`ROADMAP.md` and `AGENTS.md`). Those planning documents are locally excluded from Git and must never be committed. No release packaging automation exists yet.
