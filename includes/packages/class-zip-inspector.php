<?php
/** Strict bounded ZIP record inspection without extraction. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use TenT\GitHubUpdater\Registry\Registration;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Classic single-disk ZIP subset; no extension, shell or decompressor required. */
final class Zip_Inspector {
	public const MAX_ARCHIVE_BYTES = 52428800;

	/**
	 * Inspect a caller-owned, stable local temporary ZIP, then validate its manifest.
	 * No extraction or CRC/decompression verification. The caller must retain and
	 * protect these same bytes until subsequent validation; paths are not approval
	 * tokens. Return raw manifest only after every record and policy check passes.
	 *
	 * @param string       $path         Absolute local regular file, not a wrapper.
	 * @param Registration $registration Expected plugin identity.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function inspect( string $path, Registration $registration ): array|WP_Error {
		return $this->with_entries( $path, $registration, static function ( $stream, array $entries ): array {
			foreach ( $entries as &$entry ) {
				unset( $entry['data_offset'], $entry['crc32'] );
			}
			unset( $entry );
			return $entries;
		} );
	}

	/**
	 * Consume inspected payload records on this same read-only file handle.
	 * Adds data_offset and hexadecimal crc32 to each manifest entry. No callback
	 * runs until all records and manifest policy pass. The callback must neither
	 * retain nor close the handle; ownership remains here, closed in finally.
	 * No result is a reusable package-approval token.
	 *
	 * @param string       $path         Caller-owned stable local archive.
	 * @param Registration $registration Expected identity.
	 * @param callable      $consume      Receives open stream and validated records.
	 * @return mixed Callback result or WP_Error before consumption.
	 */
	public function with_entries( string $path, Registration $registration, callable $consume ): mixed {
		if ( PHP_INT_SIZE < 8 ) {
			return $this->error( 'unsupported', __( 'ZIP inspection requires a 64-bit PHP runtime.', 'wordpress-github-updater' ) );
		}
		if ( ! str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $this->error( 'file', __( 'The package must be a readable local regular temporary file.', 'wordpress-github-updater' ) );
		}
		$stream = @fopen( $path, 'rb' );
		if ( false === $stream ) {
			return $this->error( 'file', __( 'The package temporary file could not be opened.', 'wordpress-github-updater' ) );
		}
		try {
			$stat = fstat( $stream );
			if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] < 22 || $stat['size'] > self::MAX_ARCHIVE_BYTES ) {
				return $this->error( 'limits', __( 'The ZIP file is empty, invalid or exceeds the supported archive size.', 'wordpress-github-updater' ) );
			}
			$result = $this->records( $stream, $stat['size'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$check = Archive_Manifest::check( $result, $registration );
			return $check instanceof WP_Error ? $check : $consume( $stream, $result );
		} finally {
			fclose( $stream );
		}
	}

	/** @param resource $stream Open file. @param int $length Bounded file size. @return array|WP_Error */
	private function records( $stream, int $length ): array|WP_Error {
		$tail_start = max( 0, $length - 65557 );
		$tail       = $this->read( $stream, $tail_start, $length - $tail_start );
		if ( false === $tail ) {
			return $this->invalid();
		}
		$ends = array();
		$at   = 0;
		while ( false !== ( $at = strpos( $tail, "PK\x05\x06", $at ) ) ) {
			if ( strlen( $tail ) - $at >= 22 ) {
				$end = unpack( 'vdisk/vcentral_disk/vdisk_count/vcount/Vsize/Voffset/vcomment', substr( $tail, $at + 4, 18 ) );
				if ( $at + 22 + $end['comment'] === strlen( $tail ) ) {
					$end['position'] = $tail_start + $at;
					$ends[] = $end;
				}
			}
			++$at;
		}
		if ( 1 !== count( $ends ) ) {
			return $this->invalid();
		}
		$end = $ends[0];
		if ( 0 !== $end['disk'] || 0 !== $end['central_disk'] || $end['disk_count'] !== $end['count'] || 65535 === $end['count'] || 0xffffffff === $end['size'] || 0xffffffff === $end['offset'] ) {
			return $this->unsupported();
		}
		if ( $end['count'] < 1 || $end['count'] > Archive_Manifest::MAX_ENTRIES || $end['offset'] + $end['size'] !== $end['position'] ) {
			return $this->invalid();
		}
		$cursor  = $end['offset'];
		$records = array();
		for ( $i = 0; $i < $end['count']; ++$i ) {
			$raw = $this->read( $stream, $cursor, 46 );
			if ( false === $raw || "PK\x01\x02" !== substr( $raw, 0, 4 ) || $cursor + 46 > $end['position'] ) {
				return $this->invalid();
			}
			$h = unpack( 'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr( $raw, 4 ) );
			$next = $cursor + 46 + $h['name'] + $h['extra'] + $h['comment'];
			if ( $next > $end['position'] || $h['name'] < 1 || $h['name'] > Archive_Manifest::MAX_PATH_BYTES || $h['offset'] >= $end['offset'] ) {
				return $this->invalid();
			}
			if ( $h['needed'] > 20 || 0 !== $h['disk'] || 0xffffffff === $h['size'] || 0xffffffff === $h['compressed'] || 0xffffffff === $h['offset'] || ( $h['flags'] & ~0x080e ) || ! in_array( $h['method'], array( 0, 8 ), true ) || ( 0 === $h['method'] && ( $h['flags'] & 6 ) ) ) {
				return $this->unsupported();
			}
			$name  = $this->read( $stream, $cursor + 46, $h['name'] );
			$extra = $this->read( $stream, $cursor + 46 + $h['name'], $h['extra'] );
			if ( false === $name || false === $extra || ! $this->extras( $extra ) ) {
				return $this->unsupported();
			}
			$type = $this->entry_type( $h['made'] >> 8, $h['external'], $name );
			if ( null === $type ) {
				return $this->unsupported();
			}
			$h['raw_name'] = $name;
			$h['type']     = $type;
			$records[]     = $h;
			$cursor        = $next;
		}
		if ( $cursor !== $end['position'] ) {
			return $this->invalid();
		}
		usort( $records, static fn( $a, $b ) => $a['offset'] <=> $b['offset'] );
		$cursor   = 0;
		$manifest = array();
		foreach ( $records as $h ) {
			// Contiguity rejects overlaps, hidden local entries, stubs and padding.
			if ( $h['offset'] !== $cursor ) {
				return $this->invalid();
			}
			$raw = $this->read( $stream, $cursor, 30 );
			if ( false === $raw || "PK\x03\x04" !== substr( $raw, 0, 4 ) ) {
				return $this->invalid();
			}
			$local = unpack( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr( $raw, 4 ) );
			foreach ( array( 'needed', 'flags', 'method', 'time', 'date', 'name' ) as $field ) {
				if ( $local[ $field ] !== $h[ $field ] ) {
					return $this->invalid();
				}
			}
			$start = $cursor + 30 + $local['name'] + $local['extra'];
			if ( $start + $h['compressed'] > $end['offset'] || $this->read( $stream, $cursor + 30, $local['name'] ) !== $h['raw_name'] ) {
				return $this->invalid();
			}
			$extra = $this->read( $stream, $cursor + 30 + $local['name'], $local['extra'] );
			if ( false === $extra || ! $this->extras( $extra ) ) {
				return $this->unsupported();
			}
			$descriptor = (bool) ( $h['flags'] & 8 );
			foreach ( array( 'crc', 'compressed', 'size' ) as $field ) {
				if ( $local[ $field ] !== $h[ $field ] && ( ! $descriptor || 0 !== $local[ $field ] ) ) {
					return $this->invalid();
				}
			}
			$cursor = $start + $h['compressed'];
			if ( $descriptor ) {
				$raw = $this->read( $stream, $cursor, 4 );
				$signed = "PK\x07\x08" === $raw;
				// Signature/CRC ambiguity is intentionally unsupported.
				if ( $signed && 0x08074b50 === $h['crc'] ) {
					return $this->unsupported();
				}
				$raw = $this->read( $stream, $cursor + ( $signed ? 4 : 0 ), 12 );
				if ( false === $raw || array_values( unpack( 'Vcrc/Vcompressed/Vsize', $raw ) ) !== array( $h['crc'], $h['compressed'], $h['size'] ) ) {
					return $this->invalid();
				}
				$cursor += $signed ? 16 : 12;
			}
			$manifest[] = array( 'name' => $h['raw_name'], 'type' => $h['type'], 'size' => $h['size'], 'compressed_size' => $h['compressed'], 'encrypted' => false, 'method' => $h['method'], 'data_offset' => $start, 'crc32' => sprintf( '%08x', $h['crc'] ) );
		}
		return $cursor === $end['offset'] ? $manifest : $this->invalid();
	}

	/** Accept only inert timestamp/Unix ownership extras; reject alternate names/ZIP64. */
	private function extras( string $data ): bool {
		$seen = array();
		while ( '' !== $data ) {
			if ( strlen( $data ) < 4 ) {
				return false;
			}
			$h = unpack( 'vid/vsize', substr( $data, 0, 4 ) );
			if ( ! in_array( $h['id'], array( 0x5455, 0x7875, 0x000a ), true ) || isset( $seen[ $h['id'] ] ) || $h['size'] > strlen( $data ) - 4 ) {
				return false;
			}
			$seen[ $h['id'] ] = true;
			$data = substr( $data, 4 + $h['size'] );
		}
		return true;
	}

	/** @return string|null Reject unknown creators and special/ambiguous Unix types. */
	private function entry_type( int $os, int $attributes, string $name ): ?string {
		$directory = str_ends_with( $name, '/' );
		if ( ! in_array( $os, array( 0, 3 ), true ) || ( $attributes & 0xffc8 ) || ( ( $attributes >> 16 ) & 07000 ) || (bool) ( $attributes & 0x10 ) !== $directory ) {
			return null;
		}
		$mode = ( $attributes >> 16 ) & 0170000;
		if ( ( 3 === $os && $mode !== ( $directory ? 0040000 : 0100000 ) ) || ( 0 === $os && 0 !== ( $attributes >> 16 ) ) ) {
			return null;
		}
		return $directory ? 'directory' : 'file';
	}

	/** @param resource $stream File handle. @return string|false Exact bounded read. */
	private function read( $stream, int $offset, int $bytes ): string|false {
		if ( $offset < 0 || $bytes < 0 || 0 !== fseek( $stream, $offset ) ) {
			return false;
		}
		$data = '';
		while ( strlen( $data ) < $bytes ) {
			$part = fread( $stream, $bytes - strlen( $data ) );
			if ( false === $part || '' === $part ) {
				return false;
			}
			$data .= $part;
		}
		return $data;
	}

	/** @return WP_Error Fixed diagnostic without archive paths or parser detail. */
	private function invalid(): WP_Error {
		return $this->error( 'invalid', __( 'The ZIP records are inconsistent, truncated or ambiguous.', 'wordpress-github-updater' ) );
	}
	/** @return WP_Error Unsupported formats never fall back to a permissive reader. */
	private function unsupported(): WP_Error {
		return $this->error( 'unsupported', __( 'The ZIP uses unsupported records, attributes, encryption or compression.', 'wordpress-github-updater' ) );
	}
	/** @param string $code Fixed suffix. @param string $message Fixed text. @return WP_Error */
	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( 'github_updater_zip_' . $code, $message );
	}
}
