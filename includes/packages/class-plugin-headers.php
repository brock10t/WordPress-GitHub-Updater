<?php
/** Parse bounded in-memory plugin headers without executing code. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Core 6.6 header syntax plus explicit ambiguity rejection. */
final class Plugin_Headers {
	public const FIELDS = array(
		'Name' => 'Plugin Name', 'Version' => 'Version', 'UpdateURI' => 'Update URI',
		'RequiresWP' => 'Requires at least', 'RequiresPHP' => 'Requires PHP',
		'RequiresPlugins' => 'Requires Plugins',
		'Network' => 'Network', '_sitewide' => 'Site Wide Only',
	);

	/** Native get_plugin_data() Network/Site Wide Only fallback semantics. */
	public static function network_only( array $headers ): bool {
		$network = $headers['Network'] ?? '';
		if ( is_bool( $network ) ) {
			return $network;
		}
		if ( ! $network && ! empty( $headers['_sitewide'] ) ) {
			$network = $headers['_sitewide'];
		}
		return is_string( $network ) && 'true' === strtolower( $network );
	}

	/** @param string $prefix Raw bounded bytes. @return array<string, string>|WP_Error */
	public static function parse( string $prefix ): array|WP_Error {
		$data    = str_replace( "\r", "\n", substr( $prefix, 0, Zip_Contents::PREFIX_BYTES ) );
		$headers = array();
		foreach ( self::FIELDS as $key => $label ) {
			// Match get_file_data() and _cleanup_header_comment() in WordPress 6.6.
			$count = preg_match_all( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $data, $matches );
			if ( false === $count || $count > 1 ) {
				return new WP_Error( 'github_updater_package_headers', __( 'The packaged plugin has ambiguous or duplicate identity or requirement headers.', 'wordpress-github-updater' ) );
			}
			$value = $count && $matches[1][0] ? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1][0] ) ) : '';
			if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) {
				return new WP_Error( 'github_updater_package_headers', __( 'The packaged plugin contains invalid header characters.', 'wordpress-github-updater' ) );
			}
			$headers[ $key ] = $value;
		}
		return $headers;
	}
}
