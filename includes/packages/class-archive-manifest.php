<?php
/**
 * Conservative validation of archive entries before any extraction.
 *
 * @package TenTGitHubUpdater
 */

namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure manifest policy, not a ZIP reader or proof that a package is installable.
 */
final class Archive_Manifest {
	public const MAX_ENTRIES          = 5000;
	public const MAX_PATH_BYTES       = 240;
	public const MAX_DEPTH            = 20;
	public const MAX_FILE_BYTES       = 20971520;
	public const MAX_EXPANDED_BYTES   = 104857600;
	public const MAX_COMPRESSED_BYTES = 52428800;
	public const MAX_RATIO            = 100;

	/**
	 * Validate the entire manifest against the exact registered plugin root.
	 *
	 * A future ZIP reader must supply EVERY raw entry without sanitizing names,
	 * decoding escapes, following links or collapsing duplicates. It must derive
	 * type, encryption and method from archive records, rejecting inconsistencies
	 * and unknown attributes. Sizes here are declarations, not trusted byte counts.
	 * Extraction must enforce actual limits and validate the resulting contents.
	 *
	 * Entries: name (raw string), type ('file'/'directory'), size (integer),
	 * compressed_size (integer), encrypted (boolean), method (integer: 0 or 8).
	 * No extraction, network, disk reads, header checks or writes take place.
	 *
	 * @param array<mixed> $entries      Complete reader-produced manifest.
	 * @param Registration $registration Expected installed directory/main file.
	 * @return true|WP_Error Manifest policy passed, or a fixed safe diagnostic.
	 */
	public static function check( array $entries, Registration $registration ): true|WP_Error {
		if ( ! array_is_list( $entries ) || array() === $entries || count( $entries ) > self::MAX_ENTRIES ) {
			return self::error( 'limits', __( 'The package has an empty, invalid or excessive archive entry list.', 'wordpress-github-updater' ) );
		}
		$nodes      = array();
		$expanded   = 0;
		$compressed = 0;
		$main_found = false;
		foreach ( $entries as $entry ) {
			if (
				! is_array( $entry ) || ! isset( $entry['name'], $entry['type'], $entry['size'], $entry['compressed_size'], $entry['encrypted'], $entry['method'] )
				|| ! is_string( $entry['name'] ) || ! is_string( $entry['type'] )
				|| ! is_int( $entry['size'] ) || ! is_int( $entry['compressed_size'] )
				|| ! is_bool( $entry['encrypted'] ) || ! is_int( $entry['method'] )
				|| $entry['size'] < 0 || $entry['compressed_size'] < 0
			) {
				return self::error( 'metadata', __( 'The archive reader returned incomplete or invalid entry information.', 'wordpress-github-updater' ) );
			}
			if ( ! in_array( $entry['type'], array( 'file', 'directory' ), true ) || $entry['encrypted'] || ! in_array( $entry['method'], array( 0, 8 ), true ) ) {
				return self::error( 'entry_type', __( 'The package contains links, special files, encryption or an unsupported compression method.', 'wordpress-github-updater' ) );
			}
			$name      = $entry['name'];
			$directory = 'directory' === $entry['type'];
			if ( $directory !== str_ends_with( $name, '/' ) || strlen( $name ) > self::MAX_PATH_BYTES ) {
				return self::unsafe_path();
			}
			$path  = $directory ? substr( $name, 0, -1 ) : $name;
			$parts = explode( '/', $path );
			if ( count( $parts ) > self::MAX_DEPTH ) {
				return self::unsafe_path();
			}
			foreach ( $parts as $part ) {
				// Deliberately ASCII-only; reject cross-platform aliases, not repair them.
				if (
					'' === $part || '.' === $part || '..' === $part
					|| ! preg_match( '/\A[A-Za-z0-9._@+ -]+\z/', $part )
					|| preg_match( '/[. ]\z|\A /', $part )
					|| preg_match( '/\A(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?: *\.| *\z)/i', $part )
				) {
					return self::unsafe_path();
				}
			}
			if ( $parts[0] !== $registration->plugin_slug || ( 1 === count( $parts ) && ! $directory ) ) {
				return self::error( 'identity', __( 'The archive must contain only the exact registered plugin directory.', 'wordpress-github-updater' ) );
			}
			if ( $directory && 0 !== $entry['size'] ) {
				return self::error( 'metadata', __( 'An archive directory contains unexpected file data.', 'wordpress-github-updater' ) );
			}
			$size = $entry['size'];
			$zip  = $entry['compressed_size'];
			// Subtraction avoids overflow even for adversarial PHP_INT_MAX sizes.
			if (
				$size > self::MAX_FILE_BYTES || $size > self::MAX_EXPANDED_BYTES - $expanded
				|| $zip > self::MAX_COMPRESSED_BYTES - $compressed
				|| ( $size > 0 && ( 0 === $zip || $size / $zip > self::MAX_RATIO ) )
				|| ( 0 === $entry['method'] && $size !== $zip )
			) {
				return self::error( 'limits', __( 'The archive exceeds supported file, total size or compression-ratio limits.', 'wordpress-github-updater' ) );
			}
			$expanded   += $size;
			$compressed += $zip;
			$prefix      = '';
			$last        = count( $parts ) - 1;
			foreach ( $parts as $index => $part ) {
				$prefix = '' === $prefix ? $part : $prefix . '/' . $part;
				$key    = strtolower( $prefix );
				$leaf   = $index === $last;
				$type   = $leaf && ! $directory ? 'file' : 'directory';
				$known  = $nodes[ $key ] ?? null;
				if ( null !== $known && ( $known['path'] !== $prefix || $known['type'] !== $type || ( $leaf && $known['explicit'] ) ) ) {
					return self::error( 'collision', __( 'The archive contains duplicate paths, case collisions or file/directory conflicts.', 'wordpress-github-updater' ) );
				}
				$nodes[ $key ] = array( 'path' => $prefix, 'type' => $type, 'explicit' => $leaf || ( $known['explicit'] ?? false ) );
			}
			if ( $path === $registration->plugin_basename && ! $directory && $size > 0 ) {
				$main_found = true;
			}
		}
		if ( ! $main_found ) {
			return self::error( 'main_file', __( 'The archive is missing the nonempty registered main plugin file.', 'wordpress-github-updater' ) );
		}
		return true;
	}

	/** @return WP_Error No untrusted path is reflected in this diagnostic. */
	private static function unsafe_path(): WP_Error {
		return self::error( 'path', __( 'The archive contains an unsafe or unsupported path. Paths are not repaired automatically.', 'wordpress-github-updater' ) );
	}

	/** @param string $suffix Fixed code suffix. @param string $message Fixed text. @return WP_Error */
	private static function error( string $suffix, string $message ): WP_Error {
		return new WP_Error( 'github_updater_archive_' . $suffix, $message );
	}
}
