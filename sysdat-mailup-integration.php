<?php
/**
 * Plugin Name:       SYS-DAT MailUp Integration
 * Description:       Iscrive gli utenti a liste/gruppi MailUp quando viene inviato un modulo Contact Form 7 (API REST, OAuth2 password flow).
 * Version:           0.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            SYS-DAT Group
 * Text Domain:       sysdat-mailup
 *
 * =====================================================================
 * COSA SERVE PRIMA DI USARLO (checklist)
 * =====================================================================
 * 1. Credenziali OAuth dell'app, da richiedere a MailUp (NON riusare
 *    quelle del plugin ufficiale), piu' username e password di un utente
 *    MailUp DEDICATO all'integrazione (permessi minimi), non l'account
 *    personale di qualcuno del team. Vanno in wp-config.php, NON nel DB:
 *
 *      define( 'SYSDAT_MAILUP_CLIENT_ID',     'INSERIRE-CLIENT-ID' );
 *      define( 'SYSDAT_MAILUP_CLIENT_SECRET', 'INSERIRE-CLIENT-SECRET' );
 *      define( 'SYSDAT_MAILUP_USERNAME',      'INSERIRE-USERNAME-MAILUP' );
 *      define( 'SYSDAT_MAILUP_PASSWORD',      'INSERIRE-PASSWORD-MAILUP' );
 *
 *    Nessun redirect URI da registrare: il login usa il password flow.
 *    Se la password dell'utente MailUp cambia, aggiornare la costante.
 *
 * 2. ID numerici di lista e gruppi MailUp e ID dei campi dinamici
 *    (la pagina impostazioni li mostra quando la connessione e' attiva).
 *
 * 3. Contact Form 7 attivo.
 * =====================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYSDAT_MAILUP_VERSION', '0.4.0' );
define( 'SYSDAT_MAILUP_FILE', __FILE__ );
define( 'SYSDAT_MAILUP_DIR', plugin_dir_path( __FILE__ ) );

require_once SYSDAT_MAILUP_DIR . 'includes/class-sysdat-mailup-config.php';
require_once SYSDAT_MAILUP_DIR . 'includes/class-sysdat-mailup-logger.php';
require_once SYSDAT_MAILUP_DIR . 'includes/class-sysdat-mailup-tokens.php';
require_once SYSDAT_MAILUP_DIR . 'includes/class-sysdat-mailup-api.php';
require_once SYSDAT_MAILUP_DIR . 'includes/class-sysdat-mailup-cf7.php';
require_once SYSDAT_MAILUP_DIR . 'admin/class-sysdat-mailup-admin.php';

/**
 * Avvio: costruisce le classi e le collega (nessuna logica qui).
 */
function sysdat_mailup_run() {
	$api = new Sysdat_MailUp_API();

	new Sysdat_MailUp_CF7( $api );

	if ( is_admin() ) {
		new Sysdat_MailUp_Admin( $api );
	}
}
add_action( 'plugins_loaded', 'sysdat_mailup_run' );
