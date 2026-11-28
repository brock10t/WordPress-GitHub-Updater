<?php
/** Verify actual ZIP payloads without extracting or executing them. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read/discard bounded payload chunks; CRC is integrity, never authenticity. */
final class Zip_Contents {
	public const STORED_CHUNK_BYTES  = 65536;
	public const DEFLATE_CHUNK_BYTES = 256;
	public const MAX_SECONDS         = 30;
	public const PREFIX_BYTES        = 8192;
	public const MAX_PHP_PREFIXES    = 64;

	/** @param Zip_Inspector $inspector Raw record and manifest gate. */
	public function __construct( private readonly Zip_Inspector $inspector = new Zip_Inspector() ) {}

	/**
	 * Check records, manifest, complete payload streams, expanded sizes and CRCs.
	 * No extraction, output callbacks, full-file buffers or disk writes. The caller
	 * protects the same temporary bytes across later header and replacement gates.
	 *
	 * @param string       $path         Caller-owned stable local ZIP.
	 * @param Registration $registration Expected plugin directory/main basename.
	 * @return true|WP_Error Integrity/resource check only, not installability.
	 */
	public function check( string $path, Registration $registration ): true|WP_Error {
		return $this->validate( $path, $registration, false );
	}

	/**
	 * Return bounded root PHP prefixes only AFTER every payload passes integrity.
	 * Hidden files are included conservatively; nested library files are not
	 * native top-level plugin entry points. No full payload is retained.
	 *
	 * @param string $path Local protected ZIP. @param Registration $registration Identity.
	 * @return array<string, string>|WP_Error Raw unescaped prefixes, never executable.
	 */
	public function plugin_prefixes( string $path, Registration $registration ): array|WP_Error {
		return $this->validate( $path, $registration, true );
	}

	/**
	 * Hash each validated payload without buffering it or exposing its contents.
	 * @param string $path Protected ZIP. @param Registration $registration Identity.
	 * @return array<string, array{type:string,size:int,sha256:string}>|WP_Error
	 */
	public function fingerprints( string $path, Registration $registration ): array|WP_Error {
		return $this->validate( $path, $registration, false, true );
	}

	/** @return true|array<string, string>|WP_Error */
	private function validate( string $path, Registration $registration, bool $capture, bool $fingerprints = false ): true|array|WP_Error {
		$deadline = hrtime( true ) / 1000000000 + self::MAX_SECONDS;
		return $this->inspector->with_entries( $path, $registration, function ( $stream, array $entries ) use ( $deadline, $capture, $fingerprints ) {
			$total    = 0;
			$prefixes = array();
			$digests  = array();
			foreach ( $entries as $entry ) {
				$collect = $capture && 'file' === $entry['type'] && 1 === substr_count( $entry['name'], '/' ) && str_ends_with( $entry['name'], '.php' );
				if ( $collect && count( $prefixes ) >= self::MAX_PHP_PREFIXES ) {
					return $this->error( 'prefix_limit', __( 'The package has too many top-level PHP files for bounded header inspection.', 'wordpress-github-updater' ) );
				}
				$prefix = $collect ? '' : null;
				$digest = $fingerprints ? '' : null;
				$result = $this->entry( $stream, $entry, $total, $deadline, $prefix, $digest );
				if ( $result instanceof WP_Error ) {
					return $result;
				}
				if ( $fingerprints ) {
					$digests[ $entry['name'] ] = array( 'type' => $entry['type'], 'size' => $entry['size'], 'sha256' => $digest );
				}
				if ( $collect ) {
					$prefixes[ $entry['name'] ] = $prefix;
				}
			}
			return $fingerprints ? $digests : ( $capture ? $prefixes : true );
		} );
	}

	/** @param resource $stream Inspected read-only stream. @return true|WP_Error */
	private function entry( $stream, array $entry, int &$total, float $deadline, ?string &$prefix, ?string &$digest ): true|WP_Error {
		$deflated = 8 === $entry['method'];
		if ( $deflated && ( ! function_exists( 'inflate_init' ) || ! function_exists( 'inflate_add' ) || ! function_exists( 'inflate_get_status' ) || ! function_exists( 'inflate_get_read_len' ) ) ) {
			return $this->error( 'unsupported', __( 'This PHP runtime cannot safely validate deflated ZIP contents. The zlib streaming functions are required.', 'wordpress-github-updater' ) );
		}
		if ( 0 !== fseek( $stream, $entry['data_offset'] ) ) {
			return $this->invalid();
		}
		$inflate = $deflated ? inflate_init( ZLIB_ENCODING_RAW ) : null;
		if ( false === $inflate ) {
			return $this->invalid();
		}
		$crc       = hash_init( 'crc32b' );
		$sha       = null !== $digest ? hash_init( 'sha256' ) : null;
		$remaining = $entry['compressed_size'];
		$expanded  = 0;
		$ended     = false;
		while ( $remaining > 0 ) {
			if ( hrtime( true ) / 1000000000 > $deadline ) {
				return $this->error( 'timeout', __( 'ZIP content validation exceeded its time budget.', 'wordpress-github-updater' ) );
			}
			$chunk = fread( $stream, min( $remaining, $deflated ? self::DEFLATE_CHUNK_BYTES : self::STORED_CHUNK_BYTES ) );
			if ( false === $chunk || '' === $chunk ) {
				return $this->invalid();
			}
			$remaining -= strlen( $chunk );
			if ( $deflated ) {
				// PHP has no output-length argument. Small input chunks bound transient
				// expansion per call; never pass an entire compressed file to zlib.
				$output = $this->inflate( $inflate, $chunk, 0 === $remaining ? ZLIB_FINISH : ZLIB_SYNC_FLUSH );
				if ( false === $output ) {
					return $this->invalid();
				}
				$status = inflate_get_status( $inflate );
				if ( ! in_array( $status, array( ZLIB_OK, ZLIB_STREAM_END ), true ) ) {
					return $this->invalid();
				}
				$ended = ZLIB_STREAM_END === $status;
				if ( $ended && ( $remaining > 0 || inflate_get_read_len( $inflate ) !== $entry['compressed_size'] ) ) {
					return $this->invalid();
				}
			} else {
				$output = $chunk;
			}
			$bytes = strlen( $output );
			if ( $bytes > Archive_Manifest::MAX_FILE_BYTES - $expanded || $bytes > Archive_Manifest::MAX_EXPANDED_BYTES - $total ) {
				return $this->error( 'limits', __( 'The actual ZIP contents exceed the supported expanded-size limits.', 'wordpress-github-updater' ) );
			}
			if ( $bytes > $entry['size'] - $expanded ) {
				return $this->error( 'size', __( 'The expanded ZIP contents do not match their declared sizes.', 'wordpress-github-updater' ) );
			}
			$expanded += $bytes;
			$total    += $bytes;
			if ( null !== $prefix && strlen( $prefix ) < self::PREFIX_BYTES ) {
				$prefix .= substr( $output, 0, self::PREFIX_BYTES - strlen( $prefix ) );
			}
			hash_update( $crc, $output );
			if ( null !== $sha ) {
				hash_update( $sha, $output );
			}
			unset( $output, $chunk );
		}
		if ( hrtime( true ) / 1000000000 > $deadline ) {
			return $this->error( 'timeout', __( 'ZIP content validation exceeded its time budget.', 'wordpress-github-updater' ) );
		}
		if ( $deflated && ! $ended ) {
			return $this->invalid();
		}
		if ( $expanded !== $entry['size'] ) {
			return $this->error( 'size', __( 'The expanded ZIP contents do not match their declared sizes.', 'wordpress-github-updater' ) );
		}
		if ( hash_final( $crc ) !== $entry['crc32'] ) {
			return $this->error( 'crc', __( 'The ZIP contents failed their CRC integrity check.', 'wordpress-github-updater' ) );
		}
		if ( null !== $sha ) {
			$digest = hash_final( $sha );
		}
		return true;
	}

	/** Convert zlib's untrusted-data warnings into a fixed diagnostic, restoring handlers. */
	private function inflate( mixed $context, string $chunk, int $mode ): string|false {
		set_error_handler( static fn() => true );
		try {
			return inflate_add( $context, $chunk, $mode );
		} finally {
			restore_error_handler();
		}
	}

	/** @return WP_Error No untrusted paths or zlib messages. */
	private function invalid(): WP_Error {
		return $this->error( 'invalid', __( 'The ZIP payload is corrupt, truncated or has an unexpected stream boundary.', 'wordpress-github-updater' ) );
	}
	/** @param string $code Fixed suffix. @param string $message Fixed text. @return WP_Error */
	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( 'github_updater_zip_content_' . $code, $message );
	}
}
