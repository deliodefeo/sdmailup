<?php
/**
 * Configurazione centrale: costanti, URL, accesso alle impostazioni.
 * Unico punto in cui si toccano URL di MailUp e credenziali.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sysdat_MailUp_Config {

	const OPTION_SETTINGS = 'sysdat_mailup_settings';
	const OPTION_TOKENS   = 'sysdat_mailup_tokens';
	const PAGE_SLUG       = 'sysdat-mailup';

	// Endpoint MailUp (gli stessi usati dal plugin ufficiale 1.2.7).
	const AUTH_BASE    = 'https://services.mailup.com/Authorization/OAuth/';
	const CONSOLE_BASE = 'https://services.mailup.com/API/v1.1/Rest/ConsoleService.svc/Console/';

	/**
	 * Client ID: definito in wp-config.php (SYSDAT_MAILUP_CLIENT_ID).
	 */
	public static function client_id() {
		return defined( 'SYSDAT_MAILUP_CLIENT_ID' ) ? (string) SYSDAT_MAILUP_CLIENT_ID : '';
	}

	/**
	 * Client Secret: definito in wp-config.php (SYSDAT_MAILUP_CLIENT_SECRET).
	 * Non va mai salvato nel database ne' scritto nei log.
	 */
	public static function client_secret() {
		return defined( 'SYSDAT_MAILUP_CLIENT_SECRET' ) ? (string) SYSDAT_MAILUP_CLIENT_SECRET : '';
	}

	/**
	 * Username dell'utente MailUp dedicato (es. m1234): definito in wp-config.php
	 * (SYSDAT_MAILUP_USERNAME).
	 */
	public static function username() {
		return defined( 'SYSDAT_MAILUP_USERNAME' ) ? (string) SYSDAT_MAILUP_USERNAME : '';
	}

	/**
	 * Password dell'utente MailUp dedicato: definita in wp-config.php
	 * (SYSDAT_MAILUP_PASSWORD). Non va mai salvata nel database ne' scritta nei log.
	 */
	public static function password() {
		return defined( 'SYSDAT_MAILUP_PASSWORD' ) ? (string) SYSDAT_MAILUP_PASSWORD : '';
	}

	/**
	 * Servono tutte e quattro le costanti (password flow OAuth).
	 */
	public static function has_credentials() {
		return '' !== self::client_id()
			&& '' !== self::client_secret()
			&& '' !== self::username()
			&& '' !== self::password();
	}

	public static function token_url() {
		return self::AUTH_BASE . 'Token';
	}

	public static function console_url( $path ) {
		return self::CONSOLE_BASE . ltrim( $path, '/' );
	}

	/**
	 * Impostazioni salvate, con i default.
	 *
	 * Struttura:
	 * array(
	 *   'double_optin' => 1|0,
	 *   'rules' => array(
	 *     array(
	 *       'form_id'       => int,    // ID del form CF7 (post ID)
	 *       'lang'          => string, // codice lingua ('it', 'en'...; '' = tutte)
	 *       'list_id'       => int,    // ID lista MailUp
	 *       'group_id'      => int,    // ID gruppo MailUp (0 = nessun gruppo)
	 *       'email_field'   => string, // nome campo CF7 con l'email
	 *       'consent_field' => string, // nome campo CF7 del consenso ('' = non richiesto)
	 *       'field_map'     => array( 'nome-campo-cf7' => int ID campo dinamico MailUp ),
	 *     ),
	 *   ),
	 * )
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args(
			$saved,
			array(
				'double_optin' => 1,
				'rules'        => array(),
			)
		);
	}
}
