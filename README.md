# 10T GitHub Updater

A standalone WordPress plugin for centralized management of plugins hosted in public GitHub repositories. Currently implements the foundation and registration data model; registry storage, administration screens, installation and updates will arrive in later tasks.

Requires **PHP 8.2+** and **WordPress 6.6+**. On Multisite, network activation is required. There are no mandatory third-party dependencies and production does not require Composer.

## Foundation

- `wordpress-github-updater.php` declares plugin metadata, registers the activation guard and checks requirements before loading the coordinator on `plugins_loaded`.
- `includes/class-environment.php` checks versions and network activation and provides escaped administrative diagnostics. This file and the bootstrap deliberately avoid modern PHP syntax that would prevent an older runtime from reaching the version check (syntax baseline PHP 5.6).
- `includes/class-plugin.php` coordinates component hooks. It has no operational hooks yet.

Classes use the `TenT\GitHubUpdater` namespace and WordPress-style `class-*.php` filenames. Components load through explicit requires. Future components will separate registry persistence, GitHub requests, native update integration, package validation, administration and installation. No repository requests or database writes occur during foundation initialization.

`Update URI: false` prevents accidental WordPress.org update matching while the real self-update repository is unconfigured. It will be replaced with the confirmed repository URL when self-update support is added. No repository URL is assumed.

## Registry data model

`includes/registry/class-registration.php` defines an immutable `TenT\GitHubUpdater\Registry\Registration`. The installed plugin basename is its identity; the GitHub repository name can differ from the plugin directory. Construct records from an already-canonical basename and repository owner/name. The model provides structural checks, array serialization and consistency checks when restoring arrays. It does not discover installed plugins, validate repository URLs, contact GitHub, or establish that a repository is public or trusted.

The planned option value uses a versioned envelope, with registrations keyed by their exact installed basenames:

```php
array(
    'schema_version' => 1,
    'registrations'  => array(
        'my-plugin/main.php' => array(
            'plugin_basename'  => 'my-plugin/main.php',
            'plugin_slug'      => 'my-plugin',
            'main_file'        => 'main.php',
            'repository_owner' => 'example-owner',
            'repository_name' => 'different-repository',
            'repository_url'  => 'https://github.com/example-owner/different-repository',
            'asset_name'      => 'my-plugin.zip',
        ),
    ),
);
```

V1 supports directory-based plugins only; standalone PHP plugins directly under `wp-content/plugins` are rejected without relocation. Records preserve literal installed basename case and reject nested main-file paths, traversal components, backslashes, drive separators, control characters and percent-encoded paths. Repository component checks are structural only; full GitHub URL and owner/name rules belong to the URL-validation task.

The option-storage task will implement this envelope in ordinary/network options and check that each array key matches its record's basename. No option is read or written yet. Store plain arrays, not serialized PHP objects. Derive the slug/main file from the basename, the ZIP name from the slug, and the repository URL from its owner/name. Restoring a record must reject inconsistent derived fields rather than silently repairing its identity.

Installed versions, Update URI diagnostics, release metadata, activation state and errors are live or cached information; they are not duplicated in the registration. The built-in self-update registration will remain separate. Repository URL normalization and registration services must validate external input before constructing records and translate validation exceptions into actionable WordPress errors.

## Development checks

Run from the plugin directory:

```sh
php -l wordpress-github-updater.php
php -l includes/class-environment.php
php -l includes/class-plugin.php
php tests/foundation.php
php tests/registration.php
php tests/unsupported-environment.php wordpress
git diff --check
```

The foundation checks use WordPress function doubles and separate PHP processes to exercise loading, activation guards and notices. They do not establish real WordPress activation or old-PHP runtime compatibility. Use WordPress Coding Standards when development tooling is available; no standards runner is bundled yet.

The unsupported-environment checks fail on PHP warnings and verify early return, activation rejection, escaped diagnostics and permission checks in both admin contexts. The `wordpress` scenario injects WordPress 6.5.9. Run `tests/unsupported-environment.php php` and `tests/unsupported-environment.php both` with a real PHP interpreter below 8.2 to exercise the PHP gate; these scenarios refuse to run on supported PHP. For example:

```sh
php8.1 tests/unsupported-environment.php php
php8.1 tests/unsupported-environment.php both
```

These isolated checks do not boot an older WordPress installation. Never downgrade the active site's PHP or WordPress files to perform compatibility checks.

In disposable WordPress environments, verify:

1. On WordPress 6.6 with PHP 8.2 and on current supported versions, confirm the Plugins screen recognizes the name, version and requirements; activate and reload the administration and front end without errors.
2. On an unsupported WordPress/PHP version, confirm native activation rejection. For an already-active plugin after an environment downgrade, confirm a useful diagnostic for authorized administrators and no coordinator loading or fatal error.
3. On Multisite, confirm network-only activation, successful Network Admin activation, and rejection of programmatic site-only activation. An incorrectly site-active copy must not initialize the coordinator.
4. Confirm no GitHub requests, registry data, update offers or management screens are introduced by this foundation.

Later logic tests will cover repository normalization, versions, releases, registry validation and package identity with mocked HTTP. Installation, replacement, rollback and self-updates require disposable single-site and Multisite integration environments. Never test destructive update behavior on production.

## Release and trust boundaries

Future releases accept explicitly registered public repositories and uploaded WordPress-ready `plugin-slug.zip` assets. GitHub repository ownership is a trust decision: identity checks and HTTPS cannot establish that plugin code is safe. Package validation must precede any installable update integration. Private repositories and themes are deferred.

Release ZIPs must contain the `wordpress-github-updater/` root directory and runtime files. Omit Git metadata, development tests and local planning documents (`ROADMAP.md` and `AGENTS.md`). Those planning documents are locally excluded from Git and must never be committed. No release packaging automation exists yet.
