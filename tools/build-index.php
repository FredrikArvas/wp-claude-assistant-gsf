#!/usr/bin/env php
<?php
/**
 * build-index.php – bygger/uppdaterar docs/index.json
 *
 * Kör från pluginets rotkatalog:
 *   php tools/build-index.php
 *
 * Kräver: ANTHROPIC_API_KEY som miljövariabel eller i .env-fil i rotkatalogen.
 * Kräver: composer install (vendor/autoload.php)
 */

define( 'GSF_TOOL_ROOT', dirname( __DIR__ ) );

// ---------------------------------------------------------------------------
// Autoload + .env
// ---------------------------------------------------------------------------

$autoload = GSF_TOOL_ROOT . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// Läs .env om den finns.
$env_file = GSF_TOOL_ROOT . '/.env';
if ( file_exists( $env_file ) ) {
    foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $line = trim( $line );
        if ( $line === '' || str_starts_with( $line, '#' ) ) {
            continue;
        }
        if ( str_contains( $line, '=' ) ) {
            [ $key, $value ] = explode( '=', $line, 2 );
            putenv( trim( $key ) . '=' . trim( $value, " \t\n\r\0\x0B\"'" ) );
        }
    }
}

$api_key = getenv( 'ANTHROPIC_API_KEY' );
if ( ! $api_key ) {
    fwrite( STDERR, "Fel: ANTHROPIC_API_KEY saknas. Sätt miljövariabeln eller lägg den i .env.\n" );
    exit( 1 );
}

// ---------------------------------------------------------------------------
// Hjälpfunktioner
// ---------------------------------------------------------------------------

function extract_text_from_file( string $path ): string {
    $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

    switch ( $ext ) {
        case 'txt':
        case 'md':
            return (string) file_get_contents( $path );

        case 'pdf':
            if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf    = $parser->parseFile( $path );
                    return $pdf->getText();
                } catch ( Exception $e ) {
                    echo "  Varning: kunde inte läsa PDF ({$e->getMessage()})\n";
                }
            } else {
                echo "  Varning: smalot/pdfparser inte installerad – hoppar över textextraktion.\n";
            }
            return '';

        case 'docx':
            if ( class_exists( '\PhpOffice\PhpWord\IOFactory' ) ) {
                try {
                    $phpWord = \PhpOffice\PhpWord\IOFactory::load( $path );
                    $text    = '';
                    foreach ( $phpWord->getSections() as $section ) {
                        foreach ( $section->getElements() as $element ) {
                            if ( method_exists( $element, 'getText' ) ) {
                                $text .= $element->getText() . "\n";
                            }
                        }
                    }
                    return $text;
                } catch ( Exception $e ) {
                    echo "  Varning: kunde inte läsa DOCX ({$e->getMessage()})\n";
                }
            } else {
                echo "  Varning: phpoffice/phpword inte installerad – hoppar över textextraktion.\n";
            }
            return '';

        case 'xlsx':
            if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
                    $text        = '';
                    foreach ( $spreadsheet->getAllSheets() as $sheet ) {
                        $text .= $sheet->getTitle() . ":\n";
                        foreach ( $sheet->toArray() as $row ) {
                            $text .= implode( "\t", array_filter( $row, fn( $c ) => $c !== null && $c !== '' ) ) . "\n";
                        }
                    }
                    return $text;
                } catch ( Exception $e ) {
                    echo "  Varning: kunde inte läsa XLSX ({$e->getMessage()})\n";
                }
            } else {
                echo "  Varning: phpoffice/phpspreadsheet inte installerad – hoppar över textextraktion.\n";
            }
            return '';

        default:
            return '';
    }
}

/**
 * Anropar Claude API för att generera titel, nyckelord och sammanfattning.
 *
 * @return array{title: string, keywords: list<string>, summary: string}
 */
function generate_metadata( string $api_key, string $filename, string $text ): array {
    $is_image    = in_array( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'png', 'gif' ], true );
    $content_hint = $is_image
        ? "Filen är en bild/karta med filnamnet: $filename"
        : "Filnamn: $filename\n\nInhållsutdrag (max 4 000 tecken):\n" . mb_substr( $text, 0, 4000 );

    $prompt = <<<PROMPT
Du är en dokumentindexerare för Guldboda Samfällighetsförenings arkiv.
Analysera nedanstående dokument och returnera ENBART ett JSON-objekt (ingen annan text) med dessa fält:
- "title": kort beskrivande titel på svenska (max 60 tecken)
- "keywords": lista med 4–8 relevanta nyckelord på svenska (gemener)
- "summary": en mening på svenska som sammanfattar dokumentets innehåll (max 160 tecken)

{$content_hint}
PROMPT;

    $payload = json_encode( [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 300,
        'messages'   => [
            [ 'role' => 'user', 'content' => $prompt ],
        ],
    ] );

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ] );

    $response = curl_exec( $ch );
    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );

    if ( $http_code !== 200 || ! $response ) {
        echo "  Varning: API-anrop misslyckades (HTTP $http_code).\n";
        return default_metadata( $filename );
    }

    $data = json_decode( $response, true );
    $raw  = $data['content'][0]['text'] ?? '';

    // Extrahera JSON ur svaret.
    if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
        $meta = json_decode( $m[0], true );
        if ( is_array( $meta ) && isset( $meta['title'], $meta['keywords'], $meta['summary'] ) ) {
            return [
                'title'    => (string) $meta['title'],
                'keywords' => array_values( array_map( 'strval', (array) $meta['keywords'] ) ),
                'summary'  => (string) $meta['summary'],
            ];
        }
    }

    echo "  Varning: kunde inte tolka API-svar. Använder standardvärden.\n";
    return default_metadata( $filename );
}

/** @return array{title: string, keywords: list<string>, summary: string} */
function default_metadata( string $filename ): array {
    return [
        'title'    => pathinfo( $filename, PATHINFO_FILENAME ),
        'keywords' => [],
        'summary'  => '',
    ];
}

function is_extractable( string $filename ): bool {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    return in_array( $ext, [ 'pdf', 'docx', 'xlsx', 'txt', 'md' ], true );
}

function is_supported( string $filename ): bool {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    return in_array( $ext, [ 'pdf', 'docx', 'xlsx', 'txt', 'md', 'jpg', 'jpeg', 'png', 'gif' ], true );
}

// ---------------------------------------------------------------------------
// Huvud­logik
// ---------------------------------------------------------------------------

// --docs-dir=<absolut sökväg>  Katalog att indexera (standard: plugin/docs/).
// Index skrivs alltid till plugin/docs/index.json.
$opts     = getopt( '', [ 'docs-dir:' ] );
$docs_dir = isset( $opts['docs-dir'] ) ? rtrim( $opts['docs-dir'], '/\\' ) : GSF_TOOL_ROOT . '/docs';
$index_path = GSF_TOOL_ROOT . '/docs/index.json';

echo "Indexerar: $docs_dir\n";
echo "Index:     $index_path\n\n";

// Läs befintligt index.
$existing_index  = [];
$existing_by_name = [];
if ( file_exists( $index_path ) ) {
    $data = json_decode( (string) file_get_contents( $index_path ), true );
    if ( is_array( $data ) && ! empty( $data['files'] ) ) {
        foreach ( $data['files'] as $entry ) {
            $existing_by_name[ $entry['filename'] ] = $entry;
        }
    }
}

// Skanna docs/-katalogen rekursivt (inkl. alla undermappar).
$files_in_docs = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $docs_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ( $iterator as $item ) {
    if ( $item->isDir() ) {
        continue;
    }
    $filepath = $item->getPathname();
    // Relativ sökväg från docs/-roten (t.ex. "subdir/fil.pdf").
    $relname  = ltrim( str_replace( $docs_dir, '', $filepath ), '/\\' );
    // Normalisera separatorer till forward slash.
    $relname  = str_replace( '\\', '/', $relname );
    if ( in_array( $relname, [ 'index.json', '.gitkeep' ], true ) ) {
        continue;
    }
    if ( ! is_supported( $item->getFilename() ) ) {
        continue;
    }
    $files_in_docs[ $relname ] = $filepath;
}

ksort( $files_in_docs );

$new_entries = [];

foreach ( $files_in_docs as $relname => $filepath ) {
    $filename = $relname;  // Relativ sökväg används som unik nyckel.
    $mtime    = date( 'Y-m-d', filemtime( $filepath ) );

    // Återanvänd befintlig post om filen inte ändrats.
    if ( isset( $existing_by_name[ $filename ] ) ) {
        $existing = $existing_by_name[ $filename ];
        $existing_mtime = $existing['_mtime'] ?? '';
        if ( $existing_mtime === $mtime ) {
            echo "Oförändrad: $filename\n";
            $new_entries[] = $existing;
            continue;
        }
    }

    echo "Indexerar: $filename\n";

    $basename = basename( $filepath );
    $text = is_extractable( $basename ) ? extract_text_from_file( $filepath ) : '';
    $meta = generate_metadata( $api_key, $basename, $text );

    $entry = [
        'filename'    => $filename,
        'title'       => $meta['title'],
        'type'        => strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) ),
        'date'        => $mtime,
        'keywords'    => $meta['keywords'],
        'summary'     => $meta['summary'],
        'extractable' => is_extractable( $basename ),
        '_mtime'      => $mtime,  // Intern, för ändringsdetektering.
    ];

    $new_entries[] = $entry;
}

$output = [
    'updated' => date( 'Y-m-d' ),
    'files'   => $new_entries,
];

file_put_contents( $index_path, json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

echo "\nKlart! " . count( $new_entries ) . " poster skrivna till docs/index.json\n";
