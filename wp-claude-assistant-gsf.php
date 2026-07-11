<?php
/**
 * Plugin Name: GSF Styrelseassistent
 * Plugin URI:  https://github.com/FredrikArvas/wp-claude-assistant-gsf
 * Description: Anpassar AI Engine för Guldboda Samfällighetsförenings styrelseassistent.
 *              Injicerar systempromt, hämtar dokumentkontext och begränsar åtkomst till admin/editor.
 * Version:     1.0.0
 * Author:      Fredrik Arvas
 * Author URI:  https://guldboda.se
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: gsf-assistant
 */

defined( 'ABSPATH' ) || exit;

define( 'GSF_ASSISTANT_VERSION', '1.0.0' );
define( 'GSF_ASSISTANT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'GSF_ASSISTANT_URL',     plugin_dir_url( __FILE__ ) );

require_once GSF_ASSISTANT_DIR . 'includes/system-prompt.php';
require_once GSF_ASSISTANT_DIR . 'includes/access-control.php';
require_once GSF_ASSISTANT_DIR . 'includes/document-context.php';
