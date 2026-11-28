<?php
/** Bound adapter around native single-item installation. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Updates;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loaded only after native Plugin_Upgrader is available. */
final class Session_Upgrader extends \Plugin_Upgrader {
	public function __construct( $skin, private readonly Native_Session $session ) { parent::__construct( $skin ); }
	public function download_package( $package, $check_signatures = false, $hook_extra = array() ) {
		$valid = $this->session->download_args( $package, $hook_extra, $this );
		return $valid instanceof \WP_Error ? $valid : parent::download_package( $package, $check_signatures, $hook_extra );
	}
	public function unpack_package( $package, $delete_package = true ) { return $this->session->unpack( $package, $this ); }
	public function install_package( $args = array() ) {
		$valid = $this->session->install_args( $args, $this );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		$result = parent::install_package( $args );
		$this->session->installed( $result );
		return $result;
	}
}
