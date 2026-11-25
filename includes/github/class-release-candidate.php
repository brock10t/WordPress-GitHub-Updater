<?php
/** Metadata-only newer release result. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\GitHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Not an installable update; archive and compatibility validation remain required. */
final readonly class Release_Candidate {
	/**
	 * @param string        $plugin_basename Exact installed identity.
	 * @param string        $repository_url  Canonical repository identity.
	 * @param int           $release_id      Published release ID.
	 * @param string        $tag_name        Original tag for asset paths.
	 * @param string        $version         Normalized numeric version.
	 * @param Release_Asset $asset           Metadata pointer, not a safe archive.
	 */
	public function __construct(
		public string $plugin_basename,
		public string $repository_url,
		public int $release_id,
		public string $tag_name,
		public string $version,
		public Release_Asset $asset
	) {}
}
