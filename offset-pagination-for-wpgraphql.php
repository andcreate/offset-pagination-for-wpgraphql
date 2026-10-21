<?php
/**
 * Plugin Name:           Offset Pagination for WPGraphQL
 * Plugin URI:            https://github.com/andcreate/offset-pagination-for-wpgraphql
 * Description:           Adds offset pagination to WPGraphQL connections.
 * Author:                AndCreate, Esa-Matti Suuronen, Valu Digital Oy
 * Version:               1.0.0
 * Requires at least:     6.5
 * Requires PHP:          7.4
 * Requires Plugins:      wp-graphql
 * Requires WPGraphQL:    2.0.0
 * WPGraphQL tested up to: 2.25.0
 * License:               GPL-2.0-or-later
 * License URI:           https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:           offset-pagination-for-wpgraphql
 *
 * Forked from WPGraphQL Offset Pagination by Esa-Matti Suuronen, Valu Digital Oy
 * (https://github.com/valu-digital/wp-graphql-offset-pagination).
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

// 直接アクセスを防ぐ / Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OFFSET_PAGINATION_FOR_WPGRAPHQL_VERSION', '1.0.0' );

// Composer の autoload は使わず、本体のクラスは直接読み込む
// Load the plugin class directly instead of relying on the Composer autoloader.
require_once __DIR__ . '/src/Loader.php';

// 上流版との共存を確かめるため、全プラグインの読み込み後に初期化する
// Initialize after all plugins are loaded so the upstream plugin can be detected.
add_action( 'plugins_loaded', array( \AndCreate\OffsetPaginationForWPGraphQL\Loader::class, 'init' ) );
