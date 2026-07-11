# Changelog – wp-claude-assistant-gsf

## [1.0.0] – 2026-03-26

### Tillagt
- Grundstruktur och plugin-registrering (`wp-claude-assistant-gsf.php`)
- System-promt med GSF-hjärnan (`includes/system-prompt.php`)
- Dokumentmapp med tom `docs/index.json`
- Kontexthämtning via AI Engine-hook `mwai_system_prompt` och fallback `mwai_ai_query` (`includes/document-context.php`)
- Åtkomstkontroll för administrator/editor via `do_shortcode_tag`-filter (`includes/access-control.php`)
- CLI-script `tools/build-index.php` med Claude API-integration för automatisk metadata-generering
- `composer.json` med beroenden: smalot/pdfparser, phpoffice/phpword, phpoffice/phpspreadsheet
- `.gitignore` som exkluderar vendor/, .env och föreningsdokument
