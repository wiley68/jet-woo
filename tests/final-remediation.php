<?php
/** F11/F12/F15/thank-you: real plugin and WP hooks, synthetic services only. */
namespace Automattic\WooCommerce\Utilities {
	class FeaturesUtil {
		public static $declarations = array();
		public static function declare_compatibility( $feature, $file, $value ) { self::$declarations[$feature] = array( $file, $value ); }
	}
}
namespace {
	error_reporting( E_ALL );
	set_error_handler( function ( $level, $message, $file, $line ) { throw new \ErrorException( $message, 0, $level, $file, $line ); } );
	// Two dependency includes are empty in-memory shims, not a WordPress installation.
	class JetRemediationInclude {
		public $context; private $position = 0;
		public function stream_open( $path, $mode, $options, &$opened ) {
			if ( ! in_array( $path, array( 'jetoffline://wp-admin/includes/plugin.php', 'jetoffline://wp-admin/includes/upgrade.php' ), true ) ) { throw new \RuntimeException( 'Unexpected include.' ); }
			return true;
		}
		public function stream_read( $length ) { $this->position += $length; return $this->position === $length ? '<?php /* offline dependency */' : ''; }
		public function stream_eof() { return $this->position > 0; }
		public function stream_stat() { return array(); }
		public function stream_set_option( ...$args ) { return false; }
		public function url_stat( ...$args ) { return array(); }
	}
	stream_wrapper_register( 'jetoffline', JetRemediationInclude::class );
	define( 'ABSPATH', 'jetoffline://' );
	define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
	define( 'WPMU_PLUGIN_DIR', '/offline-mu' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'OBJECT', 'OBJECT' );
	function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	require dirname( __DIR__, 4 ) . '/wp-includes/plugin.php';
	$wp_plugin_paths = array();
	$options = array( 'active_plugins' => array( 'woocommerce/woocommerce.php' ), 'jet_count' => 42, 'jet_email' => 'bank@example.test',
		'woocommerce_jetpayment_settings' => array( 'instructions' => 'Standard instructions' ), 'woocommerce_jetpaymentcard_settings' => array( 'instructions' => 'Card instructions' ) );
	$writes = array();
	function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
	function update_option( $key, $value ) { $GLOBALS['writes'][] = $key; $GLOBALS['options'][$key] = $value; return true; }
	function is_plugin_active_for_network( $plugin ) { return false; }
	function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
	function plugins_url( ...$args ) { return '/synthetic/'; }
	function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
	function is_cart() { return false; }
	function wc_get_order_statuses() { return array(); }
	function wpautop( $text ) { return '<p>' . $text . '</p>'; }
	function wptexturize( $text ) { return $text; }
	function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function check_admin_referer( ...$args ) { return true; }
	function wp_mail( ...$args ) { throw new \RuntimeException( 'Unexpected mail.' ); }
	class WC_Payment_Gateway {
		public $id, $domain, $icon, $has_fields, $method_title, $method_description, $title, $description, $form_fields, $settings;
		public function init_settings() { $this->settings = get_option( 'woocommerce_' . $this->id . '_settings', array() ); }
		public function get_option( $key, $default = '' ) { return $this->settings[$key] ?? $default; }
	}
	class JetLifecycleDatabase {
		public $prefix = 'offline_', $tables = array(), $creates = 0;
		public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function get_var( $query ) { return isset( $this->tables[$query[1][0]] ) ? $query[1][0] : null; }
		public function get_results( $sql, $format ) { return $this->tables['offline_jet_kop'] ?? array(); }
		public function query( $sql ) { throw new \RuntimeException( 'Unexpected destructive database operation.' ); }
	}
	$wpdb = new JetLifecycleDatabase();
	function dbDelta( $sql ) {
		if ( 0 !== strpos( $sql, 'CREATE TABLE offline_jet_kop ' ) ) { throw new \RuntimeException( 'Unexpected schema.' ); }
		$GLOBALS['wpdb']->tables['offline_jet_kop'] = array(); ++$GLOBALS['wpdb']->creates;
	}
	$checks = 0;
	function verify( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new \RuntimeException( $message ); } }
	require dirname( __DIR__ ) . '/jetcredit.php';
	verify( false === has_action( 'deactivate_jetcredit/jetcredit.php' ), 'Destructive deactivation still registered.' );
	verify( ! function_exists( 'jet_remove_tables' ), 'Unused destructive helper retained.' );
	do_action( 'activate_jetcredit/jetcredit.php' );
	verify( 1 === $wpdb->creates && isset( $wpdb->tables['offline_jet_kop'] ), 'Activation did not create the missing table.' );
	$wpdb->tables['offline_jet_kop'] = array( (object) array( 'id' => 7, 'jet_product_id' => '*', 'jet_product_percent' => 0 ) );
	$before = serialize( array( $wpdb->tables, $options ) );
	do_action( 'deactivate_jetcredit/jetcredit.php' ); do_action( 'activate_jetcredit/jetcredit.php' );
	verify( $before === serialize( array( $wpdb->tables, $options ) ) && 1 === $wpdb->creates, 'Reactivation lost promotion data or settings.' );
	verify( 10 === has_action( 'before_woocommerce_init', 'jet_declare_cart_checkout_blocks_compatibility' ), 'Compatibility hook moved.' );
	do_action( 'before_woocommerce_init' );
	foreach ( array( 'cart_checkout_blocks', 'custom_order_tables' ) as $feature ) {
		verify( array( dirname( __DIR__ ) . '/jetcredit.php', true ) === \Automattic\WooCommerce\Utilities\FeaturesUtil::$declarations[$feature], 'Incorrect compatibility declaration.' );
	}
	do_action( 'plugins_loaded' );
	foreach ( array( 'Jet_Payment_Gateway' => 'Standard instructions', 'Jet_Card_Payment_Gateway' => 'Card instructions' ) as $class => $instructions ) {
		$first = new $class(); $second = new $class(); $third = new $class();
		$hook = 'woocommerce_thankyou_' . $first->id;
		ob_start(); do_action( $hook, 314 ); $output = ob_get_clean();
		verify( '<p>' . $instructions . '</p>' === $output, 'Separate gateway instances duplicated/mixed instructions.' );
		$first->instructions = '';
		ob_start(); do_action( $hook, 314 ); $output = ob_get_clean();
		verify( '' === $output, 'Empty instructions produced output.' );
	}
	foreach ( array( 'dskapipayment', 'borica', 'cod' ) as $foreign ) {
		ob_start(); do_action( 'woocommerce_thankyou_' . $foreign, 314 ); $output = ob_get_clean();
		verify( '' === $output && false === has_action( 'woocommerce_thankyou_' . $foreign ), 'Foreign thank-you hook contaminated.' );
	}
	// Execute the actual administrative PHP preamble only; no page rendering/bootstrap.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/jet_import_admin.php' );
	$preamble = substr( $source, 5, strpos( $source, '?>' ) - 5 );
	$_POST = array( 'jet_hidden' => 'Y', 'jet_count' => '40' );
	ob_start(); eval( $preamble ); ob_end_clean();
	verify( 42 === $options['jet_count'] && ! in_array( 'jet_count', $writes, true ), 'Old admin POST rewound the counter.' );
	verify( (bool) preg_match( '/name="jet_count"\s+readonly/', $source ), 'Read-only counter display removed.' );
	verify( false !== strpos( $source, 'Получатели на банковите заявления. Въведете валидни адреси, разделени със запетая. При празен списък заявката се регистрира в магазина за ръчна обработка, без изпращане до банката. При невалиден адрес заявката не се приема. Няма автоматичен резервен получател.' ), 'Approved help text absent.' );
	echo "PASS final remediation: $checks assertions; lifecycle retention, HPOS/Blocks declarations, real WP hook identity, isolated instructions and stale admin POST.\n";
}
