<?php
/**
 * Aggancio a Contact Form 7.
 *
 * Responsabilita': a invio riuscito, trovare la regola del form, validare i
 * dati e chiamare l'API. Non contiene logica HTTP.
 *
 * Note:
 * - wpcf7_mail_sent scatta solo se l'email del form e' stata inviata.
 *   Se serve iscrivere anche con invio mail fallito, usare wpcf7_submit.
 * - Il form e' identificato dal suo ID (post ID di CF7). Con WPML/Polylang
 *   ogni traduzione puo' essere un form distinto: una regola per ciascuno.
 * - La chiamata e' sincrona (timeout 15s): se MailUp e' lento, l'utente
 *   attende. TODO fase 2: coda con wp_schedule_single_event + retry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sysdat_MailUp_CF7 {

	/** @var Sysdat_MailUp_API */
	private $api;

	public function __construct( Sysdat_MailUp_API $api ) {
		$this->api = $api;
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_submission' ) );
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'add_language_field' ) );
	}

	/**
	 * @param WPCF7_ContactForm $contact_form
	 */
	public function handle_submission( $contact_form ) {
		$settings   = Sysdat_MailUp_Config::get_settings();
		$form_id    = (int) $contact_form->id();
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();

		// Stesso form tradotto = stesso ID: la regola si sceglie con ID + lingua.
		// Se il form ha un campo lingua scelto dall'utente (es. [lingua]) e la
		// regola lo indica, vince quel campo; altrimenti lingua della pagina.
		$lang_field = $this->form_language_field( $form_id, $settings['rules'] );
		$lang       = $this->language_from_field( $posted, $lang_field );
		if ( '' === $lang ) {
			$lang = $this->detect_language( $submission );
		}
		$rule = $this->find_rule( $form_id, $lang, $settings['rules'] );

		if ( ! $rule ) {
			// Se il form ha regole ma non per questa lingua, lo segnaliamo (senza dati personali).
			if ( $this->form_has_rules( $form_id, $settings['rules'] ) ) {
				Sysdat_MailUp_Logger::log( 'Nessuna regola per questa lingua', array( 'form_id' => $form_id, 'lang' => $lang ) );
			}

			return; // Form non mappato: non e' un form di iscrizione.
		}

		// Consenso: se la regola lo prevede, senza spunta NON si iscrive.
		if ( '' !== $rule['consent_field'] && ! $this->is_checked( $posted, $rule['consent_field'] ) ) {
			return;
		}

		$email_field = '' !== $rule['email_field'] ? $rule['email_field'] : 'your-email';
		$email       = isset( $posted[ $email_field ] ) ? sanitize_email( (string) $posted[ $email_field ] ) : '';
		if ( ! is_email( $email ) ) {
			Sysdat_MailUp_Logger::log( 'Email non valida, iscrizione saltata', array( 'form_id' => $form_id ) );

			return;
		}

		// Campi anagrafici: nome campo CF7 => ID campo dinamico MailUp.
		$fields = array();
		foreach ( $rule['field_map'] as $cf7_name => $mailup_id ) {
			if ( isset( $posted[ $cf7_name ] ) ) {
				$value = is_array( $posted[ $cf7_name ] ) ? implode( ', ', $posted[ $cf7_name ] ) : (string) $posted[ $cf7_name ];
				$fields[ (int) $mailup_id ] = sanitize_text_field( $value );
			}
		}

		// Gruppo newsletter + (opzionale) gruppo trigger che fa partire l'automation
		// di benvenuto/arretrato su MailUp.
		$group_ids = array( (int) $rule['group_id'], (int) $rule['trigger_group_id'] );

		$result = $this->api->subscribe(
			(int) $rule['list_id'],
			$group_ids,
			$email,
			$fields,
			! empty( $settings['double_optin'] )
		);

		if ( is_wp_error( $result ) ) {
			// Mai far fallire il form per un problema lato MailUp: solo log.
			Sysdat_MailUp_Logger::log(
				'Iscrizione fallita',
				array(
					'form_id' => $form_id,
					'error'   => $result->get_error_code(),
				)
			);
		}
	}

	/**
	 * Aggiunge a ogni form CF7 un campo nascosto con la lingua della pagina
	 * al momento del RENDER (richiesta di pagina normale, dove WPML conosce la
	 * lingua giusta). Cosi' funziona anche per form in popup/overlay, widget
	 * o template, indipendentemente da quale post li contiene.
	 */
	public function add_language_field( $fields ) {
		$fields['_sysdat_lang'] = $this->current_language();

		return $fields;
	}

	/**
	 * Lingua corrente (WPML, poi Polylang, poi locale WP), normalizzata.
	 */
	private function current_language() {
		$lang = '';

		if ( has_filter( 'wpml_current_language' ) ) {
			$lang = (string) apply_filters( 'wpml_current_language', null );
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language( 'slug' );
		}

		if ( '' === $lang ) {
			$lang = determine_locale();
		}

		return $this->normalize_lang( $lang );
	}

	/**
	 * "it_IT" / "IT" / "pt-BR" -> "it" / "it" / "pt-br". Stringa vuota se non valida.
	 */
	private function normalize_lang( $value ) {
		$value = strtolower( str_replace( '_', '-', trim( (string) $value ) ) );

		return preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $value ) ? $value : '';
	}

	/**
	 * Lingua del form inviato, normalizzata (es. "it", "en", "pt-br").
	 *
	 * Ordine dei segnali (il primo disponibile vince):
	 * 1. campo nascosto _sysdat_lang scritto al render del form (vedi sopra):
	 *    il piu' affidabile, perche' CF7 invia via REST dove la "lingua
	 *    corrente" di WPML puo' essere quella di default;
	 * 2. lingua del post che contiene il form (container_post_id di CF7);
	 * 3. lingua corrente WPML/Polylang, poi locale WP.
	 * Il risultato si puo' forzare col filtro 'sysdat_mailup_submission_language'.
	 *
	 * Nota: il valore del campo nascosto arriva dal browser e potrebbe essere
	 * manipolato. L'unico effetto possibile e' scegliere un'altra regola GIA'
	 * configurata per lo stesso form: nessun rischio per i dati.
	 */
	private function detect_language( $submission ) {
		$lang = '';

		// CF7 toglie i campi che iniziano con "_" da posted_data: si legge da $_POST.
		if ( isset( $_POST['_sysdat_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$lang = $this->normalize_lang( sanitize_text_field( wp_unslash( $_POST['_sysdat_lang'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( '' === $lang ) {
			$post_id = (int) $submission->get_meta( 'container_post_id' );
			if ( $post_id > 0 ) {
				if ( has_filter( 'wpml_post_language_details' ) ) {
					$details = apply_filters( 'wpml_post_language_details', null, $post_id );
					if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
						$lang = $this->normalize_lang( $details['language_code'] );
					}
				} elseif ( function_exists( 'pll_get_post_language' ) ) {
					$lang = $this->normalize_lang( pll_get_post_language( $post_id, 'slug' ) );
				}
			}
		}

		if ( '' === $lang ) {
			$lang = $this->current_language();
		}

		return (string) apply_filters( 'sysdat_mailup_submission_language', $lang, $submission );
	}

	/**
	 * Nome del campo lingua scelto dall'utente per questo form: il primo
	 * "campo lingua" non vuoto tra le regole del form (vale per tutte le sue righe).
	 */
	private function form_language_field( $form_id, $rules ) {
		foreach ( (array) $rules as $rule ) {
			if ( isset( $rule['form_id'] ) && (int) $rule['form_id'] === $form_id && ! empty( $rule['lang_field'] ) ) {
				return (string) $rule['lang_field'];
			}
		}

		return '';
	}

	/**
	 * Lingua dal campo CF7 scelto dall'utente (select/radio). Accetta codici
	 * (it, en) o etichette comuni (Italiano, English...): estendibili col filtro
	 * 'sysdat_mailup_language_aliases'. Stringa vuota se assente o non riconosciuta
	 * (in quel caso si usa la lingua della pagina).
	 */
	private function language_from_field( array $posted, $field ) {
		if ( '' === $field || ! isset( $posted[ $field ] ) ) {
			return '';
		}

		$value = $posted[ $field ];
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );

		$aliases = apply_filters(
			'sysdat_mailup_language_aliases',
			array(
				'italiano' => 'it',
				'italian'  => 'it',
				'ita'      => 'it',
				'inglese'  => 'en',
				'english'  => 'en',
				'eng'      => 'en',
			)
		);
		if ( isset( $aliases[ $value ] ) ) {
			$value = $aliases[ $value ];
		}

		return $this->normalize_lang( $value );
	}

	/**
	 * Regola per form + lingua. Una regola con lingua esatta batte quella
	 * con lingua vuota ("tutte le lingue"), che fa da ripiego.
	 */
	private function find_rule( $form_id, $lang, $rules ) {
		$fallback  = null;
		$lang_base = (string) strtok( $lang, '-' );

		foreach ( (array) $rules as $rule ) {
			if ( ! isset( $rule['form_id'] ) || (int) $rule['form_id'] !== $form_id ) {
				continue;
			}

			$rule_lang = isset( $rule['lang'] ) ? (string) $rule['lang'] : '';

			if ( '' === $rule_lang ) {
				if ( null === $fallback ) {
					$fallback = $rule;
				}
				continue;
			}

			if ( $rule_lang === $lang || $rule_lang === $lang_base ) {
				return $this->normalize_rule( $rule );
			}
		}

		return $fallback ? $this->normalize_rule( $fallback ) : null;
	}

	private function form_has_rules( $form_id, $rules ) {
		foreach ( (array) $rules as $rule ) {
			if ( isset( $rule['form_id'] ) && (int) $rule['form_id'] === $form_id ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_rule( array $rule ) {
		return wp_parse_args(
			$rule,
			array(
				'lang'          => '',
				'lang_field'    => '',
				'list_id'       => 0,
				'group_id'      => 0,
				'trigger_group_id' => 0,
				'email_field'   => 'your-email',
				'consent_field' => '',
				'field_map'     => array(),
			)
		);
	}

	/**
	 * Un campo acceptance/checkbox CF7 e' "spuntato" se ha un valore non vuoto
	 * (puo' arrivare come stringa o come array).
	 */
	private function is_checked( array $posted, $name ) {
		if ( ! isset( $posted[ $name ] ) ) {
			return false;
		}
		$value = $posted[ $name ];
		if ( is_array( $value ) ) {
			$value = array_filter( $value );

			return ! empty( $value );
		}

		return '' !== trim( (string) $value ) && '0' !== (string) $value;
	}
}
