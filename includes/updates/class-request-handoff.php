<?php
/**
 * Retain synchronous resource ownership across native core hook boundaries.
 *
 * @package TenTGitHubUpdater
 */
namespace TenT\GitHubUpdater\Updates;
use Fiber;
use WP_Error;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicitly resumed in hooks/shutdown, never in a destructor. */
final class Request_Handoff {
	// The fiber suspends inside existing try/finally scopes, preserving their ownership.
	// Hooks explicitly resume it; destructors cannot safely resume fibers on PHP 8.2.
	private ?Fiber $fiber = null;
	private bool $item = false;
	public function __construct( private readonly Validated_Handoff $handoff = new Validated_Handoff() ) {}
	public function begin( $record, $candidate ): mixed {
		if ( $this->item ) { return $this->error(); }
		if ( null === $this->fiber ) {
			$this->fiber = new Fiber( fn() => $this->handoff->in_request( function () {
				$command = Fiber::suspend( true );
				while ( 'close' !== $command['op'] ) {
					if ( 'owns' === $command['op'] ) { $command = Fiber::suspend( $this->handoff->owns_scope() ); continue; }
					try {
						$result = $this->handoff->run( $command['record'], $command['candidate'], function ( $path, $recheck, $owns ) {
							$command = Fiber::suspend( array( 'path' => $path ) );
							while ( 'finish' !== $command['op'] ) {
								try { $reply = 'owns' === $command['op'] ? $owns() : $recheck( $command['working'] ?? null ); }
								catch ( \Throwable $exception ) { $reply = $this->error(); }
								$command = Fiber::suspend( $reply );
							}
							return $command['result'];
						} );
					} catch ( \Throwable $exception ) { $result = $this->error(); }
					$command = Fiber::suspend( array( 'finished' => $result ) );
				}
				return true;
			} ) );
			$started = $this->fiber->start();
			if ( true !== $started ) { $result = $this->fiber->isTerminated() ? $this->fiber->getReturn() : $this->error(); $this->fiber = null; return $result; }
		}
		$result = $this->fiber->resume( array( 'op' => 'begin', 'record' => $record, 'candidate' => $candidate ) );
		$this->item = is_array( $result ) && isset( $result['path'] );
		return $this->item ? $result['path'] : ( $result['finished'] ?? $this->error() );
	}
	public function check( ?string $working = null ): mixed {
		return $this->item ? $this->fiber->resume( array( 'op' => 'check', 'working' => $working ) ) : $this->error();
	}
	public function owns(): bool {
		return null !== $this->fiber && $this->fiber->isSuspended() && true === $this->fiber->resume( array( 'op' => 'owns' ) );
	}
	public function finish( mixed $result ): mixed {
		if ( ! $this->item ) { return $result; }
		$this->item = false;
		$reply = $this->fiber->resume( array( 'op' => 'finish', 'result' => $result ) );
		return $reply['finished'] ?? $this->error();
	}
	public function close(): mixed {
		if ( null === $this->fiber ) { return true; }
		if ( $this->item ) { $this->finish( $this->error() ); }
		try { $this->fiber->resume( array( 'op' => 'close' ) ); return $this->fiber->getReturn(); }
		finally { $this->fiber = null; }
	}
	private function error(): WP_Error { return new WP_Error( 'github_updater_request_handoff', __( 'The native update resource scope is incomplete or unavailable. No package approval was issued.', 'wordpress-github-updater' ) ); }
}
