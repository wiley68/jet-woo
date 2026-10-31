<?php
/** Actual WP Options API/object cache against one in-memory counter, no WP bootstrap. */
error_reporting( E_ALL );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'WPINC', 'wp-includes' );
function is_multisite() { return false; }
function wp_installing() { return false; }
function wp_using_ext_object_cache() { return false; }
function wp_suspend_cache_addition() { return false; }
function maybe_unserialize( $value ) { return $value; }
function esc_sql( $value ) { return $value; }
function _doing_it_wrong( ...$args ) { throw new RuntimeException( 'Unexpected WP cache operation.' ); }
require dirname( __DIR__, 4 ) . '/wp-includes/plugin.php';
require dirname( __DIR__, 4 ) . '/wp-includes/cache.php';
require dirname( __DIR__, 4 ) . '/wp-includes/option.php';
require __DIR__ . '/counter-double.php';
require dirname( __DIR__ ) . '/includes/jet-submission-mail.php';
class JetCacheCounterDatabase {
	use JetCounterDouble;
	public $options = 'offline_options', $counter_store = 'options', $connection = null, $autoload = true;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function query( $query ) { return $this->counter_query( $query[0], $query[1], $handled ); }
	public function suppress_errors( $state = true ) { return false; }
	public function get_results( $sql ) {
		$rows = array( (object) array( 'option_name' => 'other_option', 'option_value' => 'sentinel' ) );
		if ( $this->autoload ) { $rows[] = (object) array( 'option_name' => 'jet_count', 'option_value' => (string) $GLOBALS['options']['jet_count'] ); }
		return $rows;
	}
	public function get_row( $query, $format = null ) {
		if ( is_string( $query ) ) { return $this->counter_row(); }
		return (object) array( 'option_value' => (string) $GLOBALS['options']['jet_count'] );
	}
}
$wpdb = new JetCacheCounterDatabase(); $options = array( 'jet_count' => 40 );
wp_cache_init();
$checks = 0;
function expect_cache( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
foreach ( array( 'alloptions', 'individual', 'negative' ) as $cache ) {
	wp_cache_flush(); $options['jet_count'] = 40;
	$wpdb->autoload = 'alloptions' === $cache;
	wp_cache_set( 'untouched', 'sentinel', 'options' );
	if ( 'alloptions' === $cache ) { wp_cache_set( 'alloptions', array( 'jet_count' => '12' ), 'options' ); }
	elseif ( 'individual' === $cache ) { wp_cache_set( 'alloptions', array(), 'options' ); wp_cache_set( 'jet_count', '12', 'options' ); }
	else { wp_cache_set( 'alloptions', array(), 'options' ); wp_cache_set( 'notoptions', array( 'jet_count' => true ), 'options' ); }
	expect_cache( ( 'negative' === $cache ? false : '12' ) === get_option( 'jet_count' ), 'Stale fixture did not reach the actual Options API.' );
	expect_cache( 41 === jet_next_application_number(), 'Allocation trusted stale WP cache.' );
	expect_cache( '41' === get_option( 'jet_count' ), 'Options API did not observe the new counter.' );
	expect_cache( 'sentinel' === wp_cache_get( 'untouched', 'options' ), 'Unrelated cache was flushed.' );
}
echo "PASS counter cache: $checks assertions; real WordPress get_option and object cache, autoload/individual/negative cache coherence.\n";
