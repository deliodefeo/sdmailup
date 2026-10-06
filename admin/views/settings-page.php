<?php
/**
 * Vista della pagina Impostazioni > MailUp Integration.
 *
 * Variabili disponibili (impostate da Sysdat_MailUp_Admin::render_page):
 *   array            $settings
 *   bool             $has_credentials
 *   bool             $connected
 *   array|WP_Error|null $reference
 *   array|null       $status_message  array( tipo, testo )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$option = Sysdat_MailUp_Config::OPTION_SETTINGS;
$rules  = array_values( (array) $settings['rules'] );
if ( empty( $rules ) ) {
	$rules[] = array(); // almeno una riga vuota da compilare
}

/**
 * Stampa una riga di regola. $index e' un numero, oppure il segnaposto
 * __INDEX__ per il template clonato dal JavaScript.
 */
$render_row = function ( $index, $rule ) use ( $option ) {
	$map_lines = array();
	if ( ! empty( $rule['field_map'] ) ) {
		foreach ( $rule['field_map'] as $name => $id ) {
			$map_lines[] = $name . '=' . $id;
		}
	}
	$base = $option . '[rules][' . $index . ']';
	?>
	<tr class="sysdat-mailup-rule">
		<td><input type="number" min="1" class="small-text" name="<?php echo esc_attr( $base ); ?>[form_id]" value="<?php echo isset( $rule['form_id'] ) ? esc_attr( $rule['form_id'] ) : ''; ?>"></td>
		<td><input type="text" class="regular-text" style="width:70px" name="<?php echo esc_attr( $base ); ?>[lang]" value="<?php echo isset( $rule['lang'] ) ? esc_attr( $rule['lang'] ) : ''; ?>"></td>
		<td><input type="text" class="regular-text" style="width:100px" name="<?php echo esc_attr( $base ); ?>[lang_field]" value="<?php echo isset( $rule['lang_field'] ) ? esc_attr( $rule['lang_field'] ) : ''; ?>"></td>
		<td><input type="number" min="1" class="small-text" name="<?php echo esc_attr( $base ); ?>[list_id]" value="<?php echo isset( $rule['list_id'] ) ? esc_attr( $rule['list_id'] ) : ''; ?>"></td>
		<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $base ); ?>[group_id]" value="<?php echo ! empty( $rule['group_id'] ) ? esc_attr( $rule['group_id'] ) : ''; ?>"></td>
		<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $base ); ?>[trigger_group_id]" value="<?php echo ! empty( $rule['trigger_group_id'] ) ? esc_attr( $rule['trigger_group_id'] ) : ''; ?>"></td>
		<td><input type="text" class="regular-text" style="width:130px" name="<?php echo esc_attr( $base ); ?>[email_field]" value="<?php echo isset( $rule['email_field'] ) ? esc_attr( $rule['email_field'] ) : 'your-email'; ?>"></td>
		<td><input type="text" class="regular-text" style="width:130px" name="<?php echo esc_attr( $base ); ?>[consent_field]" value="<?php echo isset( $rule['consent_field'] ) ? esc_attr( $rule['consent_field'] ) : ''; ?>"></td>
		<td><textarea rows="3" cols="28" name="<?php echo esc_attr( $base ); ?>[field_map]"><?php echo esc_textarea( implode( "\n", $map_lines ) ); ?></textarea></td>
		<td><button type="button" class="button-link button-link-delete sysdat-mailup-remove"><?php esc_html_e( 'Rimuovi', 'sysdat-mailup' ); ?></button></td>
	</tr>
	<?php
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'MailUp Integration', 'sysdat-mailup' ); ?></h1>

	<?php if ( $status_message ) : ?>
		<div class="notice notice-<?php echo esc_attr( $status_message[0] ); ?>"><p><?php echo esc_html( $status_message[1] ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( '1. Connessione', 'sysdat-mailup' ); ?></h2>

	<?php if ( ! $has_credentials ) : ?>
		<p>
			<?php esc_html_e( 'Mancano le credenziali MailUp. Aggiungi in wp-config.php (utente MailUp dedicato):', 'sysdat-mailup' ); ?>
		</p>
		<pre>define( 'SYSDAT_MAILUP_CLIENT_ID',     'INSERIRE-CLIENT-ID' );
define( 'SYSDAT_MAILUP_CLIENT_SECRET', 'INSERIRE-CLIENT-SECRET' );
define( 'SYSDAT_MAILUP_USERNAME',      'INSERIRE-USERNAME-MAILUP' );
define( 'SYSDAT_MAILUP_PASSWORD',      'INSERIRE-PASSWORD-MAILUP' );</pre>
	<?php endif; ?>

	<p>
		<strong><?php esc_html_e( 'Stato:', 'sysdat-mailup' ); ?></strong>
		<?php echo $connected ? esc_html__( 'connesso', 'sysdat-mailup' ) : esc_html__( 'non connesso', 'sysdat-mailup' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( $connected ) : ?>
			<input type="hidden" name="action" value="sysdat_mailup_disconnect">
			<?php wp_nonce_field( 'sysdat_mailup_disconnect' ); ?>
			<?php submit_button( __( 'Disconnetti', 'sysdat-mailup' ), 'secondary', 'submit', false ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="sysdat_mailup_connect">
			<?php wp_nonce_field( 'sysdat_mailup_connect' ); ?>
			<?php submit_button( __( 'Connetti a MailUp', 'sysdat-mailup' ), 'primary', 'submit', false, $has_credentials ? array() : array( 'disabled' => 'disabled' ) ); ?>
		<?php endif; ?>
	</form>

	<hr>

	<h2><?php esc_html_e( '2. Regole form → lista/gruppo', 'sysdat-mailup' ); ?></h2>

	<form method="post" action="options.php">
		<?php settings_fields( Sysdat_MailUp_Admin::SETTINGS_GROUP ); ?>

		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[double_optin]" value="1" <?php checked( ! empty( $settings['double_optin'] ) ); ?>>
				<?php esc_html_e( 'Double opt-in (MailUp invia l\'email di conferma)', 'sysdat-mailup' ); ?>
			</label>
		</p>

		<table class="widefat striped" style="max-width:1200px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID form CF7', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'Lingua (it, en… vuoto = tutte)', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'Campo lingua CF7 (opz.)', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'ID lista MailUp', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'ID gruppo newsletter (opz.)', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'ID gruppo trigger automation (opz.)', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'Campo email CF7', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'Campo consenso CF7 (opz.)', 'sysdat-mailup' ); ?></th>
					<th><?php esc_html_e( 'Mappa campi (nome-cf7=ID MailUp, uno per riga)', 'sysdat-mailup' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="sysdat-mailup-rules" data-next-index="<?php echo (int) count( $rules ); ?>">
			<?php foreach ( $rules as $i => $rule ) : ?>
				<?php $render_row( (int) $i, $rule ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="sysdat-mailup-add-rule"><?php esc_html_e( '+ Aggiungi regola', 'sysdat-mailup' ); ?></button>
		</p>

		<template id="sysdat-mailup-row-template">
			<?php $render_row( '__INDEX__', array() ); ?>
		</template>

		<p class="description">
			<?php esc_html_e( 'Una regola per ogni form CF7 da collegare. Se lo stesso form e\' tradotto (stesso ID), crea una riga per lingua con lo stesso ID form e il codice lingua (it, en…). Se nel form l\'utente sceglie la lingua (es. campo [lingua]), indica il nome del campo in "Campo lingua CF7": ha la precedenza sulla lingua della pagina (basta compilarlo su una riga del form). Le righe senza ID form o ID lista vengono ignorate; "Rimuovi" e le nuove righe hanno effetto dopo il salvataggio.', 'sysdat-mailup' ); ?>
			<?php esc_html_e( 'Se il campo consenso e\' valorizzato, l\'iscrizione avviene solo con il consenso spuntato.', 'sysdat-mailup' ); ?>
			<?php esc_html_e( 'Il gruppo trigger (opz.) serve a far partire un\'automation su MailUp: il contatto viene iscritto anche a quel gruppo, oltre al gruppo newsletter.', 'sysdat-mailup' ); ?>
		</p>

		<?php submit_button(); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( '3. ID di riferimento da MailUp', 'sysdat-mailup' ); ?></h2>

	<?php if ( ! $connected ) : ?>
		<p><?php esc_html_e( 'Connetti il plugin per vedere liste, gruppi e campi.', 'sysdat-mailup' ); ?></p>
	<?php elseif ( is_wp_error( $reference ) ) : ?>
		<p><?php esc_html_e( 'Impossibile leggere i dati da MailUp. Riprova tra poco.', 'sysdat-mailup' ); ?></p>
	<?php elseif ( $reference ) : ?>
		<h3><?php esc_html_e( 'Liste e gruppi', 'sysdat-mailup' ); ?></h3>
		<table class="widefat striped" style="max-width:700px">
			<thead><tr><th><?php esc_html_e( 'Lista', 'sysdat-mailup' ); ?></th><th>ID</th><th><?php esc_html_e( 'Gruppi (ID)', 'sysdat-mailup' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $reference['lists'] as $list ) : ?>
				<tr>
					<td><?php echo esc_html( $list['name'] ); ?></td>
					<td><?php echo esc_html( $list['id'] ); ?></td>
					<td>
						<?php
						$labels = array();
						foreach ( $list['groups'] as $group ) {
							$labels[] = $group['name'] . ' (' . $group['id'] . ')';
						}
						echo esc_html( implode( ', ', $labels ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Campi dinamici', 'sysdat-mailup' ); ?></h3>
		<table class="widefat striped" style="max-width:400px">
			<thead><tr><th><?php esc_html_e( 'Campo', 'sysdat-mailup' ); ?></th><th>ID</th></tr></thead>
			<tbody>
			<?php foreach ( $reference['fields'] as $field ) : ?>
				<tr><td><?php echo esc_html( $field['name'] ); ?></td><td><?php echo esc_html( $field['id'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Dati in cache per 10 minuti.', 'sysdat-mailup' ); ?></p>
	<?php endif; ?>

	<script>
	(function () {
		var body = document.getElementById('sysdat-mailup-rules');
		var tpl  = document.getElementById('sysdat-mailup-row-template');
		var add  = document.getElementById('sysdat-mailup-add-rule');
		if (!body || !tpl || !add) { return; }

		var next = parseInt(body.getAttribute('data-next-index'), 10) || body.rows.length;

		add.addEventListener('click', function () {
			body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__INDEX__/g, String(next++)));
		});

		body.addEventListener('click', function (e) {
			if (e.target.classList.contains('sysdat-mailup-remove')) {
				var row = e.target.closest('tr');
				if (row) { row.remove(); }
			}
		});
	})();
	</script>
</div>
