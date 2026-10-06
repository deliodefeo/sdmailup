<?php
/**
 * Client delle API REST di MailUp.
 *
 * Responsabilita': OAuth (password flow, refresh) e chiamate Console.
 * NON conosce Contact Form 7 ne' le impostazioni delle regole.
 *
 * Tutti i metodi pubblici restituiscono il risultato oppure un WP_Error
 * (mai eccezioni, mai output): un problema con MailUp non deve rompere
 * l'invio del form sul sito.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sysdat_MailUp_API {

	// Timeout esplicito: la chiamata avviene durante l'invio del form.
	const TIMEOUT = 15;

	const REFRESH_LOCK = 'sysdat_mailup_refresh_lock';

	/* ------------------------------------------------------------------
	 * OAuth
	 * ---------------------------------------------------------------- */

	/**
	 * Login con le credenziali dell'utente MailUp dedicato (password flow):
	 * restituisce subito access token e refresh token, senza redirect.
	 *
	 * @return true|WP_Error
	 */
	public function login_with_password() {
		if ( ! Sysdat_MailUp_Config::has_credentials() ) {
			return new WP_Error( 'sysdat_mailup_no_credentials', 'Credenziali MailUp mancanti in wp-config.php.' );
		}

		return $this->token_request(
			array(
				'grant_type' => 'password',
				'username'   => Sysdat_MailUp_Config::username(),
				'password'   => Sysdat_MailUp_Config::password(),
			)
		);
	}

	/**
	 * Rinnova l'access token con il refresh token; se MailUp lo rifiuta
	 * (scaduto, revocato, password cambiata) rifa' il login con la password.
	 * Se piu' richieste scadono insieme, una sola rinnova (lock breve).
	 *
	 * @return true|WP_Error
	 */
	private function refresh_access_token() {
		if ( get_transient( self::REFRESH_LOCK ) ) {
			// Un'altra richiesta sta gia' rinnovando: attendiamo e riusiamo i suoi token.
			usleep( 700000 );

			return true;
		}

		set_transient( self::REFRESH_LOCK, 1, 30 );

		$tokens = Sysdat_MailUp_Tokens::get();
		$result = new WP_Error( 'sysdat_mailup_no_refresh', 'Nessun refresh token.', array( 'code' => 400 ) );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			$result = $this->token_request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $tokens['refresh_token'],
				)
			);
		}

		// Refresh rifiutato (o assente): secondo tentativo con il password flow.
		if ( is_wp_error( $result ) && $this->is_auth_rejection( $result ) ) {
			$result = $this->login_with_password();
		}

		delete_transient( self::REFRESH_LOCK );

		// Rifiutato anche il login: credenziali errate/cambiate, serve intervento manuale.
		// Con la connessione marcata non valida non si ritenta a ogni invio di form.
		if ( is_wp_error( $result ) && $this->is_auth_rejection( $result ) ) {
			Sysdat_MailUp_Tokens::mark_invalid();
		}

		return $result;
	}

	/**
	 * True se l'errore e' un rifiuto di MailUp (400/401) e non un problema di rete.
	 */
	private function is_auth_rejection( WP_Error $error ) {
		$data = $error->get_error_data();
		$http = isset( $data['code'] ) ? (int) $data['code'] : 0;

		return in_array( $http, array( 400, 401 ), true );
	}

	/**
	 * Chiamata all'endpoint Token (password o refresh). Credenziali nel body POST.
	 */
	private function token_request( array $params ) {
		$params['client_id']     = Sysdat_MailUp_Config::client_id();
		$params['client_secret'] = Sysdat_MailUp_Config::client_secret();

		$response = wp_remote_post(
			Sysdat_MailUp_Config::token_url(),
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $params,
			)
		);

		if ( is_wp_error( $response ) ) {
			Sysdat_MailUp_Logger::log( 'Token: errore di rete', array( 'error' => $response->get_error_code() ) );

			return $response;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http < 200 || $http >= 300 || empty( $data['access_token'] ) ) {
			Sysdat_MailUp_Logger::log( 'Token: risposta non valida', array( 'http' => $http ) );

			return new WP_Error( 'sysdat_mailup_token', 'Richiesta token rifiutata da MailUp.', array( 'code' => $http ) );
		}

		Sysdat_MailUp_Tokens::save( $data );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Chiamata generica alla Console API
	 * ---------------------------------------------------------------- */

	/**
	 * @param string     $method GET|POST|PUT
	 * @param string     $path   Percorso relativo a CONSOLE_BASE
	 * @param array      $query  Parametri query string
	 * @param array|null $body   Body JSON
	 * @param bool       $retry  Se true, su 401/403 rinnova il token e riprova UNA volta
	 *
	 * @return array|WP_Error array( 'code' => int, 'data' => mixed )
	 */
	private function request( $method, $path, array $query = array(), $body = null, $retry = true ) {
		if ( ! Sysdat_MailUp_Tokens::is_connected() ) {
			return new WP_Error( 'sysdat_mailup_not_connected', 'MailUp non connesso.' );
		}

		$tokens = Sysdat_MailUp_Tokens::get();
		$url    = Sysdat_MailUp_Config::console_url( $path );
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $tokens['access_token'],
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Sysdat_MailUp_Logger::log( 'API: errore di rete', array( 'path' => $path, 'error' => $response->get_error_code() ) );

			return $response;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );

		if ( $retry && in_array( $http, array( 401, 403 ), true ) ) {
			$refreshed = $this->refresh_access_token();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}

			return $this->request( $method, $path, $query, $body, false );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http < 200 || $http >= 300 ) {
			// Loggare solo codice e percorso, mai il body (puo' contenere dati personali).
			Sysdat_MailUp_Logger::log( 'API: risposta di errore', array( 'path' => $path, 'http' => $http ) );

			return new WP_Error( 'sysdat_mailup_http', 'MailUp ha risposto con errore.', array( 'code' => $http ) );
		}

		return array(
			'code' => $http,
			'data' => $data,
		);
	}

	/* ------------------------------------------------------------------
	 * Lettura (usata dalla pagina impostazioni per mostrare gli ID)
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error Elenco di array( 'id' => int, 'name' => string )
	 */
	public function get_lists() {
		$res = $this->request( 'GET', 'List', array( 'PageNumber' => 0, 'PageSize' => 1000 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = array();
		foreach ( (array) ( isset( $res['data']['Items'] ) ? $res['data']['Items'] : array() ) as $item ) {
			$out[] = array(
				'id'   => (int) $item['IdList'],
				'name' => (string) $item['Name'],
			);
		}

		return $out;
	}

	/**
	 * @return array|WP_Error Elenco di array( 'id' => int, 'name' => string )
	 */
	public function get_groups( $list_id ) {
		$res = $this->request( 'GET', sprintf( 'List/%d/Groups', (int) $list_id ), array( 'PageNumber' => 0, 'PageSize' => 100 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = array();
		foreach ( (array) ( isset( $res['data']['Items'] ) ? $res['data']['Items'] : array() ) as $item ) {
			$out[] = array(
				'id'   => (int) $item['idGroup'],
				'name' => (string) $item['Name'],
			);
		}

		return $out;
	}

	/**
	 * Campi dinamici (anagrafica) definiti sulla console MailUp.
	 *
	 * @return array|WP_Error Elenco di array( 'id' => int, 'name' => string )
	 */
	public function get_dynamic_fields() {
		$res = $this->request( 'GET', 'Recipient/DynamicFields', array( 'PageNumber' => 0, 'PageSize' => 100 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = array();
		foreach ( (array) ( isset( $res['data']['Items'] ) ? $res['data']['Items'] : array() ) as $item ) {
			$out[] = array(
				'id'   => (int) $item['Id'],
				'name' => (string) $item['Description'],
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Iscrizione
	 * ---------------------------------------------------------------- */

	/**
	 * Iscrive un contatto a una lista e (opzionalmente) a uno o piu' gruppi.
	 * Chiamate, come nel plugin ufficiale:
	 *   1. POST List/{id}/Recipient              -> restituisce l'ID del recipient
	 *   2. POST Group/{id}/Subscribe/{recipient} (una per ogni gruppo > 0)
	 *
	 * @param int       $list_id   ID lista
	 * @param int|array $group_ids ID gruppo o elenco di ID (0/vuoto = nessuno)
	 * @param string $email    Email gia' validata
	 * @param array  $fields   array( ID campo dinamico (int) => valore (string) )
	 * @param bool   $confirm  true = double opt-in (MailUp invia l'email di conferma)
	 *
	 * @return true|WP_Error
	 */
	public function subscribe( $list_id, $group_ids, $email, array $fields, $confirm ) {
		$group_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $group_ids ) ) ) );
		$body      = array( 'Email' => $email );

		$dynamic = array();
		foreach ( $fields as $field_id => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$dynamic[] = array(
				'Id'    => (int) $field_id,
				'Value' => (string) $value,
			);
		}
		if ( $dynamic ) {
			$body['Fields'] = $dynamic;
		}

		// TODO testare cosa risponde MailUp se l'email e' GIA' presente nella lista
		// (errore? aggiornamento?). Va deciso se trattarlo come successo.
		$query = $confirm ? array( 'ConfirmEmail' => 'true' ) : array();
		$res   = $this->request( 'POST', sprintf( 'List/%d/Recipient', (int) $list_id ), $query, $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$recipient_id = (int) $res['data'];
		if ( $recipient_id <= 0 ) {
			return new WP_Error( 'sysdat_mailup_no_recipient', 'MailUp non ha restituito l\'ID del recipient.' );
		}

		foreach ( $group_ids as $group_id ) {
			// Il plugin ufficiale invia nel body lo stesso JSON del recipient.
			// TODO verificare se serve davvero; in caso contrario passare null.
			$res = $this->request(
				'POST',
				sprintf( 'Group/%d/Subscribe/%d', (int) $group_id, $recipient_id ),
				array( 'confirmSubscription' => 'false' ),
				$body
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return true;
	}
}
