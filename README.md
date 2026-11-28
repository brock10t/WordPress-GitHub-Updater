# 10T GitHub Updater

A standalone WordPress plugin for centralized management of plugins hosted in public GitHub repositories. The current administration screen supports explicit registration and removal. Internal components provide bounded REST retrieval, stable release candidates, version comparison, asset identification and network-aware metadata caching. Metadata-only newer-release selection is available internally. Native update offers, package validation and installation remain for later tasks.

Requires **PHP 8.2+** and **WordPress 6.6+**. On Multisite, network activation is required. There are no mandatory third-party dependencies and production does not require Composer.

## Foundation

- `wordpress-github-updater.php` declares plugin metadata, registers the activation guard and checks requirements before loading the coordinator on `plugins_loaded`.
- `includes/class-environment.php` checks versions and network activation and provides escaped administrative diagnostics. This file and the bootstrap deliberately avoid modern PHP syntax that would prevent an older runtime from reaching the version check (syntax baseline PHP 5.6).
- `includes/class-plugin.php` loads the registry/admin components only in administration requests and registers the screen and authenticated action hooks. Front-end requests do not load those components.

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

`Registry\Option_Storage` implements this envelope under `wordpress_github_updater_registry`, using `get_option`/`update_option` on single-site and `get_site_option`/`update_site_option` on Multisite (one shared registry per current network). Single-site storage disables autoload. It checks each array key against its record's basename. The adapter is used by the administration registry workflow. Simply loading the plugin or viewing the screen creates no registry option. Store plain arrays, not serialized PHP objects. Derive the slug/main file from the basename, the ZIP name from the slug, and the repository URL from its owner/name. Restoring a record must reject inconsistent derived fields rather than silently repairing its identity.

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

Registration and Update URI handling reuse this parser, then construct `Registration` using its normalized owner/name and the separately discovered installed basename. Do not infer a plugin directory from the repository name. The admin coordinator explicitly loads the parser with its registry consumers. It is not the allowlist or redirect validator for future asset downloads.

## Update URI compatibility

`Registry\Update_URI::check($plugin_data, $repository)` compares raw installed-plugin headers with a validated `GitHub\Repository`. It returns `true` for compatible headers or a `WP_Error` distinguishing a missing header, `Update URI: false`, an invalid/unsupported URL, another GitHub repository, or an incompatible hostname for native hook routing. Each error includes the canonical correction in `get_error_data()['expected_update_uri']`; escape this value when displaying it.

Repository identity comparisons reuse URL normalization, including owner/name case and optional `.git`/trailing slash. There is an additional routing requirement: the header hostname must be literal lowercase `github.com`. WordPress derives the dynamic hook directly from `wp_parse_url(sanitize_url($header), PHP_URL_HOST)` and preserves hostname case. Thus `https://GITHUB.COM/owner/repo` identifies the same repository but does not select the planned `update_plugins_github.com` hook. This behavior was checked against [WordPress 6.6 update source](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/update.php) and the native helpers on the local installation.

Diagnostics explain how to involve the plugin author and provide the expected repository-root header. They do not modify files, store a status, authorize a registration, confirm repository visibility or make an update installable. Future registration and update handlers must obtain headers for the exact selected basename through installed-plugin discovery, then check compatibility again as appropriate. The admin form permits missing-header registration only when the administrator explicitly checks the pending-registration option. Other incompatible headers reject registration. The internal registry API retains its earlier pending-enabled default for compatibility and accepts an explicit allow_pending argument; all HTTP submissions pass the checkbox choice. No global policy approval is inferred from the earlier unanswered preference question. The updater's provisional `Update URI: false` stays unchanged until its actual self-update repository is confirmed.

## Registry operations

`Registry\Registry` coordinates option storage, installed-plugin discovery and URL/header checks. It is explicitly loaded by the admin coordinator and invoked through authenticated, POST-only handlers.

- `all()` returns the persisted basename-keyed records or a storage error.
- `register($basename, $repository_url, $allow_pending = true)` explicitly associates an installed directory-based plugin with normalized repository identity and returns a `Registration` or `WP_Error`. A missing Update URI permits a pending record; callers must display `check_update_uri()` guidance after success. Explicit `false`, malformed, conflicting and incorrectly routed headers reject registration. The admin form always passes its explicit checkbox choice, including false when unchecked.
- `get($basename)` reads one exact registered identity. It can return a record for a plugin that has since been deleted, so administrators can inspect and remove stale records.
- `check_update_uri($basename)` resolves current installed headers and recomputes compatibility. A returned registration alone does not make a plugin eligible for updates.
- `remove($basename)` removes only that exact registry record. It never uninstalls, deactivates or edits plugin files; missing installed files do not prevent removal of a stale registration.

Registration never silently replaces an existing basename, and manual registration of the updater itself is reserved for built-in self-update support. V1 also rejects normalized repository reuse, case-only basename aliases, directories already assigned to another main file, and directories containing multiple installed plugin entry points (even if the other plugin is unregistered). Repository reuse across a monorepo is outside the v1 policy. Case-folded collision checks protect portable directory identity while stored basenames stay unchanged. Readiness checks apply these rules to already-stored records as well; conflicting records remain inspectable and removable. Missing-header readiness is computed from current installed headers rather than a persisted flag; a header correction needs no registry rewrite. Until native update integration exists, no records produce update offers. Future update handlers must always check current compatibility before providing metadata or packages.

Mutation methods require `update_plugins`, plus `manage_network_plugins` on Multisite. WordPress's capability mapping therefore also respects file-modification restrictions and the network administrator boundary. Future HTTP handlers must additionally restrict management to the correct admin context and verify nonces. Read operations remain available to internal update checks without a logged-in user.

Registry mutations use `Registry\Registry_Lock` to acquire a zero-wait MySQL/MariaDB advisory lock before reading current records. Its hashed name includes the database, option table, registry key and network ID; all sites in one network share a lock. Successful acquisition invalidates the relevant option and missing-option caches before validation/save. Nested mutations are rejected, and `finally` releases locks after successful returns, errors or exceptions. Busy/unavailable locks return errors without writing; release failure reports that the change may already have been saved and instructs a refresh.

This is cooperative locking for a stable connection to one primary database, using `$wpdb` only for prepared locking queries and native options APIs for persisted data. Raw storage writes or other code that ignores the lock are outside its protection. Database proxies/routers, reconnection/failover during a mutation, custom database backends and unusual persistent object caches require separate integration verification; the lock does not promise distributed or transactional recovery. Hosts disabling advisory locking cannot mutate the registry. Read-only diagnostics still work. The implementation follows [MySQL locking functions](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html) and [MariaDB GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock).

## Using the registration screen

Open **Plugins → GitHub Updater**. On Multisite, use **Network Admin → Plugins → GitHub Updater**; ordinary site administrators cannot manage the network registry.

Select an installed plugin by its displayed name and canonical basename, enter its public GitHub repository root URL, and submit **Register plugin**. Already-registered plugins and the updater itself are omitted from the selector. Standalone files remain visible but disabled. Shared directories and other conflicts are rejected by the registry after submission.

The pending-registration checkbox is unchecked by default. Select it only if you want to save an association whose Update URI header is missing. The list then shows corrective guidance; no GitHub updates are offered until compatibility is resolved and native update integration exists. Explicitly disabled, malformed or conflicting headers must be corrected before registration. Files are never edited for you. Repository existence, public visibility and release availability are not checked yet.

The table shows the installed version, canonical basename, repository link and current header/conflict diagnostics. **Remove registration** only removes the association; orphaned records remain removable. It does not uninstall or deactivate a plugin. No install, activation or update-check controls are part of this milestone.

`Admin\Registry_Page` registers standard `admin_post_10t_gu_*` handlers on single-site and `network_admin_edit_10t_gu_*` handlers on Multisite. Network forms use `network/edit.php?action=...` rather than a nonexistent network admin-post endpoint. Rendering, menu inclusion and mutations check capabilities and admin context. POST values are type-checked, unslashed and validated without rewriting canonical identities. Nonces protect registration and are additionally bound to the basename on removal. Responses use a fixed local 303 redirect; per-user network-aware notices expire after one minute and are consumed once. Header, URL and notice output is escaped. [WordPress 6.6 network action source](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-admin/network/edit.php) defines the network handler route.

Manual browser checks: register a test directory-based plugin using its actual public repository; try unchecked/checked pending registration, an invalid URL and conflicting header; refresh after submission; remove only its registration and verify the plugin remains installed/active as before. On a disposable Multisite installation, repeat from Network Admin and confirm site-admin/unauthorized attempts fail and a second site sees the same network registry. Real browser and Multisite checks remain pending; the native single-site integration verified menus, handlers, nonce checks, redirects, rendering, cross-request persistence and cleanup programmatically.

## GitHub REST transport

`GitHub\Api_Client` uses WordPress's `wp_safe_remote_get()` for two internally constructed endpoints on `https://api.github.com`: repository metadata and one paginated releases list. Call `get_repository($repository)` to confirm a matching public repository, or `get_releases_page($repository, $page = 1, $per_page = 100)` for raw release entries. Methods return decoded arrays or `WP_Error`; no arbitrary URL or endpoint can be supplied. Raw release entries are not eligible releases or trusted download instructions yet.

Requests use TLS verification, a 10-second timeout, a 1 MiB response-body limit (with a one-byte overflow check), an explicit JSON media type and API version `2022-11-28`. The static user agent does not disclose the site's URL. Responses request identity encoding, require JSON content type and the expected object/list root, and limit JSON nesting. Repository metadata must identify the selected repository and explicitly describe it as public. The API version is [currently supported by GitHub](https://docs.github.com/en/rest/about-the-rest-api/api-versions).

Metadata redirects are refused rather than followed, including same-host redirects. Repository moves therefore require review of the registered URL. HTTP/transport errors, malformed metadata, oversized bodies and private/mismatched repository identity return safe errors without reflecting remote body text. Timeouts, access denials, retired resources, temporary service failures and rate limits have distinct diagnostics. Retry guidance is described below; the client does not sleep or retry. Missing and private resources cannot reliably be distinguished from a 404 alone.

The transport is not wired into page views, registration or native update checks. It does not cache, scan pages, select a stable release, compare versions, download ZIPs or install anything. Release eligibility, asset validation and caching/backoff are separate components; this transport does not implement their policies. Future consumers must add caching before routine automatic checks. GitHub HTTP arguments were checked against [WordPress 6.6 HTTP source](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/class-wp-http.php).

The user supplied [wordpress-schedule-post-updates](https://github.com/brock10t/wordpress-schedule-post-updates) as a public test plugin. A read-only native WordPress HTTP check observed `v1.0.0` and an uploaded `wordpress-schedule-post-updates.zip` on 2026-11-22. Later package-stage checks downloaded this baseline temporarily and verified ZIP structure, expanded sizes and CRCs without extraction or installation; a later packaged-header check also passed identity, release-version, repository and declared PHP/WordPress requirement gates on the current native runtime. No extraction or installability is asserted. This repository is unrelated to the updater's still-unconfigured built-in self-update source. Automated transport tests use mocked responses and do not depend on this live release remaining unchanged.

## Stable published-release retrieval

`GitHub\Stable_Releases::get($repository)` first verifies matching public repository metadata, then scans release-list pages through `Api_Client`. It returns an array of stable published candidates or a `WP_Error`. Empty completed results mean no candidates. It is explicitly loaded by consumers and is not wired into the administration screen or native update hooks yet.

The scan requests 100 entries per page, at most five pages plus the repository check. It continues until an empty page, so a short page does not hide later entries. An empty page must occur within that bound: exhausting all five nonempty pages returns `github_updater_release_scan_incomplete`, even if some candidates were found. Requests that fail on later pages also return an error, never a partial result. No Link URL, `/latest` endpoint, branch, commit or source-archive endpoint is followed. Pagination is not a transactional GitHub snapshot; repeated candidate IDs are detected and return `github_updater_release_scan_changed` rather than being silently deduplicated.

Both `draft` and `prerelease` must be explicit booleans. Either true excludes the entry; explicit null `published_at` also excludes it. Candidate IDs must be positive integers, tags nonempty strings without controls, and publication dates valid UTC GitHub timestamps. Missing or malformed required metadata fails the scan rather than hiding a potentially newer candidate. Results sort by publication time descending, then release ID descending for deterministic ties; neither API order nor tag text determines that ordering.

This step establishes publication/flag eligibility only. Raw tag text, asset metadata and other fields remain untrusted. A release incorrectly marked stable with a prerelease-shaped tag will still need the upcoming tag validator; these candidate arrays are not installable update metadata. Numeric version selection, package assets, caching and backoff remain later tasks. The live test repository's existing `v1.0.0` is sufficient for this step; keep it as the baseline until native update integration is ready for an upgrade test.

## Release-tag normalization

`GitHub\Release_Tag::normalize($tag)` returns a numeric version string or `github_updater_unsupported_release_tag` as a `WP_Error`. Accepted tags contain at least two dot-separated numeric components, optionally preceded by one lowercase `v`. Examples: `v1.0.0` becomes `1.0.0`; `1.2`, `1.2.3.4` and numeric calendar versions retain their components.

Each component is `0` or a nonzero-leading integer of up to nine digits, which fits signed 32-bit PHP integers. The normalized version is limited to 128 characters. Leading zeros, whitespace/controls, uppercase/repeated prefixes, labels, branch names, signs and prerelease/build suffixes are rejected. For v1, use numeric stable tags without build metadata. A prerelease-shaped tag is rejected even if a release author incorrectly marks it `prerelease: false`.

Normalization does not trim, pad components, remove trailing zeros or strip qualifiers to manufacture a stable version. Thus `1.2` remains `1.2` and `1.2.0` remains `1.2.0`; the comparison helper pads only its private inputs so these versions compare as equivalent. Consumers must retain the original GitHub `tag_name` for asset paths and diagnostics; the normalized version is only for version semantics.

The helper is explicitly loaded by consumers and is not yet combined with retrieval into an update selector. Stable_Releases still returns raw candidates. Future selection must apply this validator before comparing versions, then require a validated uploaded asset. No update offers, downloads or installed-header version changes are introduced by this step.

## Version comparison

`GitHub\Version_Comparison::compare($release_tag, $installed_version)` returns `-1` for an older release, `0` for an equivalent version, `1` for a newer release, or a `WP_Error`. Check for an error before using the result. Both the original release tag and installed `Version` header must satisfy the supported stable numeric format, including its optional lowercase `v`, length and digit bounds.

The helper normalizes both strings, pads the shorter numeric component list with zeros, then uses PHP's `version_compare()`. Thus `v1.2`, `1.2.0` and `1.2.0.0` compare equal; `1.10` is newer than `1.9`; additional nonzero components remain significant. Padding is internal and does not rewrite the original tag, displayed installed version or plugin source.

Unsupported release tags retain `github_updater_unsupported_release_tag`; unsupported installed versions return `github_updater_unsupported_installed_version`. Empty, unrecognized, qualified or otherwise unsupported installed values never default to zero and cannot manufacture a newer-release result. Version headers containing prerelease qualifiers are outside the current supported comparison format.

This is a pure, explicitly loaded helper. It does not pick a release from a list, prove publication, validate package assets, contact GitHub or write any state. Future update selection must require stable publication/flags, valid versions and a positive comparison result together with the correct validated uploaded ZIP; equal/older releases must not be offered as upgrades. No managed-plugin version bump is needed until native update integration can test an actual upgrade.

## Required ZIP asset identification

`GitHub\Release_Asset::select($release, $registration)` returns an immutable metadata pointer (`id`, `name`, declared `size`, `api_url`, `download_url`) or a `WP_Error`. It examines only uploaded `assets`; automatically generated `zipball_url`/`tarball_url` archives are never fallback packages. The required name is the registration's exact `plugin_slug.zip`, including case, regardless of repository or main-file name.

Exactly one asset must match. Missing or duplicate filenames, malformed asset lists, missing names, unfinished upload state, nonpositive/noninteger ID or size, and invalid URL fields fail closed. Other well-named assets such as checksums do not need ZIP metadata because they are not selected. Only validated pointer fields are returned; unvalidated extra metadata is not promoted into a trusted result.

Both URLs are pinned to literal HTTPS GitHub hosts and the registered repository identity. The metadata endpoint must be `https://api.github.com/repos/owner/repository/releases/assets/ID`; the browser URL must be `https://github.com/owner/repository/releases/download/ORIGINAL_TAG/EXPECTED_FILENAME`. Owner/repository letter-case differences are permitted, but the tag, filename and encoded suffix must match exactly. No credentials, ports, queries, fragments, arbitrary endpoints or redirect destinations are accepted. A raw `v1.0.0` tag stays `v1.0.0` in the download path even though its comparison version is `1.0.0`. Endpoint shapes were checked against [GitHub release-asset documentation](https://docs.github.com/en/rest/releases/assets#get-a-release-asset).

This selects metadata, not an installable archive. Publication eligibility, installed-version comparison and actual package validation remain separate gates. Declared size and MIME type do not establish ZIP integrity; MIME is not used to select a package. A future downloader must impose actual byte/resource limits, revalidate metadata as appropriate and validate every download redirect before archive inspection. This helper performs no requests or writes and does not expose package metadata to WordPress's updater yet.

A native metadata-only check identified the existing test release's `wordpress-schedule-post-updates.zip` (declared 10,987 bytes) using an explicit in-memory test registration. It did not infer or register an installed main-file basename, download the archive, or validate its contents. Keep the test plugin at `v1.0.0` until an actual update test is ready.

## Release caching and invalidation

`GitHub\Cached_Releases` wraps the uncached `Stable_Releases` reader. `get($repository)` returns a revalidated completed candidate list or `WP_Error`. `get($repository, true)` performs an explicit manual refresh, bypassing successful entries and ordinary failure backoff while honoring recorded server cooldowns. `invalidate($repository)` removes that repository's current-scope metadata/error entry and returns `true` (including when absent) or `WP_Error`. It preserves an active server-directed cooldown in a separate transient.

Successful results, including an empty candidate list, have a **12-hour** lifetime. Failures have a **five-minute** minimum backoff; an existing numeric Retry-After hint can extend it up to one day. No sleeping or automatic retry loop is added. Forced refresh is for authorized user actions, never routine update checks, and cannot bypass a known active rate-limit or service Retry-After deadline. A forced refresh removes the previous entry before requesting metadata; failure returns its diagnostic and replaces success with a short error record, without stale release fallback. A cache write failure does not discard a successfully retrieved result; a failed invalidation reports an error instead of claiming fresh data.

Values are versioned plain arrays, with repository identity and absolute creation/expiry times. Cached successes reuse the reader's candidate validation (flags, dates, IDs, duplicates, bounded list and deterministic order); malformed or expired entries are misses. Tag, installed-version, asset and ZIP validation still apply downstream. No installed activation/version state or package-validation result is cached.

WordPress site transients provide ordinary option-backed storage on single-site and network storage on Multisite, or the configured object-cache backend. Keys hash canonical repository identity, API version, retrieval policy and site/network scope. Network ID is explicit because `site-transient` is a global object-cache group: sites in the same network share results, while different networks cannot share entries even with a global persistent cache. Expiration is a maximum, not a promise of retention; early eviction triggers a normal refresh. Bump the cache schema when changing stored-format or relevant eligibility semantics.

Cold/forced refreshes and invalidation use a per-repository zero-wait database advisory lock, separate from registry mutation locks. After acquisition, refresh local transient option snapshots (or force a backend cache read), then recheck for another writer's completed entry. Busy, unavailable or nested refreshes fail without redundant requests. Release happens in `finally`, including after exceptions. An invalidation that succeeds cannot be repopulated by a fetch that was already holding this same lock; contention returns a busy error instead of racing it. Like registry locking, this requires a stable primary MySQL/MariaDB connection; custom routers/failover and unusual persistent cache backends need integration verification.

The wrapper is explicitly loaded and remains outside admin page views and native update hooks. Future Check Now handlers must enforce capabilities/nonces before force or invalidation, and native update integration must refresh WordPress's own update metadata separately. Removing a registration does not require deleting public metadata caches: they are repository-scoped, contain no installed identity, and expire independently. Backend manual bypass is tested; no Check Now button or custom scheduler is introduced here. [WordPress 6.6 transient implementation](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/option.php) defines the backend behavior.

## HTTP failures, timeouts and rate limits

The client classifies failures without automatic retries:

| Situation | Diagnostic |
| --- | --- |
| Recognized local timeout or HTTP 408 | `github_updater_api_timeout` |
| Other transport failure | `github_updater_api_request_failed` |
| HTTP 401 or non-rate-limit 403 | `github_updater_api_access_denied` |
| HTTP 404 | `github_updater_api_not_found` (missing and private resources remain indistinguishable) |
| HTTP 410 | `github_updater_api_resource_gone` |
| HTTP 429 or recognized primary/secondary-limit 403 | `github_updater_api_rate_limited` |
| HTTP 5xx | `github_updater_api_unavailable` |
| Other unsuccessful HTTP statuses | `github_updater_api_http_error` |

WordPress wraps Requests transport exceptions in `http_request_failed`, so timeout recognition uses known local timeout wording/cURL code 28; unfamiliar transport messages safely fall back to the generic connectivity diagnostic. Raw transport details and error response bodies are never used as user-facing messages.

Rate classification uses strict header values and a small, valid JSON error message for known GitHub secondary-limit/abuse wording. A bare 403 is not assumed to be a rate limit. Retry-After accepts bounded nonnegative seconds or a valid canonical GMT HTTP date. Exhausted primary quota also considers the UTC epoch in `X-RateLimit-Reset`. For rate limits, use the longer valid hint with at least a one-minute wait; delays are capped at one day. Malformed/array/negative/exponent headers are ignored. `retry_after`, absolute `retry_at` and `retryable` are safe diagnostic data, not instructions to launch an automatic retry. HTTP 5xx honors a valid positive Retry-After too. The policy follows [GitHub's rate-limit retry guidance](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api#handle-rate-limit-errors-appropriately).

Cache schema v2 keeps a separate scoped cooldown marker. Force and invalidation preserve the deadline; if the marker is evicted but the metadata error still records the wait, force honors it and invalidation restores it before clearing metadata. If that restoration fails, invalidation returns an error and retains the known metadata error. After the deadline, explicit refresh can bypass the remaining ordinary five-minute error cache. These are repository/network-scoped transient records, not global IP-quota tracking; early eviction of all recorded state and unrelated applications/networks sharing an egress IP are outside that coordination. Cache schema v1 entries are ignored and expire on their existing schedules.

All failure scenarios are tested with mocked responses; tests do not deliberately exhaust GitHub limits or require a new managed-plugin version. Error-state presentation and final integration remain separate tasks.

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
php tests/admin.php
php tests/api-client.php
php tests/api-resilience.php
php tests/api-response-matrix.php
php tests/release-selection.php
php tests/archive-manifest.php
php tests/zip-inspector.php
php tests/zip-contents.php
php tests/packaged-plugin.php
php tests/asset-downloader.php
php tests/check-state.php
php tests/stable-releases.php
php tests/release-tag.php
php tests/version-comparison.php
php tests/release-asset.php
php tests/cached-releases.php
php tests/unsupported-environment.php wordpress
git diff --check
```

The offline API matrix runs real components together with deterministic HTTP, transient, clock and database doubles. It sends no network requests and does not boot WordPress:

| Boundary | Coverage |
| --- | --- |
| HTTP transport and response parsing | `api-client.php`, `api-resilience.php`: fixed endpoints, request bounds, JSON/media/root/size errors, status and retry-hint handling |
| Complete release retrieval | `stable-releases.php`: flags, dates, IDs, short pages, duplicates, ordering and scan limit |
| Cache and coordination | `cached-releases.php`: success/error lifetimes, bypass/invalidation, cooldown preservation, corruption, lock failures and scope isolation |
| Downstream metadata gates | `release-tag.php`, `version-comparison.php`, `release-asset.php`, `update-uri.php`: version/header/ZIP identity validation |
| Safe presentation | `check-state.php`: known/unknown errors, fixed guidance, retry hints and explicit healthy outcomes |
| Cross-component regressions | `api-response-matrix.php`: 44 staged failures through retrieval/cache/presentation, identity and pagination failures, warm-cache downstream rejection, forced-refresh failure and recovery |

The response matrix invokes downstream validators directly; `release-selection.php` separately exercises the production selector and composed checker through real retrieval/cache logic with offline doubles. Neither test establishes ZIP safety or installability.

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
4. Confirm the admin screen performs no GitHub requests or update/install actions, and that plugin initialization and a read-only page view do not create registry records. Test admin registration/removal separately with fixture cleanup.

Later logic tests will cover repository normalization, versions, releases, registry validation and package identity with mocked HTTP. Installation, replacement, rollback and self-updates require disposable single-site and Multisite integration environments. Never test destructive update behavior on production.

## Metadata-only release selection

`GitHub\\Release_Check::get($registration, $force = false)` composes exact installed-plugin discovery, current Update URI and Version checks, cached complete retrieval and `Release_Selector`. Consumers explicitly require these classes and their concrete dependencies. Supply a current registration from a ready registry; a structural `Registration` alone does not establish registry readiness. Installed headers are read through `Installed_Plugins` on each call, outside the repository cache. No check is hooked to WordPress or the admin screen yet.

The selector revalidates a completed candidate list and chooses the highest supported numeric version newer than the installed version, regardless of publication date. Unsupported stable tags fail the check. Multiple releases representing the highest newer numeric version (including `v1.2` and `1.2.0`) fail as ambiguous; lower-version ties do not affect a unique higher choice. It validates the highest release's exact ZIP metadata and returns its error if invalid or missing, without falling back to another release or source archive. Older assets do not affect a valid highest choice.

Results are an immutable `Release_Candidate` (identity, original tag, normalized version and `Release_Asset` metadata pointer), an explicit healthy `Check_State` for no stable releases/no newer version, or `WP_Error`. Failures never become an up-to-date result. Candidates are not installable updates: archive contents, plugin identity, compatibility and replacement conflicts still require validation before native update integration. Future action handlers must enforce capabilities/nonces and recheck registry and installed prerequisites before replacing code. Force retains backend cooldown rules; selection results themselves are not cached.

## Check outcome presentation

`GitHub\\Check_State` is an explicitly loaded, unhooked presentation adapter. `from_error($error)` maps known GitHub, header, version, asset and cache errors to fixed translated titles, descriptions and next steps. Unknown errors receive a conservative fallback; remote messages, arbitrary data and package URLs are excluded. Keep the original `WP_Error` for diagnostics and escape view text when rendering.

Cooldown states retain bounded retry deadlines. `retry_suggested_now()` is only a display hint: backend cooldowns, capabilities and nonces still govern requests. No retry is scheduled. `no_releases()` is reserved for a completed validated empty scan; `no_update()` requires a completed successful comparison. Failed checks never become either successful outcome. This adapter does not retrieve releases, establish package eligibility or add administration controls.

## Pre-extraction manifest policy

`Packages\\Archive_Manifest::check($entries, $registration)` is the first package-security layer. It validates a complete reader-produced entry list without extracting files or reading a ZIP. Each entry must describe its raw name, file/directory type, declared expanded/compressed sizes, encryption flag and compression method. `Zip_Inspector` supplies this list after raw-record checks; user-supplied or sanitized lists cannot establish archive safety.

The policy requires only the exact registered root directory and a nonempty file at the registered main basename. Explicit directory records are optional. It rejects traversal/absolute/backslash paths, empty or dot segments, control characters, encoded-path syntax, Windows-reserved names, trailing dots/spaces, duplicate paths, case collisions and file/directory conflicts, including implicit parents. It permits only conservative ASCII names, ordinary files/directories, unencrypted stored/deflated entries. Packages with Unicode names or extra roots such as `__MACOSX` are rejected rather than rewritten.

| Limit | Maximum |
| --- | --- |
| Entries | 5,000 |
| Raw path length / depth | 240 bytes / 20 components including root |
| Declared size per file | 20 MiB |
| Total declared expanded / compressed entry bytes | 100 MiB / 50 MiB |
| Declared per-entry expansion ratio | 100:1 |

Passing this policy returns `true`; failures return fixed `WP_Error` diagnostics without reflecting entry paths. A pass is not package approval. ZIP inspection adds record consistency and an actual local archive size cap. Bounded download/decompression and actual expanded-byte limits, headers/version/repository identity, compatibility and destination conflicts remain mandatory. Nothing invokes this component from update hooks or the administration screen yet.

## Bounded asset downloading

`Packages\Asset_Downloader::with_file($asset, $consume)` downloads a validated `Release_Asset`, passes its local temporary path to a synchronous callback, and attempts deletion in `finally` on success, error or exception. A normal cleanup failure returns `WP_Error`; callback exceptions propagate after the deletion attempt. The callback must consume the same bytes, validate them and return its result, never retain the path as approval. Consumers explicitly load the downloader and its dependencies. There is no update/admin hook or persistent download cache.

The first request uses the exact selected API asset endpoint with `Accept: application/octet-stream` and the pinned API version. WordPress safe HTTP streams each response to a 0600 temporary file with TLS verification, no credentials/cookies, no automatic redirects and no content decompression. Redirect bodies are cleared before the next request. Only the exact selected browser asset URL and literal release-storage hosts `release-assets.githubusercontent.com` and `objects.githubusercontent.com` with supported `github-production-release-asset` paths are accepted. Relative URLs, repository moves, other hosts, credentials, ports, fragments, encoded paths and loops fail closed. After the first storage redirect, that raw host/path is pinned; signed query parameters may vary and are never returned in diagnostics. This deliberately trusts a GitHub-issued storage location, not arbitrary URLs or wildcard subdomains.

Limits: selected size at most 50 MiB, at most three manually validated redirects (four requests), 20 seconds per request and a 60-second monotonic total budget. Each response has a selected-size-plus-one overflow cap; consequently the chain has at most four bounded responses, not a 50 MiB aggregate bandwidth guarantee. Final status must be 200 with identity/no Content-Encoding, consistent Content-Length when present and an actual streamed byte count exactly matching selected metadata. Partial responses, stale sizes and transport failures are rejected with fixed messages; there are no retries. MIME type and Content-Disposition do not establish safety or rename the temporary file.

This is a download stage only. ZIP inspection, payload/CRC/expanded-byte checks, plugin identity/version/runtime compatibility and replacement conflicts must still pass. Future callers enforce registry readiness, installed-state checks, capabilities and nonces. The downloader's callback lifetime keeps cleanup ownership explicit while the remaining gates are developed. HTTP streaming/size options were reviewed against [WordPress 6.6 HTTP source](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/class-wp-http.php); direct/redirected binary API behavior is documented in [GitHub's release asset API](https://docs.github.com/en/rest/releases/assets?apiVersion=2022-11-28#get-a-release-asset).

## ZIP record inspection

`Packages\Zip_Inspector::inspect($absolute_local_path, $registration)` reads a caller-owned stable regular temporary file, capped at 50 MiB including headers and comments. It returns the complete raw manifest only after record checks and `Archive_Manifest::check()` pass, or a fixed `WP_Error`. Explicitly load it and its concrete dependencies. It never downloads, extracts, executes plugin code or modifies the archive. PHP must be 64-bit; no ZipArchive extension or external executable is used.

The supported subset is classic single-disk ZIP with stored/deflated entries, known DOS or Unix creators, ordinary file/directory attributes and optional signed/unsigned 32-bit data descriptors. Only bounded timestamp/ownership extra fields (0x5455, 0x7875, 0x000a) are allowed; they are ignored as opaque metadata and cannot rename entries. ZIP64, spanning, encryption, alternate-name extras, unknown creator/types/flags, special permissions and malformed records fail closed. This intentionally excludes some otherwise valid ZIPs rather than falling back to a more permissive reader.

Inspection compares central and local raw names, flags, methods, timestamps, sizes and CRC declarations; validates descriptor declarations; checks central-directory boundaries and unique end record; and requires contiguous nonoverlapping local records from byte zero. Duplicate names are preserved for manifest rejection. Stubs, hidden/unindexed local records, padding and trailing bytes are rejected. These record rules follow the [PKWARE ZIP specification](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT).

A manifest pass is not a CRC or payload-integrity check: compressed data is not decompressed here. `Zip_Contents` enforces actual expanded-byte limits and CRCs on the same open handle after inspection. `Packaged_Plugin` adds exact headers and declared PHP/WordPress compatibility; replacement/activation conflict checks still remain. The caller must prevent changes to the temporary file between stages; neither a path nor a manifest is a reusable approval token. Native update offers remain unwired.

`php tests/zip-inspector.php` uses actual temporary ZIP bytes, independently written Python ZIP vectors, hostile record mutations, truncation at every byte of a small archive, descriptors, overlap checks, path/type/extra-field failures and an oversized sparse file. Fixture creation uses test-only zlib; Python is not needed to run the embedded vectors. Temporary files are cleaned up. No archive is extracted.

## ZIP payload integrity

`Packages\Zip_Contents::check($local_path, $registration)` runs the ZIP record/manifest gates and validates every stored or deflated payload on the inspector's same open read-only handle. The inspector's `with_entries()` callback supplies checked payload offsets and hexadecimal CRC declarations only after the entire archive layout passes; its public `inspect()` manifest shape is unchanged. The handle is closed in `finally`, including consumer exceptions. No caller-supplied manifest can bypass inspection.

Stored data is read in 64 KiB chunks; raw deflate input uses 256-byte chunks. PHP's incremental zlib API has no output-length argument, so small input chunks limit temporary expansion per call; each resulting output chunk is immediately checked, hashed and discarded. No complete decompressed file is retained. The check enforces exact declared expanded sizes, the existing 20 MiB per-file and 100 MiB aggregate expanded limits, and a 30-second monotonic budget checked between operations. It requires deflate stream completion and exact compressed-byte consumption, rejecting truncated streams, trailing garbage and concatenated streams. Calculated CRC32 must match each entry, including empty files and directories. CRC is an integrity check, not proof of authenticity or safe code.

Missing incremental zlib functions produce a fixed error for deflated packages; stored payloads remain supported. This is a package capability gate, not a new plugin activation requirement. Malformed-data zlib warnings become fixed errors, with the prior error handler restored. The implementation uses PHP's [incremental inflater](https://www.php.net/manual/en/function.inflate-add.php), [stream status](https://www.php.net/manual/en/function.inflate-get-status.php) and [consumed-byte count](https://www.php.net/manual/en/function.inflate-get-read-len.php).

`php tests/zip-contents.php` covers real ZIP payload corruption, CRC and size mismatches, truncated/extra/concatenated deflate data, stored/deflated and descriptor cases, empty files/directories, an actual file-size overflow, exact size boundaries, deadlines, missing-zlib behavior and stream/error-handler cleanup. A subprocess validates 100 MiB of expanded content with PHP `memory_limit=16M`. No archive is extracted. The optional `plugin_prefixes()` API retains only the first 8 KiB of up to 64 top-level PHP files and returns them only after every payload passes; ordinary `check()` still returns only an integrity result. `Packaged_Plugin` consumes these prefixes. Replacement conflict checks remain before update integration; the downloader callback must retain cleanup ownership throughout validation.

## Packaged plugin identity and requirements

`Packages\Packaged_Plugin::check($local_zip, $registration, $selected_release_tag)` composes ZIP record, manifest and actual payload integrity checks, then validates headers from the exact registered main basename. It returns a fixed `WP_Error` or the raw main-file header values, which must be escaped if displayed. There is no extraction, plugin-code execution, file repair, caching or native update hook. The caller still supplies a current explicit registration and freshly selected release identity and protects the same temporary bytes throughout later gates.

`Plugin_Headers` matches WordPress 6.6's first-8-KiB, CR-newline and comment-cleanup rules for Plugin Name, Version, Update URI, Requires at least, Requires PHP and Requires Plugins. It additionally rejects duplicate recognized headers and control characters. The main file must have a nonempty Plugin Name, a supported Version numerically equal to the selected tag (optional lowercase v and trailing-zero equivalence), and a matching routable Update URI. Display-name changes do not change basename identity. Other top-level PHP files declaring a plugin name are rejected; nested library/example files are not native top-level entry points. Prefix capture is capped at 64 root PHP files/512 KiB total; all archive payloads still pass integrity before any prefix is used.

Absent PHP/WordPress minimums follow native optional-header semantics. Nonempty minimums must contain one to four ordinary numeric components (no suffixes, operators, leading zeros or v); native `is_php_version_compatible()` and `is_wp_version_compatible()` check the current runtime. A nonempty Requires Plugins header is rejected until dependency validation is supported. This conservative limitation prevents unsupported dependency requirements from silently passing. Duplicate header and root-entry-point rejection may likewise exclude packages that native parsing alone would tolerate.

Header syntax and compatibility behavior were checked against [WordPress 6.6 functions](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-includes/functions.php) and [plugin administration APIs](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-admin/includes/plugin.php). This verifies declared requirements, not PHP syntax, arbitrary code behavior, network/site activation transitions or destination conflicts. Those remaining gates must be enforced before update offers/replacement, including automatic and bulk updates.

## Release and trust boundaries

Future releases accept explicitly registered public repositories and uploaded WordPress-ready `plugin-slug.zip` assets. GitHub repository ownership is a trust decision: identity checks and HTTPS cannot establish that plugin code is safe. Package validation must precede any installable update integration. Private repositories and themes are deferred.

Release ZIPs must contain the `wordpress-github-updater/` root directory and runtime files. Omit Git metadata, development tests and local planning documents (`ROADMAP.md` and `AGENTS.md`). Those planning documents are locally excluded from Git and must never be committed. No release packaging automation exists yet.
