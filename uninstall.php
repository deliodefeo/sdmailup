<?php
/**
 * Pulizia alla disinstallazione: rimuove impostazioni e token.
 * (Le credenziali in wp-config.php restano: vanno tolte a mano.)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sysdat_mailup_settings' );
delete_option( 'sysdat_mailup_tokens' );
delete_transient( 'sysdat_mailup_reference' );
delete_transient( 'sysdat_mailup_refresh_lock' );
