<?php
/** Compare a staged local tree to fully validated ZIP payloads. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only evidence for a subsequent coordinated source-selection gate. */
final class Staged_Files {
	public const MAX_NODES   = 5000;
	public const MAX_SECONDS = 60;

	/** @param Zip_Contents $contents Complete raw ZIP/manifest/integrity validation. */
	public function __construct( private readonly Zip_Contents $contents = new Zip_Contents() ) {}

	/**
	 * Working root must contain precisely the registered plugin tree. Recompute
	 * fingerprints from the protected ZIP; never trust a caller-supplied map.
	 * Caller binds the actual native working/source directory and protects both
	 * ZIP and staged bytes until copying; this is not an atomic filesystem lock.
	 *
	 * @param string $zip Protected ZIP. @param string $working Native extraction root.
	 * @param Registration $registration Exact expected identity.
	 * @return true|WP_Error No extraction, modification, execution or approval token.
	 */
	public function check( string $zip, string $working, Registration $registration ): true|WP_Error {
		$deadline = hrtime( true ) / 1000000000 + self::MAX_SECONDS;
		$root = rtrim( $working, '/' );
		clearstatcache();
		if ( '' === $root || ! str_starts_with( $root, '/' ) || str_contains( $root, "\0" ) || is_link( $root ) || realpath( $root ) !== $root || ! is_dir( $root ) ) {
			return $this->error( 'root' );
		}
		$plugins = defined( 'WP_PLUGIN_DIR' ) ? realpath( WP_PLUGIN_DIR ) : false;
		if ( false !== $plugins && ( $root === $plugins || str_starts_with( $root, $plugins . '/' ) || str_starts_with( $plugins, $root . '/' ) ) ) {
			return $this->error( 'root' );
		}
		$payloads = $this->contents->fingerprints( $zip, $registration );
		if ( $payloads instanceof WP_Error ) {
			return $payloads;
		}
		$expected = array();
		foreach ( $payloads as $name => $payload ) {
			$parts = explode( '/', rtrim( $name, '/' ) );
			$path = '';
			foreach ( $parts as $index => $part ) {
				$path = '' === $path ? $part : $path . '/' . $part;
				$leaf = $index === count( $parts ) - 1;
				$expected[ $path ] = $leaf ? $payload : array( 'type' => 'directory' );
				if ( count( $expected ) > self::MAX_NODES ) {
					return $this->error( 'limits' );
				}
			}
		}
		$count = 0;
		$result = $this->walk( $root, '', $expected, $count, $deadline );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return $count === count( $expected ) ? true : $this->error( 'mismatch' );
	}

	/** @return true|WP_Error Inspect without following links; exact names include case. */
	private function walk( string $root, string $relative, array $expected, int &$count, float $deadline ): true|WP_Error {
		if ( hrtime( true ) / 1000000000 > $deadline ) {
			return $this->error( 'timeout' );
		}
		$path = '' === $relative ? $root : $root . '/' . $relative;
		clearstatcache( true, $path );
		$before = @lstat( $path );
		if ( false === $before || 0040000 !== ( $before['mode'] & 0170000 ) || realpath( $path ) !== $path ) {
			return $this->error( 'type' );
		}
		$dir = @opendir( $path );
		if ( false === $dir ) {
			return $this->error( 'read' );
		}
		try {
			while ( false !== ( $name = readdir( $dir ) ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ++$count > self::MAX_NODES ) {
					return $this->error( 'limits' );
				}
				$key = '' === $relative ? $name : $relative . '/' . $name;
				if ( ! isset( $expected[ $key ] ) || substr_count( $key, '/' ) + 1 > Archive_Manifest::MAX_DEPTH ) {
					return $this->error( 'mismatch' );
				}
				$child = $root . '/' . $key;
				clearstatcache( true, $child );
				$stat = @lstat( $child );
				if ( false === $stat || ( $stat['mode'] & 07000 ) ) {
					return $this->error( 'type' );
				}
				$want = $expected[ $key ];
				if ( 'directory' === $want['type'] ) {
					$result = $this->walk( $root, $key, $expected, $count, $deadline );
				} elseif ( 0100000 === ( $stat['mode'] & 0170000 ) && 1 === $stat['nlink'] && $stat['size'] === $want['size'] ) {
					$result = $this->file( $child, $want, $stat, $deadline );
				} else {
					return $this->error( 'type' );
				}
				if ( $result instanceof WP_Error ) {
					return $result;
				}
			}
		} finally {
			closedir( $dir );
		}
		clearstatcache( true, $path );
		return $this->same_node( $before, @lstat( $path ) ) ? true : $this->error( 'changed' );
	}

	/** @return true|WP_Error Bounded reads verify size, SHA-256 and stable file identity. */
	private function file( string $path, array $want, array $before, float $deadline ): true|WP_Error {
		$stream = @fopen( $path, 'rb' );
		if ( false === $stream ) {
			return $this->error( 'read' );
		}
		try {
			if ( ! $this->same_node( $before, fstat( $stream ) ) ) {
				return $this->error( 'changed' );
			}
			$hash = hash_init( 'sha256' );
			$bytes = 0;
			while ( ! feof( $stream ) ) {
				if ( hrtime( true ) / 1000000000 > $deadline ) {
					return $this->error( 'timeout' );
				}
				$chunk = fread( $stream, min( 65536, $want['size'] - $bytes + 1 ) );
				if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
					return $this->error( 'read' );
				}
				$bytes += strlen( $chunk );
				if ( $bytes > $want['size'] ) {
					return $this->error( 'mismatch' );
				}
				hash_update( $hash, $chunk );
			}
			if ( $bytes !== $want['size'] || hash_final( $hash ) !== $want['sha256'] ) {
				return $this->error( 'mismatch' );
			}
			clearstatcache( true, $path );
			return $this->same_node( $before, fstat( $stream ) ) && $this->same_node( $before, @lstat( $path ) ) ? true : $this->error( 'changed' );
		} finally {
			fclose( $stream );
		}
	}
	private function same_node( array $before, array|false $after ): bool {
		if ( false === $after ) {
			return false;
		}
		foreach ( array( 'dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime' ) as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				return false;
			}
		}
		return true;
	}
	private function error( string $code ): WP_Error {
		return new WP_Error( 'github_updater_staged_' . $code, __( 'The staged plugin tree does not match the validated package or cannot be inspected safely. No replacement approval was issued.', 'wordpress-github-updater' ) );
	}
}
