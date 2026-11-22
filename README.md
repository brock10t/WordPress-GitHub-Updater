# 10T GitHub Updater

A standalone WordPress plugin for centralized management of plugins hosted in public GitHub repositories. Currently implements the foundation, registration data model, option storage, installed-plugin discovery, GitHub URL validation and Update URI diagnostics; internal registration operations with conflict controls and coordinated writes; administration screens, installation and updates will arrive in later tasks.

Requires **PHP 8.2+** and **WordPress 6.6+**. On Multisite, network activation is required. There are no mandatory third-party dependencies and production does not require Composer.

## Foundation

- `wordpress-github-updater.php` declares plugin metadata, registers the activation guard and checks requirements before loading the coordinator on `plugins_loaded`.
- `includes/class-environment.php` checks versions and network activation and provides escaped administrative diagnostics. This file and the bootstrap deliberately avoid modern PHP syntax that would prevent an older runtime from reaching the version check (syntax baseline PHP 5.6).
- `includes/class-plugin.php` coordinates component hooks. It has no operational hooks yet.

Classes use the `TenT\GitHubUpdater` namespace and WordPress-style `class-*.php` filenames. Components load through explicit requires. Future components will separate registry persistence, GitHub requests, native update integration, package validation, administration and installation. No repository requests or database writes occur during foundation initialization.

`Update URI: false` prevents accidental WordPress.org update matching while the real self-update repository is unconfigured. It will be replaced with the confirmed repository URL when self-update support is added. No repository URL is assumed.

## Registry data model

`includes/registry/class-registration.php` defines an immutable `TenT\GitHubUpdater\Registry\Registration`. The installed plugin basename is its identity; the GitHub repository name can differ from the plugin directory. Construct records from an already-canonical basename and repository owner/name. The model provides structural checks, array serialization and consistency checks when restoring arrays. It does not discover installed plugins, validate repository URLs, contact GitHub, or establish that a repository is public or trusted.

The option value uses a versioned envelope, with registrations keyed by their exact installed basenames:

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

V1 supports directory-based plugins only; standalone PHP plugins directly under `wp-content/plugins` are rejected without relocation. Records preserve literal installed basename case and reject nested main-file paths, traversal components, backslashes, drive separators, control characters and percent-encoded paths. Repository component checks are structural only; external repository URLs must pass through `GitHub\Repository::from_url()` before registration services construct a record.

`Registry\Option_Storage` implements this envelope under `wordpress_github_updater_registry`, using `get_option`/`update_option` on single-site and `get_site_option`/`update_site_option` on Multisite (one shared registry per current network). Single-site storage disables autoload. It checks each array key against its record's basename. The adapter is not yet wired into operational plugin hooks, so initialization creates no registry option. Store plain arrays, not serialized PHP objects. Derive the slug/main file from the basename, the ZIP name from the slug, and the repository URL from its owner/name. Restoring a record must reject inconsistent derived fields rather than silently repairing its identity.

`load()` returns basename-keyed `Registration` objects or a `WP_Error`; an absent option returns an empty array without creating storage. `save()` accepts a complete basename-keyed snapshot of `Registration` objects and returns `true` or a `WP_Error`. It refuses to overwrite a malformed registry or any unsupported schema version, including future versions. Invalid records fail the whole read rather than disappearing from the next save. A failed update is distinguished from an unchanged update by reading back the exact stored value. No automatic repair or migration occurs.

Raw storage does not enforce user capabilities, nonces, repository validity or conflicting registrations. Use the registry service for mutations: it performs validation and holds the write lock across the complete read/validate/save operation. Calling storage directly bypasses those safeguards; raw snapshot saves alone are not atomic. No per-request writes or activation-time storage initialization are introduced.

Installed versions, Update URI diagnostics, release metadata, activation state and errors are live or cached information; they are not duplicated in the registration. The built-in self-update registration will remain separate. Repository URL normalization and registration services must validate external input before constructing records and translate validation exceptions into actionable WordPress errors.

## Installed-plugin discovery

`Registry\Installed_Plugins::all()` reads WordPress's `get_plugins()` list and preserves its canonical basename keys and raw headers. It includes active, inactive and standalone ordinary plugins; must-use plugins and drop-ins are outside this list. The WordPress administration helper is loaded on demand when necessary. Discovery uses WordPress's own plugin cache and adds no independent cache.

`get($basename)` resolves only an exact installed key and applies `Registration::is_supported_basename()` before returning headers. Missing or altered keys return `github_updater_plugin_not_installed`; installed standalone files or unsupported paths return `github_updater_unsupported_plugin_basename`. Display names, directory names alone and repository names cannot substitute for basenames. Distinct main files in the same directory remain distinct entries; v1 registry conflict rules reject managing shared directories.

The directory-only format check is shared with the registration model; it does not prove installation by itself. Header values are untrusted text and must be escaped when displayed. Discovery does not validate Update URI, register plugins, read GitHub, inspect activation eligibility or write storage. Future administration actions must enforce capabilities and nonces and resolve the selected basename again before acting. Core's plugin cache must be invalidated appropriately after future install/update operations.

## Repository URL validation

`GitHub\Repository::from_url($url)` returns an immutable repository identity (`owner`, `name`, `url`) or `github_updater_invalid_repository_url` as a `WP_Error`. It performs no HTTP requests, redirects or persistence. A valid shape does not establish that the repository exists, is public, contains a WordPress plugin, or has an eligible release; the GitHub API client will check those separately.

Accepted input is an HTTPS repository root on exactly `github.com`, with two path components. Host and scheme are case-insensitive; owner/name normalize to lowercase for comparisons. Ordinary surrounding spaces, one trailing slash and one `.git` suffix are normalized. The parser rejects credentials, explicit ports (including 443), queries/fragments (including empty ones), extra or empty path segments, percent encoding, backslashes, control characters, Unicode lookalikes, SSH/HTTP forms, host aliases and arbitrary download/API URLs. Repeated `.git` suffixes are rejected to keep canonicalization idempotent.

V1 accepts ordinary public account/organization owner syntax: 1–39 ASCII alphanumeric characters or single internal hyphens. Repository names use 1–100 ASCII letters, digits, periods, underscores or hyphens; `.` and `..` are rejected. These are local syntax constraints, not a complete determination of names GitHub will accept or resolve. Managed-user/enterprise-specific owner forms are outside this parser's current grammar. Case-insensitive repository identity and repository-name constraints were checked against [GitHub repository API documentation](https://docs.github.com/en/rest/repos/repos#get-a-repository) and [repository creation documentation](https://docs.github.com/en/repositories/creating-and-managing-repositories/creating-a-new-repository).

Future registration and Update URI handling should reuse this parser, then construct `Registration` using its normalized owner/name and the separately discovered installed basename. Do not infer a plugin directory from the repository name. The parser is explicitly loaded by consumers and is not yet wired to administrative actions. It is not the allowlist or redirect validator for future asset downloads.

## Update URI compatibility

`Registry\Update_URI::check($plugin_data, $repository)` compares raw installed-plugin headers with a validated `GitHub\Repository`. It returns `true` for compatible headers or a `WP_Error` distinguishing a missing header, `Update URI: false`, an invalid/unsupported URL, another GitHub repository, or an incompatible hostname for native hook routing. Each error includes the canonical correction in `get_error_data()['expected_update_uri']`; escape this value when displaying it.

Repository identity comparisons reuse URL normalization, including owner/name case and optional `.git`/trailing slash. There is an additional routing requirement: the header hostname must be literal lowercase `github.com`. WordPress derives the dynamic hook directly from `wp_parse_url(sanitize_url($header), PHP_URL_HOST)` and preserves hostname case. Thus `https://GITHUB.COM/owner/repo` identifies the same repository but does not select the planned `update_plugins_github.com` hook. This behavior was checked against [WordPress 6.6 update source](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/update.php) and the native helpers on the local installation.

Diagnostics explain how to involve the plugin author and provide the expected repository-root header. They do not modify files, store a status, authorize a registration, confirm repository visibility or make an update installable. Future registration and update handlers must obtain headers for the exact selected basename through installed-plugin discovery, then check compatibility again as appropriate. The current provisional registration policy permits missing headers as pending and rejects all other incompatibilities; this policy is applied by the registry service, not by diagnostics. The updater's provisional `Update URI: false` stays unchanged until its actual self-update repository is confirmed.

## Registry operations

`Registry\Registry` coordinates option storage, installed-plugin discovery and URL/header checks. It is explicitly loaded by consumers and has no public HTTP action or operational hook yet.

- `all()` returns the persisted basename-keyed records or a storage error.
- `register($basename, $repository_url)` explicitly associates an installed directory-based plugin with normalized repository identity and returns a `Registration` or `WP_Error`. A missing Update URI permits a pending record; callers must display `check_update_uri()` guidance after success. Explicit `false`, malformed, conflicting and incorrectly routed headers reject registration. This is the recommended provisional policy pending the user's preference.
- `get($basename)` reads one exact registered identity. It can return a record for a plugin that has since been deleted, so administrators can inspect and remove stale records.
- `check_update_uri($basename)` resolves current installed headers and recomputes compatibility. A returned registration alone does not make a plugin eligible for updates.
- `remove($basename)` removes only that exact registry record. It never uninstalls, deactivates or edits plugin files; missing installed files do not prevent removal of a stale registration.

Registration never silently replaces an existing basename, and manual registration of the updater itself is reserved for built-in self-update support. V1 also rejects normalized repository reuse, case-only basename aliases, directories already assigned to another main file, and directories containing multiple installed plugin entry points (even if the other plugin is unregistered). Repository reuse across a monorepo is outside the v1 policy. Case-folded collision checks protect portable directory identity while stored basenames stay unchanged. Readiness checks apply these rules to already-stored records as well; conflicting records remain inspectable and removable. Missing-header readiness is computed from current installed headers rather than a persisted flag; a header correction needs no registry rewrite. Until native update integration exists, no records produce update offers. Future update handlers must always check current compatibility before providing metadata or packages.

Mutation methods require `update_plugins`, plus `manage_network_plugins` on Multisite. WordPress's capability mapping therefore also respects file-modification restrictions and the network administrator boundary. Future HTTP handlers must additionally restrict management to the correct admin context and verify nonces. Read operations remain available to internal update checks without a logged-in user.

Registry mutations use `Registry\Registry_Lock` to acquire a zero-wait MySQL/MariaDB advisory lock before reading current records. Its hashed name includes the database, option table, registry key and network ID; all sites in one network share a lock. Successful acquisition invalidates the relevant option and missing-option caches before validation/save. Nested mutations are rejected, and `finally` releases locks after successful returns, errors or exceptions. Busy/unavailable locks return errors without writing; release failure reports that the change may already have been saved and instructs a refresh.

This is cooperative locking for a stable connection to one primary database, using `$wpdb` only for prepared locking queries and native options APIs for persisted data. Raw storage writes or other code that ignores the lock are outside its protection. Database proxies/routers, reconnection/failover during a mutation, custom database backends and unusual persistent object caches require separate integration verification; the lock does not promise distributed or transactional recovery. Hosts disabling advisory locking cannot mutate the registry. Read-only diagnostics still work. The implementation follows [MySQL locking functions](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html) and [MariaDB GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock).

No administrative mutation endpoints exist yet. Their capability, nonce and Network Admin checks remain for the administration task.

## Development checks

Run from the plugin directory:

```sh
php -l wordpress-github-updater.php
php -l includes/class-environment.php
php -l includes/class-plugin.php
php tests/foundation.php
php tests/registration.php
php tests/option-storage.php
php tests/installed-plugins.php
php tests/repository.php
php tests/update-uri.php
php tests/registry.php
php tests/registry-lock.php
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
