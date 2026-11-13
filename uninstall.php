<?php
/**
 * Uninstall: remove options, notices and pending jobs.
 *
 * Order data imported at reactive.chat is NOT deleted by this - that would
 * need one DELETE per customer. Imported data expires there after 90 days at
 * the latest, and the import can be emptied at once in the reactive.chat
 * project.
 *
 * @package ReactiveChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans up one site.
 */
function reactive_chat_uninstall_site() {
	foreach ( array(
		'reactive_chat_settings',
		'reactive_chat_import_status',
		'reactive_chat_secret_ident',
		'reactive_chat_secret_endpoint',
		'reactive_chat_secret_api_key',
		'reactive_chat_nonces',
		'reactive_chat_diagnostics',
	) as $option ) {
		delete_option( $option );
	}

	// Pending admin notices (one short-lived transient per shop manager).
	$reactive_chat_users = get_users(
		array(
			'capability' => 'manage_woocommerce',
			'fields'     => 'ID',
		)
	);
	foreach ( $reactive_chat_users as $reactive_chat_user_id ) {
		delete_transient( 'reactive_chat_notice_' . (int) $reactive_chat_user_id );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'reactive-chat' );
	}
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $reactive_chat_site_id ) {
		switch_to_blog( $reactive_chat_site_id );
		reactive_chat_uninstall_site();
		restore_current_blog();
	}
} else {
	reactive_chat_uninstall_site();
}
