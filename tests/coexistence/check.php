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

$op_expect = $args[0] ?? '';
$op_errors = array();

// 本プラグインのフックが graphql_register_types に載っているか
// Whether this plugin's callback is attached to graphql_register_types.
$op_fork_hooked = false;
global $wp_filter;
if ( isset( $wp_filter['graphql_register_types'] ) ) {
	foreach ( $wp_filter['graphql_register_types']->callbacks as $op_callbacks ) {
		foreach ( $op_callbacks as $op_cb ) {
			if ( is_array( $op_cb['function'] ) && $op_cb['function'][0] instanceof \AndCreate\OffsetPaginationForWPGraphQL\Loader ) {
				$op_fork_hooked = true;
			}
		}
	}
}

// 管理画面の通知 / Admin notice.
wp_set_current_user( 1 );
ob_start();
do_action( 'admin_notices' );
$op_notice = false !== strpos( (string) ob_get_clean(), 'Offset Pagination for WPGraphQL is inactive' );

// GraphQL が型の二重登録なしで動くか / GraphQL works without duplicate type registration.
$op_res      = graphql( array( 'query' => '{ posts(where: { offsetPagination: { offset: 0, size: 1 } }) { nodes { databaseId } pageInfo { offsetPagination { total hasMore hasPrevious } } } }' ) );
$op_messages = wp_list_pluck( $op_res['extensions']['debug'] ?? array(), 'message' );
$op_dupes    = array_values(
	array_filter(
		$op_messages,
		static function ( $m ) {
			return false !== stripos( (string) $m, 'duplicate' ) || false !== stripos( (string) $m, 'already' );
		}
	)
);

$op_state = array(
	'expect'         => $op_expect,
	'upstream_const' => defined( 'WP_GRAPHQL_OFFSET_PAGINATION' ),
	'fork_loaded'    => defined( 'OFFSET_PAGINATION_FOR_WPGRAPHQL_VERSION' ),
	'fork_hooked'    => $op_fork_hooked,
	'notice'         => $op_notice,
	'graphql_errors' => $op_res['errors'] ?? null,
	'total_is_int'   => is_int( $op_res['data']['posts']['pageInfo']['offsetPagination']['total'] ?? null ),
	'duplicate_msgs' => $op_dupes,
);

$op_want = array(
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

if ( ! isset( $op_want[ $op_expect ] ) ) {
	$op_errors[] = "unknown expect: {$op_expect}";
} else {
	foreach ( $op_want[ $op_expect ] as $op_key => $op_value ) {
		if ( $op_state[ $op_key ] !== $op_value ) {
			$op_errors[] = "{$op_key} should be " . var_export( $op_value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}
}
if ( null !== $op_state['graphql_errors'] ) {
	$op_errors[] = 'graphql returned errors';
}
if ( ! $op_state['total_is_int'] ) {
	$op_errors[] = 'total is not an int';
}
if ( array() !== $op_dupes ) {
	$op_errors[] = 'duplicate registration messages found';
}

echo wp_json_encode( $op_state ) . PHP_EOL;
if ( array() !== $op_errors ) {
	echo 'FAIL: ' . implode( '; ', $op_errors ) . PHP_EOL;
	exit( 1 );
}
echo 'PASS' . PHP_EOL;
