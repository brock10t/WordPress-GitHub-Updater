<?php
/**
 * Route standard native plugin updates through retained validation scopes.
 *
 * @package TenTGitHubUpdater
 */
namespace TenT\GitHubUpdater\Updates;
use TenT\GitHubUpdater\Registry\Registry;
use TenT\GitHubUpdater\GitHub\Release_Candidate;
use TenT\GitHubUpdater\GitHub\Release_Check;
use WP_Error;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Does not publish metadata, override update eligibility or opt users into auto-updates. */
final class Native_Router {
	private array $pending = array();
	private ?array $item = null;
	private ?Request_Handoff $scope = null;
	private array $upgraders = array();
	public function __construct( private readonly Registry $registry = new Registry() ) {}
	public function register_hooks(): void {
		add_filter( 'upgrader_package_options', array( $this, 'options' ), PHP_INT_MAX );
		add_filter( 'pre_unzip_file', array( $this, 'pre_unzip' ), PHP_INT_MAX, 3 );
		add_filter( 'unzip_file', array( $this, 'unzipped' ), PHP_INT_MAX, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'final_source' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'result' ), PHP_INT_MAX, 2 );
		add_action( 'shutdown', array( $this, 'before_shutdown' ), 0 );
		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MAX );
	}
	/** Bind the exact native update options before core starts the item. */
	public function options( mixed $options ): mixed {
		if ( ! is_array( $options ) || ( class_exists( Native_Session::class, false ) && null !== Native_Session::current() ) ) { return $options; }
		foreach ( array( 'upgrader_pre_download', 'upgrader_pre_install', 'upgrader_source_selection' ) as $hook ) { if ( doing_filter( $hook ) ) { return $options; } }
		if ( null !== $this->item ) { $this->end_item( $this->error( 'abandoned' ) ); }
		$extra = $options['hook_extra'] ?? null;
		$plugin = is_array( $extra ) ? ( $extra['plugin'] ?? null ) : null;
		if ( ! is_string( $plugin ) || ! in_array( $extra['type'] ?? 'plugin', array( 'plugin' ), true ) || ! in_array( $extra['action'] ?? 'update', array( 'update' ), true ) ) { return $options; }
		$record = $this->registry->get( $plugin );
		if ( $record instanceof WP_Error ) { return $options; }
		if ( ( $options['destination'] ?? null ) !== WP_PLUGIN_DIR || true !== ( $options['clear_destination'] ?? null ) || true !== ( $options['clear_working'] ?? null ) ) { return $options; }
		$backup = array( 'slug' => $record->plugin_slug, 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins' );
		if ( ( $extra['temp_backup'] ?? null ) !== $backup ) { return $options; }
		$token = bin2hex( random_bytes( 32 ) );
		$options['hook_extra']['type'] = 'plugin'; $options['hook_extra']['action'] = 'update';
		$options['hook_extra']['_10t_route'] = $token;
		$this->pending = array( 'token' => $token, 'record' => $record, 'package' => $options['package'] );
		return $options;
	}
	public function matches( mixed $extra ): bool {
		return null !== $this->item && is_array( $extra ) && ( $extra['_10t_route'] ?? null ) === $this->item['token'] && ( $extra['plugin'] ?? null ) === $this->item['record']->plugin_basename;
	}
	/** Retain the protected ZIP and give core a disposable private copy. */
	public function download( mixed $reply, mixed $package, mixed $upgrader, mixed $extra ): mixed {
		if ( false !== $reply || ! $upgrader instanceof \Plugin_Upgrader || ! is_array( $extra ) || ( $extra['_10t_route'] ?? null ) !== ( $this->pending['token'] ?? null ) || ( $extra['plugin'] ?? null ) !== ( $this->pending['record']->plugin_basename ?? null ) || $package !== ( $this->pending['package'] ?? null ) || null !== $this->item ) { return $this->error( 'context' ); }
		$this->load();
		$record = $this->pending['record'];
		// Selection is repeated under the retained scopes before download.
		$selected = ( new Release_Check() )->get( $record );
		if ( ! $selected instanceof Release_Candidate ) { return $selected instanceof WP_Error ? $selected : $this->error( 'selection' ); }
		if ( ! in_array( $package, array( $selected->asset->api_url, $selected->asset->download_url ), true ) ) { return $this->error( 'package' ); }
		$base = rtrim( WP_CONTENT_DIR, '/' );
		foreach ( array( $base, $base . '/upgrade', $base . '/upgrade-temp-backup', $base . '/upgrade-temp-backup/plugins' ) as $dir ) { if ( is_link( $dir ) || ( file_exists( $dir ) && ( ! is_dir( $dir ) || realpath( $dir ) !== $dir ) ) ) { return $this->error( 'working' ); } }
		if ( file_exists( $base . '/upgrade-temp-backup/plugins/' . $record->plugin_slug ) || is_link( $base . '/upgrade-temp-backup/plugins/' . $record->plugin_slug ) ) { return $this->error( 'backup' ); }
		if ( is_dir( $base . '/upgrade' ) ) { $list = scandir( $base . '/upgrade' ); if ( false === $list || array_diff( $list, array( '.', '..', 'index.php' ) ) || is_link( $base . '/upgrade/index.php' ) ) { return $this->error( 'working' ); } }
		$this->scope ??= new Request_Handoff();
		$path = $this->scope->begin( $record, $selected );
		if ( ! is_string( $path ) ) { return $path; }
		$copy = wp_tempnam( '10t-native-package' );
		if ( ! is_string( $copy ) || ! chmod( $copy, 0600 ) || ! copy( $path, $copy ) || hash_file( 'sha256', $copy ) !== hash_file( 'sha256', $path ) ) { if ( is_string( $copy ) ) { wp_delete_file( $copy ); } $this->scope->finish( $this->error( 'temporary' ) ); return $this->error( 'temporary' ); }
		$this->item = $this->pending + array( 'upgrader' => $upgrader, 'path' => $path, 'copy' => $copy, 'hash' => hash_file( 'sha256', $path ), 'working' => null, 'working_stat' => null, 'phase' => 'downloaded' );
		$this->pending = array(); $this->upgraders[ spl_object_id( $upgrader ) ] = $upgrader;
		return $copy;
	}
	/** Reject extraction overrides and changed archive bytes. */
	public function pre_unzip( mixed $value, mixed $file, mixed $to ): mixed {
		if ( null === $this->item || $file !== $this->item['copy'] ) { return $value; }
		if ( null !== $value || ! is_file( $file ) || is_link( $file ) || hash_file( 'sha256', $file ) !== $this->item['hash'] || ! $this->scope->owns() ) { return $this->error( 'archive' ); }
		return $value;
	}
	/** Bind the actual native working directory and verify every staged file. */
	public function unzipped( mixed $value, mixed $file, mixed $to ): mixed {
		if ( null === $this->item || $file !== $this->item['copy'] || $value instanceof WP_Error ) { return $value; }
		$working = is_string( $to ) ? rtrim( $to, '/' ) : '';
		$parent = rtrim( WP_CONTENT_DIR, '/' ) . '/upgrade';
		if ( '' === $working || dirname( $working ) !== $parent || is_link( $working ) || realpath( $working ) !== $working ) { return $this->error( 'working' ); }
		$this->item['working'] = $working; $this->item['working_stat'] = lstat( $working );
		$valid = $this->scope->check( $working );
		if ( $valid instanceof WP_Error ) { return $valid; }
		$this->item['phase'] = 'staged'; return $value;
	}
	/** Recheck before native deactivation and maintenance callbacks. */
	public function pre_install( mixed $value, mixed $extra ): mixed {
		if ( ! $this->matches( $extra ) || 'staged' !== $this->item['phase'] ) { return $this->error( 'context' ); }
		$valid = $this->scope->check( $this->item['working'] );
		if ( $valid instanceof WP_Error ) { return $valid; }
		remove_filter( 'upgrader_pre_install', array( $this->item['upgrader'], 'deactivate_plugin_before_upgrade' ), 10 );
		$this->item['phase'] = 'preinstalled'; return $value;
	}
	/** Require the original upgrader and exact registered source directory. */
	public function source( mixed $source, mixed $remote, mixed $upgrader, mixed $extra ): mixed {
		if ( ! $this->matches( $extra ) || $upgrader !== $this->item['upgrader'] || 'preinstalled' !== $this->item['phase'] || $remote !== $this->item['working'] || ! is_string( $source ) || rtrim( $source, '/' ) !== $remote . '/' . $this->item['record']->plugin_slug ) { return $this->error( 'context' ); }
		$valid = $this->scope->check( $remote ); if ( $valid instanceof WP_Error ) { return $valid; }
		$this->item['phase'] = 'copy_ready'; return $source;
	}
	/** Recheck after earlier source-selection filters. */
	public function final_source( mixed $source, mixed $remote, mixed $upgrader, mixed $extra ): mixed {
		if ( $source instanceof WP_Error || ! $this->matches( $extra ) ) { return $source; }
		if ( $upgrader !== $this->item['upgrader'] || 'copy_ready' !== $this->item['phase'] || $remote !== $this->item['working'] || ! is_string( $source ) || rtrim( $source, '/' ) !== $remote . '/' . $this->item['record']->plugin_slug ) { return $this->error( 'context' ); }
		$valid = $this->scope->check( $remote );
		return $valid instanceof WP_Error ? $valid : $source;
	}

	/** Finish this item while retaining request ownership for native backups. */
	public function result( mixed $result, mixed $extra ): mixed {
		if ( ! $this->matches( $extra ) ) { return $result; }
		if ( ! $result instanceof WP_Error && 'copy_ready' !== $this->item['phase'] ) { $result = $this->error( 'context' ); }
		return $this->end_item( $result );
	}
	private function end_item( mixed $result ): mixed {
		if ( null === $this->item ) { return $result; }
		wp_delete_file( $this->item['copy'] );
		if ( file_exists( $this->item['copy'] ) || is_link( $this->item['copy'] ) ) { $result = $this->error( 'temporary_cleanup' ); }
		if ( null !== $this->item['working'] && file_exists( $this->item['working'] ) ) {
			$stat = lstat( $this->item['working'] ); $initial = $this->item['working_stat']; $count = 0;
			if ( false === $stat || $stat['dev'] !== $initial['dev'] || $stat['ino'] !== $initial['ino'] || ! $this->remove_tree( $this->item['working'], 0, $count ) ) { $result = $this->error( 'staging_cleanup' ); }
		}
		$this->item = null;
		return $this->scope->finish( $result );
	}
	/** Core backup callbacks must not write if our primary connection lost ownership. */
	public function before_shutdown(): void {
		if ( null === $this->scope || $this->scope->owns() ) { return; }
		foreach ( $this->upgraders as $upgrader ) { remove_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ), 10 ); remove_action( 'shutdown', array( $upgrader, 'delete_temp_backup' ), 100 ); }
		do_action( 'wordpress_github_updater_cleanup_error', $this->error( 'ownership' ) );
	}
	/** Release retained scopes only after native deferred backup callbacks. */
	public function shutdown(): void {
		if ( null === $this->scope ) { return; }
		$cleanup = $this->end_item( $this->error( 'abandoned' ) );
		if ( $cleanup instanceof WP_Error && 'github_updater_route_abandoned' !== $cleanup->get_error_code() ) { do_action( 'wordpress_github_updater_cleanup_error', $cleanup ); }
		$result = $this->scope->close();
		$this->scope = null;
		if ( $result instanceof WP_Error ) { do_action( 'wordpress_github_updater_cleanup_error', $result ); }
	}
	private function remove_tree( string $path, int $depth, int &$count ): bool {
		$stat = @lstat( $path );
		if ( false === $stat ) { return ! file_exists( $path ); }
		if ( ++$count > 5001 || $depth > 20 ) { return false; }
		if ( 0040000 !== ( $stat['mode'] & 0170000 ) ) { return @unlink( $path ); }
		$dir = @opendir( $path ); if ( false === $dir ) { return false; }
		$ok = true;
		try { while ( false !== ( $name = readdir( $dir ) ) ) { if ( '.' !== $name && '..' !== $name && ! $this->remove_tree( $path . '/' . $name, $depth + 1, $count ) ) { $ok = false; } } } finally { closedir( $dir ); }
		return $ok && @rmdir( $path );
	}

	private function load(): void {
		foreach ( array(
			'github/class-api-client.php',
			'github/class-stable-releases.php',
			'github/class-cached-releases.php',
			'github/class-release-tag.php',
			'github/class-version-comparison.php',
			'github/class-release-asset.php',
			'github/class-release-candidate.php',
			'github/class-release-selector.php',
			'github/class-check-state.php',
			'github/class-release-check.php',
			'packages/class-archive-manifest.php',
			'packages/class-zip-inspector.php',
			'packages/class-zip-contents.php',
			'packages/class-plugin-headers.php',
			'packages/class-packaged-plugin.php',
			'packages/class-staged-files.php',
			'packages/class-replacement-check.php',
			'packages/class-asset-downloader.php',
			'updates/class-upgrade-lock.php',
			'updates/class-validated-handoff.php',
			'updates/class-request-handoff.php',
		) as $file ) {
			require_once dirname( __DIR__ ) . '/' . $file;
		}
	}
	private function error( string $code ): WP_Error { return new WP_Error( 'github_updater_route_' . $code, __( 'The native managed update could not be validated or coordinated. Refresh before retrying; inspect any retained backup or cleanup error.', 'wordpress-github-updater' ) ); }
}
