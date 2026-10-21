<?php
/**
 * Offset pagination for WPGraphQL connections.
 *
 * Originally written by Esa-Matti Suuronen, Valu Digital Oy.
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

namespace AndCreate\OffsetPaginationForWPGraphQL;

use WPGraphQL\Data\Connection\AbstractConnectionResolver;
use WPGraphQL\Data\Connection\UserConnectionResolver;

/**
 * フックを登録し、offsetPagination の入力と pageInfo を提供する
 * Registers hooks that provide the offsetPagination input and pageInfo.
 */
class Loader {

	/**
	 * 上流版の Loader クラス名 / Class name of the upstream plugin's loader.
	 */
	const UPSTREAM_LOADER_CLASS = 'WPGraphQL\Extensions\OffsetPagination\Loader';

	/**
	 * プラグインを初期化する。plugins_loaded で呼ばれる
	 * Initialize the plugin. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		// 上流版が有効なら型が二重に登録されるので、フックを登録せずに通知だけ出す
		// If the upstream plugin is active the types would be registered twice, so only show a notice.
		if ( self::is_upstream_active() ) {
			add_action( 'admin_notices', array( self::class, 'render_upstream_notice' ) );
			return;
		}

		( new Loader() )->bind_hooks();
	}

	/**
	 * 上流版（valu-digital/wp-graphql-offset-pagination）が読み込まれているか
	 * Whether the upstream plugin (valu-digital/wp-graphql-offset-pagination) has been loaded.
	 *
	 * 上流は読み込み時に Loader::init() を呼んで定数を定義する。autoload はしない
	 * （Composer に入っているだけで初期化されていない上流は、型を登録しないので対象外）。
	 * The upstream plugin calls Loader::init() and defines its constant at load time. Autoloading is
	 * skipped on purpose: an upstream package that is only autoloadable but never initialized registers nothing.
	 *
	 * @return bool
	 */
	public static function is_upstream_active() {
		return defined( 'WP_GRAPHQL_OFFSET_PAGINATION' ) || class_exists( self::UPSTREAM_LOADER_CLASS, false );
	}

	/**
	 * 上流版と同時に有効になっていることを管理画面に知らせる
	 * Tell administrators that the upstream plugin is active at the same time.
	 *
	 * @return void
	 */
	public static function render_upstream_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Offset Pagination for WPGraphQL is inactive because the original "WPGraphQL Offset Pagination" plugin is active. Deactivate one of them.',
				'offset-pagination-for-wpgraphql'
			)
		);
	}

	/**
	 * WPGraphQL のフィルターとアクションを登録する
	 * Register WPGraphQL filters and actions.
	 *
	 * @return void
	 */
	public function bind_hooks() {
		add_action(
			'graphql_register_types',
			array( $this, 'op_action_register_types' ),
			9,
			0
		);

		add_filter(
			'graphql_map_input_fields_to_wp_query',
			array( $this, 'op_filter_map_offset_to_wp_query_args' ),
			10,
			2
		);

		add_filter(
			'graphql_map_input_fields_to_wp_user_query',
			array( $this, 'op_filter_map_offset_to_wp_user_query_args' ),
			10,
			2
		);

		add_filter(
			'graphql_connection_page_info',
			array( $this, 'op_filter_graphql_connection_page_info' ),
			10,
			2
		);

		add_filter(
			'graphql_connection_query_args',
			array( $this, 'op_filter_graphql_connection_query_args' ),
			10,
			5
		);

		add_filter(
			'graphql_connection_amount_requested',
			array( $this, 'op_filter_graphql_connection_amount_requested' ),
			10,
			2
		);
	}

	/**
	 * offsetPagination のときは size を取得件数にする
	 * Use the page size as the requested amount for offset pagination.
	 *
	 * @param int                        $amount   Requested amount.
	 * @param AbstractConnectionResolver $resolver Connection resolver.
	 * @return int
	 */
	public function op_filter_graphql_connection_amount_requested( $amount, $resolver ) {
		if ( self::is_offset_resolver( $resolver ) ) {
			return self::get_page_size( $resolver );
		}

		return $amount;
	}

	/**
	 * where.offsetPagination.size を返す（無ければ 0）
	 * Returns where.offsetPagination.size (0 when missing).
	 *
	 * @param AbstractConnectionResolver $resolver Connection resolver.
	 * @return int
	 */
	public static function get_page_size( AbstractConnectionResolver $resolver ) {
		$args = $resolver->get_args();
		return intval( $args['where']['offsetPagination']['size'] ?? 0 );
	}

	/**
	 * offsetPagination を指定された接続か
	 * Whether the connection is resolving offset pagination.
	 *
	 * @param AbstractConnectionResolver $resolver Connection resolver.
	 * @return bool
	 */
	public static function is_offset_resolver( AbstractConnectionResolver $resolver ) {
		$args = $resolver->get_args();
		return isset( $args['where']['offsetPagination'] );
	}

	/**
	 * total が選択されたときだけ総数を数える
	 * Lazily enable total calculations only when they are asked in the selection set.
	 *
	 * @param array<string,mixed>        $query_args Query args.
	 * @param AbstractConnectionResolver $resolver   Connection resolver.
	 * @return array<string,mixed>
	 */
	public function op_filter_graphql_connection_query_args(
		$query_args,
		AbstractConnectionResolver $resolver
	) {
		$info          = $resolver->get_info();
		$selection_set = $info->getFieldSelection( 2 );

		if ( ! isset( $selection_set['pageInfo']['offsetPagination']['total'] ) ) {
			// total を求めていなければ何もしない / Get out if not requesting total counting.
			return $query_args;
		}

		if ( $resolver instanceof UserConnectionResolver ) {
			// ユーザー接続の総数を数える（遅い） / Enable slow total counting for user connections.
			$query_args['count_total'] = true;
		} else {
			// 投稿接続の総数を数える（遅い） / Enable slow total counting for post connections.
			$query_args['no_found_rows'] = false;
		}

		return $query_args;
	}

	/**
	 * 投稿タイプの RootQuery 接続に offsetPagination の入力を足す
	 * Add the offsetPagination input to a post type's root query connection.
	 *
	 * @param \WP_Post_Type $post_type_object Post type object.
	 * @return void
	 */
	public static function add_post_type_fields( \WP_Post_Type $post_type_object ) {
		$type = ucfirst( $post_type_object->graphql_single_name );
		register_graphql_fields(
			"RootQueryTo{$type}ConnectionWhereArgs",
			array(
				'offsetPagination' => array(
					'type'        => 'OffsetPagination',
					'description' => "Paginate {$type}s with offsets",
				),
			)
		);
	}

	/**
	 * pageInfo.offsetPagination の値を組み立てる
	 * Build the pageInfo.offsetPagination values.
	 *
	 * @param array<string,mixed>        $page_info Page info.
	 * @param AbstractConnectionResolver $resolver  Connection resolver.
	 * @return array<string,mixed>
	 */
	public function op_filter_graphql_connection_page_info(
		$page_info,
		AbstractConnectionResolver $resolver
	) {
		$size   = self::get_page_size( $resolver );
		$query  = $resolver->get_query();
		$args   = $resolver->get_args();
		$offset = $args['where']['offsetPagination']['offset'] ?? 0;

		$total = null;

		if ( $query instanceof \WP_Query ) {
			$total = $query->found_posts;
		} elseif ( $query instanceof \WP_User_Query ) {
			$total = $query->total_users;
		}

		$page_info['offsetPagination'] = array(
			'total'       => $total,
			'hasMore'     => count( $resolver->get_ids() ) > $size,
			'hasPrevious' => $offset > 0,
		);
		return $page_info;
	}

	/**
	 * offsetPagination を WP_Query の引数に写す
	 * Map offsetPagination to WP_Query args.
	 *
	 * @param array<string,mixed> $query_args Query args.
	 * @param array<string,mixed> $where_args Where args.
	 * @return array<string,mixed>
	 */
	public function op_filter_map_offset_to_wp_query_args(
		array $query_args,
		array $where_args
	) {
		if ( isset( $where_args['offsetPagination']['offset'] ) ) {
			$query_args['offset'] = $where_args['offsetPagination']['offset'];
		}

		if ( isset( $where_args['offsetPagination']['size'] ) ) {
			// hasMore を総数なしで判定するため size+1 件を取る
			// Fetch size+1 to be able calculate "hasMore" field without slowly counting full totals.
			$query_args['posts_per_page'] =
				intval( $where_args['offsetPagination']['size'] ) + 1;
		}

		return $query_args;
	}

	/**
	 * offsetPagination を WP_User_Query の引数に写す
	 * Map offsetPagination to WP_User_Query args.
	 *
	 * @param array<string,mixed> $query_args Query args.
	 * @param array<string,mixed> $where_args Where args.
	 * @return array<string,mixed>
	 */
	public function op_filter_map_offset_to_wp_user_query_args(
		array $query_args,
		array $where_args
	) {
		if ( isset( $where_args['offsetPagination']['offset'] ) ) {
			$query_args['offset'] = $where_args['offsetPagination']['offset'];
		}

		if ( isset( $where_args['offsetPagination']['size'] ) ) {
			$query_args['number'] =
				intval( $where_args['offsetPagination']['size'] ) + 1;
		}

		return $query_args;
	}

	/**
	 * GraphQL の型とフィールドを登録する
	 * Register GraphQL types and fields.
	 *
	 * @return void
	 */
	public function op_action_register_types() {
		foreach ( \WPGraphQL::get_allowed_post_types() as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( $post_type_object instanceof \WP_Post_Type ) {
				self::add_post_type_fields( $post_type_object );
			}
		}

		register_graphql_object_type(
			'OffsetPaginationPageInfo',
			array(
				'description' => __(
					'Get information about the offset pagination state',
					'offset-pagination-for-wpgraphql'
				),
				'fields'      => array(
					'total'       => array(
						'type'        => 'Int',
						'description' => __(
							'Total amount of nodes in this connection',
							'offset-pagination-for-wpgraphql'
						),
					),
					'hasMore'     => array(
						'type'        => 'Boolean',
						'description' => __(
							'True if there is one or more nodes available in this connection. Eg. you can increase the offset at least by one.',
							'offset-pagination-for-wpgraphql'
						),
					),
					'hasPrevious' => array(
						'type'        => 'Boolean',
						'description' => __(
							'True when offset can be decresed eg. offset is 0<',
							'offset-pagination-for-wpgraphql'
						),
					),
				),
			)
		);

		register_graphql_field(
			'WPPageInfo',
			'offsetPagination',
			array(
				'type'        => 'OffsetPaginationPageInfo',
				'description' => __(
					'Get information about the offset pagination state in the current connection',
					'offset-pagination-for-wpgraphql'
				),
			)
		);

		register_graphql_input_type(
			'OffsetPagination',
			array(
				'description' => __(
					'Offset pagination input type',
					'offset-pagination-for-wpgraphql'
				),
				'fields'      => array(
					'size'   => array(
						'type'        => 'Int',
						'description' => __(
							'Number of post to show per page. Passed to posts_per_page of WP_Query.',
							'offset-pagination-for-wpgraphql'
						),
					),
					'offset' => array(
						'type'        => 'Int',
						'description' => __(
							'Number of post to show per page. Passed to posts_per_page of WP_Query.',
							'offset-pagination-for-wpgraphql'
						),
					),
				),
			)
		);

		register_graphql_field(
			'RootQueryToContentNodeConnectionWhereArgs',
			'offsetPagination',
			array(
				'type'        => 'OffsetPagination',
				'description' => 'Paginate content nodes with offsets',
			)
		);

		register_graphql_field(
			'RootQueryToUserConnectionWhereArgs',
			'offsetPagination',
			array(
				'type'        => 'OffsetPagination',
				'description' => 'Paginate users with offsets',
			)
		);
	}
}
