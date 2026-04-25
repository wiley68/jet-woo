<?php
function jet_admin_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	add_options_page(
		esc_html('ПБ Лични Финанси - Настройки на модула'),
		esc_html('ПБ Лични Финанси настойки'),
		'manage_options',
		'jet-options',
		'jet_admin_options'
	);
}
add_action( 'admin_menu', 'jet_admin_actions' );

function jet_admin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Нямате достатъчно права за достъп до тази страница.' ) );
	}

	require_once JET_INCLUDES_DIR . '/class-jet-admin-toggle.php';

	$file_path = plugin_dir_path( __FILE__ ) . 'jet_import_admin.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	} else {
		echo '<div class="error"><p>' . esc_html( 'Файлът jet_import_admin.php не беше намерен!' ) . '</p></div>';
	}
}
