<?php
/**
 * Salvataggio dei token OAuth (access + refresh).
 *
 * Salvati in una option NON autoload. Il refresh token e' sensibile:
 * TODO (fase 2, opzionale): cifrarlo a riposo con libsodium usando una
 * chiave definita in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sysdat_MailUp_Tokens {

	/**
	 * Legge sempre il valore fresco dal DB: se un'altra richiesta ha appena
	 * fatto il refresh, qui non vogliamo il token vecchio dalla cache.
	 */
	public static function get() {
		wp_cache_delete( Sysdat_MailUp_Config::OPTION_TOKENS, 'options' );
		$tokens = get_option( Sysdat_MailUp_Config::OPTION_TOKENS, array() );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Salva la risposta dell'endpoint Token. Se la risposta non contiene un
	 * nuovo refresh token, mantiene quello precedente.
	 */
	public static function save( array $response ) {
		$current = self::get();

		$tokens = array(
			'access_token'  => (string) ( isset( $response['access_token'] ) ? $response['access_token'] : '' ),
			'refresh_token' => (string) ( isset( $response['refresh_token'] ) ? $response['refresh_token'] : ( isset( $current['refresh_token'] ) ? $current['refresh_token'] : '' ) ),
			'saved_at'      => time(),
			'invalid'       => false,
		);

		update_option( Sysdat_MailUp_Config::OPTION_TOKENS, $tokens, false );
	}

	public static function clear() {
		delete_option( Sysdat_MailUp_Config::OPTION_TOKENS );
	}

	/**
	 * Il refresh token e' stato rifiutato: serve un nuovo login da admin.
	 */
	public static function mark_invalid() {
		$tokens = self::get();
		if ( $tokens ) {
			$tokens['invalid'] = true;
			update_option( Sysdat_MailUp_Config::OPTION_TOKENS, $tokens, false );
		}
	}

	public static function is_invalid() {
		$tokens = self::get();

		return ! empty( $tokens['invalid'] );
	}

	public static function is_connected() {
		$tokens = self::get();

		return ! empty( $tokens['access_token'] ) && ! empty( $tokens['refresh_token'] ) && empty( $tokens['invalid'] );
	}
}
