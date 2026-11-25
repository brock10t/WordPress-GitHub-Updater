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
		$deadline = hrtime( true ) / 1000000000 + self::MAX_SECONDS;
		return $this->inspector->with_entries( $path, $registration, function ( $stream, array $entries ) use ( $deadline ) {
			$total = 0;
			foreach ( $entries as $entry ) {
				$result = $this->entry( $stream, $entry, $total, $deadline );
				if ( $result instanceof WP_Error ) {
					return $result;
				}
			}
			return true;
		} );
	}

	/** @param resource $stream Inspected read-only stream. @return true|WP_Error */
	private function entry( $stream, array $entry, int &$total, float $deadline ): true|WP_Error {
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
			hash_update( $crc, $output );
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
