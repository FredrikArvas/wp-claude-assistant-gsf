# GSF Styrelseassistent

WordPress child-plugin till [AI Engine](https://wordpress.org/plugins/ai-engine/) som anpassar AI Engine för **Guldboda Samfällighetsförenings** styrelseassistent.

## Vad pluginet gör

- Injicerar GSF:s systempromt ("hjärna") i alla chattar via AI Engine
- Söker i `docs/index.json` och bifogar relevanta föreningsdokument som kontext
- Begränsar chatboten till inloggade användare med rollen `administrator` eller `editor`

## Krav

- WordPress 6.0+
- [AI Engine](https://wordpress.org/plugins/ai-engine/) installerat och aktiverat
- Anthropic API-nyckel konfigurerad i AI Engine (Settings → AI Engine → Anthropic)
- PHP 8.0+
- [Composer](https://getcomposer.org/) (för CLI-scriptet och textextraktion)

## Installation

1. Klona eller ladda ned repot till `wp-content/plugins/wp-claude-assistant-gsf/`
2. Kör `composer install` i pluginkatalogen
3. Aktivera pluginet i WordPress adminpanel
4. Lägg till dokument i `docs/` och kör `php tools/build-index.php`

## Lägg till dokument

1. Kopiera PDF, DOCX, XLSX eller TXT-filer till mappen `docs/`
2. Kör indexeringsscriptet (se nedan)
3. Dokument inkluderas automatiskt vid nästa chattförfrågan

## Kör build-index.php

Scriptet genererar/uppdaterar `docs/index.json` med AI-genererade titlar, nyckelord och sammanfattningar.

```bash
# Sätt API-nyckel (eller lägg den i .env)
export ANTHROPIC_API_KEY=sk-ant-...

# Kör från pluginets rotkatalog
cd wp-content/plugins/wp-claude-assistant-gsf
php tools/build-index.php
```

Filer som inte ändrats sedan senaste körning hoppas automatiskt över.

### .env-fil (alternativ till miljövariabel)

Skapa `.env` i pluginets rotkatalog:
```
ANTHROPIC_API_KEY=sk-ant-...
```

`.env` ligger i `.gitignore` och committas aldrig.

## Uppdatera system-prompten

Redigera `includes/system-prompt.php` – funktionen `gsf_get_system_prompt()` returnerar en PHP-heredoc med hela systemprompten. Inga andra filer behöver ändras.

## AI Engine-hooks

Pluginet använder:
- `mwai_system_prompt` – primär hook för att injicera systempromt
- `mwai_ai_query` – fallback-hook som modifierar query-objektet direkt
- `do_shortcode_tag` – för att dölja chatboten för ej behöriga användare

## Versionshantering

Semantisk versionering: `v1.0.0`, `v1.1.0` etc. Se [CHANGELOG.md](CHANGELOG.md).
