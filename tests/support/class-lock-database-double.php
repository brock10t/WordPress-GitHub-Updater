<?php
/** Database double shared by isolated registry/lock checks. */
class Lock_Database_Double {
	public string $options  = 'wp_options';
	public string $sitemeta = 'wp_sitemeta';
	public ?string $acquire = '1';
	public ?string $release = '1';
	public bool $locked = false;
	public bool $suppressed = false;
	public array $queries = array();

	public function prepare( $query, $name ) { return $query . '|' . $name; }
	public function suppress_errors( $value ) {
		$previous = $this->suppressed;
		$this->suppressed = $value;
		return $previous;
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'GET_LOCK(' ) ) {
			$this->locked = '1' === $this->acquire;
			return $this->acquire;
		}
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) {
			$this->locked = false;
			return $this->release;
		}
		throw new RuntimeException( 'Unexpected database query in lock test.' );
	}
}
