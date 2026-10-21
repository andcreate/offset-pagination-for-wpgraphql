<?php
/**
 * 共存ガードの状態を調べる。`wp eval-file check.php <expect>` で新しいプロセスから呼ぶ
 * Inspect the coexistence guard state. Run in a fresh process: `wp eval-file check.php <expect>`.
 *
 * expect:
 *   fork-only — 本プラグインだけが有効 / only this plugin is active
 *   both      — 上流版も有効。本プラグインはフックを登録せず通知を出す / upstream is also active; this plugin stays idle and shows a notice
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.GlobalVariablesOverride.Prohibited

$opfw_expect = $args[0] ?? '';
$opfw_errors = array();

// 本プラグインのフックが graphql_register_types に載っているか
// Whether this plugin's callback is attached to graphql_register_types.
$opfw_fork_hooked = false;
global $wp_filter;
if ( isset( $wp_filter['graphql_register_types'] ) ) {
	foreach ( $wp_filter['graphql_register_types']->callbacks as $opfw_callbacks ) {
		foreach ( $opfw_callbacks as $opfw_cb ) {
			if ( is_array( $opfw_cb['function'] ) && $opfw_cb['function'][0] instanceof \AndCreate\OffsetPaginationForWPGraphQL\Loader ) {
				$opfw_fork_hooked = true;
			}
		}
	}
}

// 管理画面の通知 / Admin notice.
wp_set_current_user( 1 );
ob_start();
do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
$opfw_notice = false !== strpos( (string) ob_get_clean(), 'Offset Pagination for WPGraphQL is inactive' );

// GraphQL が型の二重登録なしで動くか / GraphQL works without duplicate type registration.
$opfw_res      = graphql( array( 'query' => '{ posts(where: { offsetPagination: { offset: 0, size: 1 } }) { nodes { databaseId } pageInfo { offsetPagination { total hasMore hasPrevious } } } }' ) );
$opfw_messages = wp_list_pluck( $opfw_res['extensions']['debug'] ?? array(), 'message' );
$opfw_dupes    = array_values(
	array_filter(
		$opfw_messages,
		static function ( $m ) {
			return false !== stripos( (string) $m, 'duplicate' ) || false !== stripos( (string) $m, 'already' );
		}
	)
);

$opfw_state = array(
	'expect'         => $opfw_expect,
	'upstream_const' => defined( 'WP_GRAPHQL_OFFSET_PAGINATION' ),
	'fork_loaded'    => defined( 'OFFSET_PAGINATION_FOR_WPGRAPHQL_VERSION' ),
	'fork_hooked'    => $opfw_fork_hooked,
	'notice'         => $opfw_notice,
	'graphql_errors' => $opfw_res['errors'] ?? null,
	'total_is_int'   => is_int( $opfw_res['data']['posts']['pageInfo']['offsetPagination']['total'] ?? null ),
	'duplicate_msgs' => $opfw_dupes,
);

$opfw_want = array(
	'fork-only' => array(
		'upstream_const' => false,
		'fork_loaded'    => true,
		'fork_hooked'    => true,
		'notice'         => false,
	),
	'both'      => array(
		'upstream_const' => true,
		'fork_loaded'    => true,
		'fork_hooked'    => false,
		'notice'         => true,
	),
);

if ( ! isset( $opfw_want[ $opfw_expect ] ) ) {
	$opfw_errors[] = "unknown expect: {$opfw_expect}";
} else {
	foreach ( $opfw_want[ $opfw_expect ] as $opfw_key => $opfw_value ) {
		if ( $opfw_state[ $opfw_key ] !== $opfw_value ) {
			$opfw_errors[] = "{$opfw_key} should be " . var_export( $opfw_value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}
}
if ( null !== $opfw_state['graphql_errors'] ) {
	$opfw_errors[] = 'graphql returned errors';
}
if ( ! $opfw_state['total_is_int'] ) {
	$opfw_errors[] = 'total is not an int';
}
if ( array() !== $opfw_dupes ) {
	$opfw_errors[] = 'duplicate registration messages found';
}

echo wp_json_encode( $opfw_state ) . PHP_EOL;
if ( array() !== $opfw_errors ) {
	echo 'FAIL: ' . implode( '; ', $opfw_errors ) . PHP_EOL;
	exit( 1 );
}
echo 'PASS' . PHP_EOL;
