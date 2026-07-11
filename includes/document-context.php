<?php
/**
 * Kontexthämtning – söker i docs/index.json och bifogar relevanta dokument
 * till systemprompten via AI Engine-hooks.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Läser och returnerar docs/index.json, eller null vid fel.
 *
 * @return array<string, mixed>|null
 */
function gsf_load_index(): ?array {
    $path = GSF_ASSISTANT_DIR . 'docs/index.json';

    if ( ! file_exists( $path ) ) {
        return null;
    }

    $json = file_get_contents( $path );
    if ( $json === false ) {
        return null;
    }

    $data = json_decode( $json, true );
    return is_array( $data ) ? $data : null;
}

/**
 * Poängsätter ett dokument mot en sökfråga.
 * Enkel ordmatchning mot keywords och summary.
 *
 * @param array<string, mixed> $file
 * @param string               $query
 * @return int
 */
function gsf_score_document( array $file, string $query ): int {
    $score      = 0;
    $query_low  = mb_strtolower( $query );
    $words      = preg_split( '/\s+/', $query_low, -1, PREG_SPLIT_NO_EMPTY );

    if ( empty( $words ) ) {
        return 0;
    }

    $keywords = array_map( 'mb_strtolower', $file['keywords'] ?? [] );
    $summary  = mb_strtolower( $file['summary'] ?? '' );
    $title    = mb_strtolower( $file['title'] ?? '' );

    foreach ( $words as $word ) {
        if ( strlen( $word ) < 3 ) {
            continue;
        }
        foreach ( $keywords as $kw ) {
            if ( str_contains( $kw, $word ) || str_contains( $word, $kw ) ) {
                $score += 2;
            }
        }
        if ( str_contains( $summary, $word ) ) {
            $score += 1;
        }
        if ( str_contains( $title, $word ) ) {
            $score += 1;
        }
    }

    return $score;
}

/**
 * Extraherar text ur en fil i GSF_DOCS_BASE.
 * Stöder .txt, .md, .pdf (via pdfparser) och .docx (via phpword).
 * Max $max_chars tecken returneras.
 *
 * @param string $filename  Relativ sökväg från GSF_DOCS_BASE, t.ex. "styrelsemöten/protokoll/2025.pdf"
 * @param int    $max_chars ~12 000 ≈ 3 000 tokens
 * @return string
 */
function gsf_extract_text( string $filename, int $max_chars = 12000 ): string {
    // Normalisera och förhindra path traversal.
    $safe = str_replace( '\\', '/', $filename );
    $safe = preg_replace( '#\.\./#', '', $safe );
    $path = GSF_DOCS_BASE . ltrim( $safe, '/' );

    if ( ! file_exists( $path ) ) {
        return '';
    }

    $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

    switch ( $ext ) {
        case 'txt':
        case 'md':
            $text = file_get_contents( $path );
            break;

        case 'pdf':
            $text = gsf_extract_pdf( $path );
            break;

        case 'docx':
            $text = gsf_extract_docx( $path );
            break;

        default:
            $text = '';
    }

    if ( $text === false || $text === null ) {
        $text = '';
    }

    return mb_substr( (string) $text, 0, $max_chars );
}

/**
 * Extraherar text ur PDF via smalot/pdfparser (om installerad via Composer).
 */
function gsf_extract_pdf( string $path ): string {
    $autoload = GSF_ASSISTANT_DIR . 'vendor/autoload.php';
    if ( ! file_exists( $autoload ) ) {
        return '';
    }
    require_once $autoload;

    if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
        return '';
    }

    try {
        $parser   = new \Smalot\PdfParser\Parser();
        $pdf      = $parser->parseFile( $path );
        return $pdf->getText();
    } catch ( \Exception $e ) {
        return '';
    }
}

/**
 * Extraherar text ur DOCX via phpoffice/phpword (om installerad via Composer).
 */
function gsf_extract_docx( string $path ): string {
    $autoload = GSF_ASSISTANT_DIR . 'vendor/autoload.php';
    if ( ! file_exists( $autoload ) ) {
        return '';
    }
    require_once $autoload;

    if ( ! class_exists( '\PhpOffice\PhpWord\IOFactory' ) ) {
        return '';
    }

    try {
        $phpWord  = \PhpOffice\PhpWord\IOFactory::load( $path );
        $text     = '';
        foreach ( $phpWord->getSections() as $section ) {
            foreach ( $section->getElements() as $element ) {
                if ( method_exists( $element, 'getText' ) ) {
                    $text .= $element->getText() . "\n";
                }
            }
        }
        return $text;
    } catch ( \Exception $e ) {
        return '';
    }
}

/**
 * Bygger kontextblock med de mest relevanta dokumenten för en given fråga.
 *
 * @param string $query
 * @return string  Tom sträng om inga relevanta dokument hittades.
 */
function gsf_build_document_context( string $query ): string {
    $index = gsf_load_index();

    if ( ! $index || empty( $index['files'] ) ) {
        return '';
    }

    // Poängsätt och sortera.
    $scored = [];
    foreach ( $index['files'] as $file ) {
        if ( empty( $file['extractable'] ) ) {
            continue; // Hoppa över bilder/kartor.
        }
        $score = gsf_score_document( $file, $query );
        if ( $score > 0 ) {
            $scored[] = [ 'file' => $file, 'score' => $score ];
        }
    }

    if ( empty( $scored ) ) {
        return '';
    }

    usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

    $top     = array_slice( $scored, 0, 3 );
    $context = "\n\nRELEVANTA DOKUMENT\n";
    $context .= str_repeat( '-', 40 ) . "\n";

    foreach ( $top as $item ) {
        $file  = $item['file'];
        $title = $file['title'] ?? $file['filename'];
        $date  = $file['date'] ?? '';
        $text  = gsf_extract_text( $file['filename'] );

        if ( $text === '' ) {
            continue;
        }

        $context .= sprintf( "\n[Dokument: %s%s]\n", $title, $date ? " ($date)" : '' );
        $context .= $text . "\n";
        $context .= str_repeat( '-', 40 ) . "\n";
    }

    return $context;
}

// ---------------------------------------------------------------------------
// AI Engine-integration
// ---------------------------------------------------------------------------

/**
 * AI Engine-hook: mwai_system_prompt
 *
 * Injicerar GSF-systemprompten och dokumentkontext.
 * Filtret får ($system_prompt, $bot_id, $session).
 *
 * Notera: Verifiera hooknamn mot AI Engine-källkod om beteendet avviker.
 * Alternativa hooks att prova: mwai_context_before_transform, mwai_ai_query.
 */
add_filter( 'mwai_system_prompt', 'gsf_inject_system_prompt', 10, 3 );

function gsf_inject_system_prompt( string $system_prompt, mixed $bot_id = null, mixed $session = null ): string {
    $has_access = gsf_user_has_access();
    $user_id    = get_current_user_id();

    error_log( sprintf(
        '[GSF] mwai_system_prompt anropad. user_id=%d, has_access=%s, bot_id=%s',
        $user_id,
        $has_access ? 'true' : 'false',
        is_scalar( $bot_id ) ? (string) $bot_id : gettype( $bot_id )
    ) );

    if ( ! $has_access ) {
        error_log( '[GSF] mwai_system_prompt: åtkomst nekad – returnerar oförändrad systempromt.' );
        return $system_prompt;
    }

    // Hämta senast inskickade meddelande för kontextsökning.
    $query = '';
    if ( isset( $_POST['message'] ) ) {
        $query = sanitize_text_field( wp_unslash( $_POST['message'] ) );
    }

    error_log( sprintf( '[GSF] mwai_system_prompt: query="%s"', mb_substr( $query, 0, 100 ) ) );

    $base    = gsf_get_system_prompt();
    $context = $query !== '' ? gsf_build_document_context( $query ) : '';

    error_log( sprintf(
        '[GSF] mwai_system_prompt: systempromt injicerad (%d tecken), kontext=%s',
        strlen( $base ),
        $context !== '' ? strlen( $context ) . ' tecken' : 'ingen'
    ) );

    return $base . $context;
}

/**
 * Fallback-hook för äldre/alternativa versioner av AI Engine.
 * mwai_ai_query ger tillgång till hela query-objektet.
 */
add_filter( 'mwai_ai_query', 'gsf_inject_via_query', 10, 1 );

function gsf_inject_via_query( mixed $query ): mixed {
    $has_access = gsf_user_has_access();
    $user_id    = get_current_user_id();

    error_log( sprintf(
        '[GSF] mwai_ai_query anropad. user_id=%d, has_access=%s, query_class=%s',
        $user_id,
        $has_access ? 'true' : 'false',
        is_object( $query ) ? get_class( $query ) : gettype( $query )
    ) );

    if ( is_object( $query ) ) {
        $props = array_keys( get_object_vars( $query ) );
        error_log( '[GSF] mwai_ai_query: tillgängliga properties: ' . implode( ', ', $props ) );
    }

    if ( ! $has_access ) {
        error_log( '[GSF] mwai_ai_query: åtkomst nekad – returnerar oförändrat query.' );
        return $query;
    }

    // Försök sätta systempromt om objektet stödjer det.
    if ( is_object( $query ) && property_exists( $query, 'instructions' ) ) {
        $user_message = '';
        if ( property_exists( $query, 'messages' ) && is_array( $query->messages ) ) {
            $last = end( $query->messages );
            if ( is_object( $last ) && property_exists( $last, 'content' ) ) {
                $user_message = (string) $last->content;
            }
        }

        error_log( sprintf( '[GSF] mwai_ai_query: user_message="%s"', mb_substr( $user_message, 0, 100 ) ) );

        $base    = gsf_get_system_prompt();
        $context = $user_message !== '' ? gsf_build_document_context( $user_message ) : '';

        if ( ! str_contains( (string) $query->instructions, 'Guldboda Samfällighetsförening' ) ) {
            $query->instructions = $base . $context;
            error_log( sprintf(
                '[GSF] mwai_ai_query: instructions satt (%d tecken), kontext=%s',
                strlen( $base ),
                $context !== '' ? strlen( $context ) . ' tecken' : 'ingen'
            ) );
        } else {
            error_log( '[GSF] mwai_ai_query: instructions redan satt av mwai_system_prompt – hoppar över.' );
        }
    } else {
        error_log( '[GSF] mwai_ai_query: query-objektet saknar property "instructions" – hooken har ingen effekt.' );
    }

    return $query;
}
