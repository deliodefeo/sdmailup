# SYS-DAT MailUp Integration

Iscrive gli utenti a liste/gruppi MailUp all'invio di moduli Contact Form 7.

## Struttura

```
sysdat-mailup-integration/
├── sysdat-mailup-integration.php        Header plugin, checklist, avvio
├── uninstall.php                        Pulizia opzioni alla disinstallazione
├── README.md
├── includes/
│   ├── class-sysdat-mailup-config.php   Costanti, URL MailUp, credenziali, impostazioni
│   ├── class-sysdat-mailup-logger.php   Log senza dati personali
│   ├── class-sysdat-mailup-tokens.php   Salvataggio access/refresh token
│   ├── class-sysdat-mailup-api.php      OAuth (password flow, refresh) + chiamate Console API
│   └── class-sysdat-mailup-cf7.php      Hook wpcf7_mail_sent -> regole -> API
└── admin/
    ├── class-sysdat-mailup-admin.php    Menu, Settings API, connect/disconnect, avvisi
    └── views/
        └── settings-page.php            HTML della pagina impostazioni
```

Regola di progetto: un file = una responsabilita'. Solo `*-api.php` parla con MailUp,
solo `*-cf7.php` parla con Contact Form 7, solo `admin/` genera interfaccia.

## Setup

Autenticazione: OAuth2 **password flow** (nessun redirect URI da registrare).

1. Creare su MailUp un utente DEDICATO all'integrazione, con permessi minimi sulla lista
   (non l'account personale di qualcuno del team).
2. wp-config.php:
   ```php
   define( 'SYSDAT_MAILUP_CLIENT_ID',     '...' );
   define( 'SYSDAT_MAILUP_CLIENT_SECRET', '...' );
   define( 'SYSDAT_MAILUP_USERNAME',      'm1234' );
   define( 'SYSDAT_MAILUP_PASSWORD',      '...' );
   ```
3. Attivare il plugin, aprire Impostazioni > MailUp Integration e premere "Connetti a MailUp".
4. Creare le regole (form CF7 -> lista/gruppo, campo email, campo consenso, mappa campi).

Gruppo trigger (opzionale, da 0.3.0): ogni regola puo' avere un secondo gruppo MailUp, oltre a
quello della newsletter. Il contatto viene iscritto a entrambi; su MailUp un'automation (o
workflow) che parte dall'ingresso in quel gruppo invia, per esempio, l'ultima newsletter ai
nuovi iscritti. Il plugin non sa nulla dell'automation: la logica (invio una sola volta,
spostamento del contatto, messaggio da aggiornare ogni mese) sta tutta su MailUp.

Come funziona la connessione: il login restituisce access token (validita' 1 ora) e refresh
token. Alla scadenza il plugin rinnova col refresh token; se MailUp lo rifiuta rifa' da solo il
login con username e password. Se rifiuta anche quello (password cambiata/utente disattivato)
la connessione risulta scaduta, compare l'avviso in admin e le iscrizioni non vengono inviate
finche' non si aggiornano le costanti e si preme di nuovo "Connetti a MailUp".

Sicurezza: la password sta solo in wp-config.php (mai nel DB ne' nei log).

## Form tradotti (stesso ID, lingue diverse)

Ogni regola ha un campo "Lingua". Per un form IT/EN con lo stesso ID si creano due
righe con lo stesso ID form e lingua `it` / `en`, ciascuna col proprio gruppo MailUp.
Campo lingua scelto dall'utente (da 0.4.0): se il form ha un select/radio per la lingua
(es. `[lingua]`), si scrive il nome del campo in "Campo lingua CF7" su una riga del form: quel
valore ha la precedenza sulla lingua della pagina. Valori accettati: `it`, `en` o etichette
comuni (Italiano, English...; estendibili col filtro `sysdat_mailup_language_aliases`). Se il
valore manca o non e' riconosciuto si torna alla lingua della pagina. Gli altri form, senza
campo lingua, non cambiano.
La lingua e' scritta in un campo nascosto `_sysdat_lang` quando il form viene mostrato
(WPML o Polylang), quindi funziona anche per form in popup/overlay. Ripiego: lingua del
post contenitore, poi lingua corrente e locale WP. Forzabile col filtro
`sysdat_mailup_submission_language`.

## Da verificare in test (marcati TODO nel codice)

- Nel sorgente della pagina, dentro il form, c'e' `<input type="hidden" name="_sysdat_lang" value="it">` (o `en` nella pagina inglese).
- Lingua rilevata corretta per un invio IT e uno EN (log "Nessuna regola per questa lingua" se non combacia).
- Comportamento se l'email e' gia' presente nella lista.
- Body della chiamata Group/Subscribe: serve il JSON del recipient (come nel plugin ufficiale) o no?

## Fase 2 (non implementata)

- Coda + retry in background per iscrizioni fallite.
- Cifratura a riposo del refresh token.
- Flusso authorization code (redirect URI): rimosso in 0.2.0, si puo' reintrodurre se serve.
