<?php
/** Counter SQL adapter for the existing array/SQLite mail harnesses only.
 * Actual MySQL semantics are verified separately by counter-mysql.php.
 */
trait JetCounterDouble {
	public $counter_failure = '', $counter_last = null, $counter_updates = 0, $counter_reads = 0;
	public function get_var( $sql ) {
		if ( 'SELECT CONNECTION_ID()' !== $sql ) { throw new RuntimeException( 'Unexpected scalar query.' ); }
		return 'connection' === $this->counter_failure ? null : '1';
	}
	public function counter_row() {
		++$this->counter_reads;
		if ( 'read' === $this->counter_failure ) { return null; }
		return array( 'connection_id' => 'reconnect' === $this->counter_failure ? '2' : '1', 'number' => $this->counter_last );
	}
	public function counter_query( $sql, $args, &$handled ) {
		$handled = ( $args[0] ?? '' ) === 'jet_count';
		if ( ! $handled ) { return null; }
		$initialize = 0 === strpos( $sql, 'INSERT IGNORE' );
		if ( ! $initialize && 0 !== strpos( $sql, 'UPDATE ' ) ) { throw new RuntimeException( 'Unexpected counter SQL.' ); }
		if ( $this->counter_failure === ( $initialize ? 'initialize' : 'update' ) ) { return false; }
		$store = $this->counter_store;
		if ( $this->connection ) {
			if ( $initialize ) {
				$statement = $this->connection->prepare( "INSERT OR IGNORE INTO {$this->options} VALUES (?, ?, ?)" );
				$statement->execute( array( 'jet_count', serialize( 0 ), 'no' ) );
				return $statement->rowCount();
			}
			$this->connection->exec( 'BEGIN IMMEDIATE' );
			try {
				$statement = $this->connection->query( "SELECT option_value FROM {$this->options} WHERE option_name = 'jet_count'" );
				$value = $statement->fetchColumn(); $value = false === $value ? null : unserialize( $value );
				$next = $this->counter_next( $value );
				if ( null !== $next ) {
					$statement = $this->connection->prepare( "UPDATE {$this->options} SET option_value = ? WHERE option_name = 'jet_count'" );
					$statement->execute( array( serialize( $next ) ) );
				}
				$this->connection->exec( 'COMMIT' );
			} catch ( Throwable $error ) { $this->connection->exec( 'ROLLBACK' ); throw $error; }
		} else {
			if ( $initialize ) {
				if ( array_key_exists( 'jet_count', $GLOBALS[$store] ) ) { return 0; }
				$GLOBALS[$store]['jet_count'] = 0; return 1;
			}
			$next = $this->counter_next( $GLOBALS[$store]['jet_count'] ?? null );
			if ( null !== $next ) { $GLOBALS[$store]['jet_count'] = $next; }
		}
		if ( null === $next ) { return 0; }
		$this->counter_last = (string) $next; ++$this->counter_updates;
		return 1;
	}
	private function counter_next( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		$value = (string) $value; $maximum = (string) PHP_INT_MAX;
		if ( preg_match( '/[^0-9]/', $value ) || strlen( $value ) > strlen( $maximum )
			|| ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) >= 0 ) ) { return null; }
		return (int) $value + 1;
	}
}
