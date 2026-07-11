<?php
/**
 * Åtkomstkontroll – chatboten visas enbart för administrator och editor.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kontrollerar om nuvarande användare har åtkomst till GSF-assistenten.
 *
 * @return bool
 */
function gsf_user_has_access(): bool {
    return is_user_logged_in()
        && ( current_user_can( 'administrator' ) || current_user_can( 'editor' ) );
}

/**
 * Filtrar bort AI Engines chatbot-shortcode-output för ej behöriga användare.
 * Hookar in på do_shortcode_tag för att intercepta [mwai_chatbot] och [mwai_chat].
 */
add_filter( 'do_shortcode_tag', 'gsf_filter_chatbot_shortcode', 10, 4 );

function gsf_filter_chatbot_shortcode( string $output, string $tag, $attr, array $m ): string {
    // Enbart påverka AI Engine-chatbot-shortcodes.
    if ( ! in_array( $tag, [ 'mwai_chatbot', 'mwai_chat', 'mwai_chatbot_v2' ], true ) ) {
        return $output;
    }

    if ( ! gsf_user_has_access() ) {
        return ''; // Visa ingenting för icke-behöriga.
    }

    return $output;
}
