<?php
/**
 * Log minimale su error_log (visibile con WP_DEBUG_LOG attivo).
 *
 * REGOLA: non loggare mai dati personali (email, nomi), token o secret.
 * Solo ID di form, codici HTTP e messaggi tecnici.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sysdat_MailUp_Logger {

	public static function log( $message, array $context = array() ) {
		$line = '[SYSDAT MailUp] ' . $message;
		if ( $context ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
