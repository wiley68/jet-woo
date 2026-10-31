<?php
/** Real MySQL 8 counter test. Config is read from STDIN, never printed or persisted.
 * No WP bootstrap. The adapter redirects only jet_count to unique synthetic keys.
 * All mutations are guarded and cleanup deletes only this run's exact keys.
 */
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) ) {
	fwrite( STDERR, "DEFERRED: mysqli and pcntl are required.\n" ); exit( 2 );
}
$config = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $config ) || ! isset( $config['host'], $config['database'], $config['user'], $config['password'], $config['prefix'] )
	|| ! preg_match( '/^[a-zA-Z0-9_]+$/D', $config['prefix'] ) ) {
	fwrite( STDERR, "DEFERRED: supply database configuration privately through STDIN.\n" ); exit( 2 );
}
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
define( 'ARRAY_A', 'ARRAY_A' );
function wp_cache_delete( ...$args ) {} // No access to the store's actual object cache.
require dirname( __DIR__ ) . '/includes/jet-submission-mail.php';
function connect_counter_test( $config ) {
	$host = $config['host']; $port = 3306; $socket = null;
	if ( preg_match( '/^([^:]+):([0-9]+)$/D', $host, $parts ) ) { $host = $parts[1]; $port = (int) $parts[2]; }
	elseif ( preg_match( '/^([^:]+):(\/.*)$/D', $host, $parts ) ) { $host = $parts[1]; $socket = $parts[2]; }
	try { $db = new mysqli( $host, $config['user'], $config['password'], $config['database'], $port, $socket ); $db->set_charset( 'utf8mb4' ); return $db; }
	catch ( Throwable $error ) { throw new RuntimeException( 'MySQL connection unavailable.' ); }
}
function synthetic_key( $key ) {
	if ( ! preg_match( '/^jet_imp04_counter_[a-f0-9]{24}_[a-z0-9]+$/D', $key ) ) { throw new RuntimeException( 'Unsafe synthetic key.' ); }
}
class JetSyntheticMysqlCounter {
	public $options, $db, $reconnect_on_read = false;
	private $key, $config;
	public function __construct( $config, $key ) { synthetic_key( $key ); $this->config = $config; $this->key = $key; $this->options = $config['prefix'] . 'options'; $this->db = connect_counter_test( $config ); }
	public function prepare( $sql, ...$args ) {
		return preg_replace_callback( '/%[sd]/', function ( $match ) use ( &$args ) {
			$value = array_shift( $args ); if ( 'jet_count' === $value ) { $value = $this->key; }
			return '%d' === $match[0] ? (string) (int) $value : "'" . $this->db->real_escape_string( (string) $value ) . "'";
		}, $sql );
	}
	public function query( $sql ) {
		if ( ( 0 !== strpos( $sql, 'INSERT IGNORE INTO ' . $this->options . ' ' ) && 0 !== strpos( $sql, 'UPDATE ' . $this->options . "\n" ) )
			|| false === strpos( $sql, "'" . $this->key . "'" ) || false !== strpos( $sql, "'jet_count'" ) || false !== strpos( $sql, ';' ) ) { throw new RuntimeException( 'Unsafe mutation blocked.' ); }
		try { $this->db->query( $sql ); return $this->db->affected_rows; } catch ( mysqli_sql_exception $error ) { return false; }
	}
	public function get_var( $sql ) { if ( 'SELECT CONNECTION_ID()' !== $sql ) { throw new RuntimeException( 'Unsafe scalar query.' ); } return $this->db->query( $sql )->fetch_row()[0]; }
	public function get_row( $sql, $format ) {
		if ( 'SELECT CONNECTION_ID() AS connection_id, LAST_INSERT_ID() AS number' !== $sql ) { throw new RuntimeException( 'Unsafe result query.' ); }
		if ( $this->reconnect_on_read ) { $this->db->close(); $this->db = connect_counter_test( $this->config ); }
		return $this->db->query( $sql )->fetch_assoc();
	}
}
function check_mysql( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function fixture_row( $db, $table, $key, $value = null ) {
	synthetic_key( $key );
	if ( null !== $value ) {
		$statement = $db->prepare( "INSERT INTO $table (option_name,option_value,autoload) VALUES (?,?,'no')" );
		$statement->bind_param( 'ss', $key, $value ); $statement->execute();
	}
	$statement = $db->prepare( "SELECT option_value,autoload FROM $table WHERE option_name=?" ); $statement->bind_param( 's', $key ); $statement->execute();
	return $statement->get_result()->fetch_assoc();
}
function counter_fingerprint( $db, $table ) { return $db->query( "SELECT SHA2(CONCAT(option_value,':',autoload),256) FROM $table WHERE option_name='jet_count'" )->fetch_row()[0] ?? null; }
function own_fixture( $db, $table, $key, $seed = null ) {
	check_mysql( null === fixture_row( $db, $table, $key ), 'Synthetic key collision; existing row must not be touched.' );
	if ( null !== $seed ) { fixture_row( $db, $table, $key, $seed ); }
	$GLOBALS['keys'][] = $key;
}
$keys = array(); $children = array(); $directory = null; $checks = 0; $results = array();
$prefix = 'jet_imp04_counter_' . bin2hex( random_bytes( 12 ) ) . '_'; $table = $config['prefix'] . 'options';
try {
	$db = connect_counter_test( $config ); $version = $db->server_info;
	check_mysql( 0 === strpos( $version, '8.' ), 'MySQL 8 is required.' );
	$escaped = $db->real_escape_string( $table );
	check_mysql( 'InnoDB' === $db->query( "SELECT ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$escaped'" )->fetch_row()[0], 'Options table must be InnoDB.' );
	check_mysql( 0 === (int) $db->query( "SELECT COUNT(*) FROM information_schema.triggers WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='$escaped'" )->fetch_row()[0], 'Synthetic mutations require a trigger-free options table.' );
	$original = counter_fingerprint( $db, $table );
	$directory = sys_get_temp_dir() . '/jet-counter-imp04-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory, 0700 );
	foreach ( array( 'missing' => null, 'empty' => '', 'existing' => '40' ) as $scenario => $seed ) {
		$key = $prefix . $scenario; own_fixture( $db, $table, $key, $seed );
		$db->close(); $db = null; // No inherited live MySQL connection across fork.
		$workers = 12; $per_worker = 5; $children = array();
		for ( $worker = 0; $worker < $workers; ++$worker ) {
			$pid = pcntl_fork(); if ( $pid < 0 ) { throw new RuntimeException( 'Cannot fork counter worker.' ); }
			if ( 0 === $pid ) {
				try {
					$wpdb = new JetSyntheticMysqlCounter( $config, $key ); $connection = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
					$deadline = microtime( true ) + 15;
					while ( ! file_exists( "$directory/$scenario.start" ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Worker barrier timed out.' ); } usleep( 1000 ); }
					$numbers = array(); for ( $i = 0; $i < $per_worker; ++$i ) { $numbers[] = jet_next_application_number(); }
					file_put_contents( "$directory/$scenario.$worker.json", json_encode( array( 'connection' => $connection, 'numbers' => $numbers ) ) ); exit( 0 );
				} catch ( Throwable $error ) { file_put_contents( "$directory/$scenario.$worker.json", '{"failed":true}' ); exit( 1 ); }
			}
			$children[] = $pid;
		}
		file_put_contents( "$directory/$scenario.start", 'go' );
		foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); check_mysql( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'Counter worker failed.' ); }
		$children = array(); $numbers = $connections = array();
		for ( $worker = 0; $worker < $workers; ++$worker ) {
			$row = json_decode( file_get_contents( "$directory/$scenario.$worker.json" ), true );
			check_mysql( isset( $row['numbers'] ) && count( $row['numbers'] ) === $per_worker, 'Worker result incomplete.' );
			for ( $i = 1; $i < $per_worker; ++$i ) { check_mysql( $row['numbers'][$i] > $row['numbers'][$i - 1], 'Worker sequence was not monotonic.' ); }
			$connections[] = $row['connection']; $numbers = array_merge( $numbers, $row['numbers'] );
		}
		$start = (int) $seed + 1; sort( $numbers, SORT_NUMERIC );
		check_mysql( count( array_unique( $connections ) ) === $workers && $numbers === range( $start, $start + $workers * $per_worker - 1 ), 'Concurrent reservations duplicated or skipped numbers.' );
		$db = connect_counter_test( $config ); $row = fixture_row( $db, $table, $key );
		check_mysql( (int) $row['option_value'] === end( $numbers ) && 'no' === $row['autoload'], 'Final synthetic counter/autoload incorrect.' );
		$results[] = array( 'case' => $scenario, 'connections' => $workers, 'reservations' => count( $numbers ), 'first' => reset( $numbers ), 'last' => end( $numbers ) );
	}
	foreach ( array( '-1', '1.2', 'invalid', "1\n", (string) PHP_INT_MAX, str_repeat( '9', 25 ) ) as $i => $invalid ) {
		$key = $prefix . 'invalid' . $i; own_fixture( $db, $table, $key, $invalid ); $wpdb = new JetSyntheticMysqlCounter( $config, $key );
		$rejected = false; try { jet_next_application_number(); } catch ( RuntimeException $error ) { $rejected = true; }
		check_mysql( $rejected && $invalid === fixture_row( $db, $table, $key )['option_value'], 'Invalid/overflow value changed or was accepted.' ); $wpdb->db->close();
	}
	$key = $prefix . 'boundary'; own_fixture( $db, $table, $key, (string) ( PHP_INT_MAX - 1 ) ); $wpdb = new JetSyntheticMysqlCounter( $config, $key );
	check_mysql( PHP_INT_MAX === jet_next_application_number(), 'Exact PHP integer boundary failed.' ); $wpdb->db->close();
	$key = $prefix . 'reconnect'; own_fixture( $db, $table, $key, '40' ); $wpdb = new JetSyntheticMysqlCounter( $config, $key ); $wpdb->reconnect_on_read = true;
	$rejected = false; try { jet_next_application_number(); } catch ( RuntimeException $error ) { $rejected = true; }
	check_mysql( $rejected && '41' === fixture_row( $db, $table, $key )['option_value'], 'Reconnection accepted a stale result or recycled a reserved number.' ); $wpdb->db->close();
	check_mysql( $original === counter_fingerprint( $db, $table ), 'Actual store counter changed during verification.' );
} catch ( Throwable $error ) {
	$exit_code = $keys ? 1 : 2;
	fwrite( STDERR, ( 2 === $exit_code ? 'DEFERRED' : 'FAIL' ) . ' MySQL counter: ' . ( $error instanceof mysqli_sql_exception ? 'database operation failed' : $error->getMessage() ) . "\n" ); $failed = true;
} finally {
	foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); }
	if ( $keys ) {
		$db = connect_counter_test( $config ); $deleted = 0;
		$statement = $db->prepare( "DELETE FROM $table WHERE option_name=?" );
		foreach ( $keys as $key ) { synthetic_key( $key ); $statement->bind_param( 's', $key ); $statement->execute(); $deleted += $statement->affected_rows; }
		$remaining = 0;
		foreach ( $keys as $key ) { $remaining += null !== fixture_row( $db, $table, $key ) ? 1 : 0; }
		if ( $remaining ) { $failed = true; fwrite( STDERR, "FAIL: synthetic cleanup incomplete.\n" ); }
		$db->close();
	}
	if ( $directory ) { foreach ( glob( "$directory/*" ) as $file ) { unlink( $file ); } rmdir( $directory ); }
}
if ( ! empty( $failed ) ) { exit( $exit_code ?? 1 ); }
echo 'PASS MySQL counter: ' . json_encode( array( 'mysql' => $version, 'assertions' => $checks, 'rounds' => $results, 'synthetic_rows_deleted' => $deleted, 'synthetic_rows_remaining' => $remaining, 'actual_counter_unchanged' => true ) ) . "\n";
