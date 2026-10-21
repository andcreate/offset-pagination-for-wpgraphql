<?php
/**
 * PHPUnit bootstrap.
 *
 * wp-env の cli コンテナにある WordPress のテストライブラリ（WP_TESTS_DIR）を使う。
 * Uses the WordPress test library shipped in the wp-env cli container (WP_TESTS_DIR).
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

$op_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $op_tests_dir ) {
	$op_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $op_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$op_tests_dir}/includes/functions.php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Polyfills と wp-graphql-testcase を読み込む / Load the polyfills and wp-graphql-testcase.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

require_once $op_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// WPGraphQL を先に、本プラグインを後に読む（本番と同じ順）
		// Load WPGraphQL first and this plugin second, the same order as production.
		require_once WP_PLUGIN_DIR . '/wp-graphql/wp-graphql.php';
		require_once dirname( __DIR__, 2 ) . '/offset-pagination-for-wpgraphql.php';
	}
);

require $op_tests_dir . '/includes/bootstrap.php';
