<?php
/**
 * Area admin: Impostazioni > MailUp Integration.
 *
 * - pagina impostazioni (regole form CF7 -> lista/gruppo)
 * - connessione a MailUp (password flow) e disconnessione
 * - avvisi (connessione scaduta, credenziali mancanti, CF7 assente)
 *
 * Solo utenti con capability manage_options. Ogni azione ha un nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sysdat_MailUp_Admin {

	const SETTINGS_GROUP   = 'sysdat_mailup';
	const REFERENCE_CACHE  = 'sysdat_mailup_reference';
	const NOTICE_PARAM     = 'sysdat_mailup_status';

	/** @var Sysdat_MailUp_API */
	private $api;

	public function __construct( Sysdat_MailUp_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sysdat_mailup_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_sysdat_mailup_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/* ------------------------------------------------------------------
	 * Menu e pagina
	 * ---------------------------------------------------------------- */

	public function add_menu() {
		add_options_page(
			__( 'MailUp Integration', 'sysdat-mailup' ),
			__( 'MailUp Integration', 'sysdat-mailup' ),
			'manage_options',
			Sysdat_MailUp_Config::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings        = Sysdat_MailUp_Config::get_settings();
		$has_credentials = Sysdat_MailUp_Config::has_credentials();
		$connected       = Sysdat_MailUp_Tokens::is_connected();
		$reference       = $connected ? $this->get_reference_data() : null;
		$status_message  = $this->get_status_message();

		include SYSDAT_MAILUP_DIR . 'admin/views/settings-page.php';
	}

	/* ------------------------------------------------------------------
	 * Impostazioni (Settings API)
	 * ---------------------------------------------------------------- */

	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Sysdat_MailUp_Config::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Pulisce e normalizza quanto arriva dal form impostazioni.
	 * Le righe senza ID form o ID lista vengono scartate (righe vuote).
	 */
	public function sanitize_settings( $input ) {
		$out = array(
			'double_optin' => empty( $input['double_optin'] ) ? 0 : 1,
			'rules'        => array(),
		);

		if ( empty( $input['rules'] ) || ! is_array( $input['rules'] ) ) {
			return $out;
		}

		foreach ( $input['rules'] as $rule ) {
			$form_id = isset( $rule['form_id'] ) ? absint( $rule['form_id'] ) : 0;
			$list_id = isset( $rule['list_id'] ) ? absint( $rule['list_id'] ) : 0;
			if ( ! $form_id || ! $list_id ) {
				continue;
			}

			$out['rules'][] = array(
				'form_id'       => $form_id,
				'lang'          => $this->clean_lang( isset( $rule['lang'] ) ? $rule['lang'] : '' ),
				'lang_field'    => $this->clean_field_name( isset( $rule['lang_field'] ) ? $rule['lang_field'] : '' ),
				'list_id'       => $list_id,
				'group_id'      => isset( $rule['group_id'] ) ? absint( $rule['group_id'] ) : 0,
				'trigger_group_id' => isset( $rule['trigger_group_id'] ) ? absint( $rule['trigger_group_id'] ) : 0,
				'email_field'   => $this->clean_field_name( isset( $rule['email_field'] ) ? $rule['email_field'] : '' ),
				'consent_field' => $this->clean_field_name( isset( $rule['consent_field'] ) ? $rule['consent_field'] : '' ),
				'field_map'     => $this->parse_field_map( isset( $rule['field_map'] ) ? $rule['field_map'] : '' ),
			);
		}

		return $out;
	}

	/**
	 * Codice lingua (es. it, en, pt-br): minuscolo, solo lettere e trattino.
	 * Vuoto = la regola vale per tutte le lingue.
	 */
	private function clean_lang( $value ) {
		$value = strtolower( str_replace( '_', '-', (string) $value ) );

		return substr( preg_replace( '/[^a-z\-]/', '', $value ), 0, 10 );
	}

	/**
	 * Nomi campo CF7: solo lettere, numeri, trattini e underscore.
	 */
	private function clean_field_name( $value ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	}

	/**
	 * Testo "nome-campo-cf7=ID" (una riga per campo) -> array( nome => ID ).
	 * Esempio:
	 *   your-name=1
	 *   your-surname=2
	 *   your-company=3
	 */
	private function parse_field_map( $text ) {
		$map = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name = $this->clean_field_name( trim( $parts[0] ) );
			$id   = absint( trim( $parts[1] ) );
			if ( '' !== $name && $id > 0 ) {
				$map[ $name ] = $id;
			}
		}

		return $map;
	}

	/* ------------------------------------------------------------------
	 * Connessione: connect / disconnect
	 * ---------------------------------------------------------------- */

	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'sysdat-mailup' ), 403 );
		}
		check_admin_referer( 'sysdat_mailup_connect' );

		if ( ! Sysdat_MailUp_Config::has_credentials() ) {
			$this->redirect_to_page( 'no_credentials' );
		}

		// Login diretto con l'utente MailUp dedicato (nessun redirect, nessun "state").
		$result = $this->api->login_with_password();

		delete_transient( self::REFERENCE_CACHE );
		$this->redirect_to_page( is_wp_error( $result ) ? 'connect_error' : 'connected' );
	}

	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'sysdat-mailup' ), 403 );
		}
		check_admin_referer( 'sysdat_mailup_disconnect' );

		Sysdat_MailUp_Tokens::clear();
		delete_transient( self::REFERENCE_CACHE );
		$this->redirect_to_page( 'disconnected' );
	}

	private function redirect_to_page( $status ) {
		wp_safe_redirect(
			add_query_arg(
				self::NOTICE_PARAM,
				$status,
				admin_url( 'options-general.php?page=' . Sysdat_MailUp_Config::PAGE_SLUG )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------
	 * Avvisi
	 * ---------------------------------------------------------------- */

	/**
	 * Messaggio di esito (whitelist: mai stampare il parametro cosi' com'e').
	 */
	private function get_status_message() {
		$messages = array(
			'connected'      => array( 'success', __( 'Connessione a MailUp riuscita.', 'sysdat-mailup' ) ),
			'disconnected'   => array( 'info', __( 'Disconnesso da MailUp.', 'sysdat-mailup' ) ),
			'connect_error'  => array( 'error', __( 'Connessione a MailUp non riuscita. Controlla Client ID, Client Secret, username e password in wp-config.php, poi riprova.', 'sysdat-mailup' ) ),
			'no_credentials' => array( 'error', __( 'Credenziali MailUp mancanti in wp-config.php (servono Client ID, Client Secret, username e password).', 'sysdat-mailup' ) ),
		);

		$code = isset( $_GET[ self::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return isset( $messages[ $code ] ) ? $messages[ $code ] : null;
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Su tutte le schermate admin: se la connessione e' scaduta le iscrizioni falliscono in silenzio.
		if ( Sysdat_MailUp_Tokens::is_invalid() ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'MailUp: la connessione e\' scaduta, le iscrizioni dai form NON vengono inviate.', 'sysdat-mailup' ),
				esc_url( admin_url( 'options-general.php?page=' . Sysdat_MailUp_Config::PAGE_SLUG ) ),
				esc_html__( 'Riconnetti', 'sysdat-mailup' )
			);
		}

		// Solo sulla nostra pagina: promemoria sui prerequisiti.
		$on_our_page = isset( $_GET['page'] ) && Sysdat_MailUp_Config::PAGE_SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $on_our_page && ! class_exists( 'WPCF7_ContactForm' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Contact Form 7 non risulta attivo: il plugin non puo\' intercettare i form.', 'sysdat-mailup' )
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Dati di riferimento (ID di liste, gruppi, campi) con cache breve
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error array( 'lists' => [ [id, name, groups => [ [id, name] ]] ], 'fields' => [ [id, name] ] )
	 */
	private function get_reference_data() {
		$cached = get_transient( self::REFERENCE_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$lists = $this->api->get_lists();
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		foreach ( $lists as $i => $list ) {
			$groups              = $this->api->get_groups( $list['id'] );
			$lists[ $i ]['groups'] = is_wp_error( $groups ) ? array() : $groups;
		}

		$fields = $this->api->get_dynamic_fields();
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$data = array(
			'lists'  => $lists,
			'fields' => $fields,
		);
		set_transient( self::REFERENCE_CACHE, $data, 10 * MINUTE_IN_SECONDS );

		return $data;
	}
}
