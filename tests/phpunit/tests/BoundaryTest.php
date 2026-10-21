<?php
/**
 * Boundary behaviour tests.
 *
 * v1 は上流の挙動を変えない。ここではその挙動を固定する。
 * v1 keeps the upstream behaviour unchanged; these tests freeze it.
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

use AndCreate\OffsetPaginationForWPGraphQL\Tests\OffsetPaginationTestCase;

/**
 * 境界のテスト / Boundary tests.
 */
class BoundaryTest extends OffsetPaginationTestCase {

	const PAGE_INFO = 'pageInfo { offsetPagination { total hasMore hasPrevious } }';

	/**
	 * posts を offsetPagination 付きで流す
	 * Query posts with the given offsetPagination literal.
	 *
	 * @param string $op    offsetPagination input literal, e.g. "{offset: 0, size: 5}".
	 * @param string $extra Extra where args.
	 * @return array<string,mixed> The posts connection.
	 */
	private function posts( $op, $extra = '' ) {
		$res = $this->queryOk(
			'query { posts(where: { orderby: { field: TITLE, order: ASC }, offsetPagination: ' . $op . $extra . ' }) { nodes { title } ' . self::PAGE_INFO . ' } }'
		);
		return $res['data']['posts'];
	}

	/**
	 * offset が総数と同じ: 0 件、hasMore false、total は 0（WP_Query は空の結果では found_posts を数えない）
	 * offset equal to the total: no nodes, hasMore false, total 0 (WP_Query skips found_posts for empty results).
	 *
	 * @return void
	 */
	public function testOffsetEqualToTotal() {
		$this->createPosts( 8 );

		$posts = $this->posts( '{offset: 8, size: 6}' );
		$this->assertSame( array(), $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 0,
				'hasMore'     => false,
				'hasPrevious' => true,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * offset が総数より大きい / offset greater than the total.
	 *
	 * @return void
	 */
	public function testOffsetBeyondTotal() {
		$this->createPosts( 8 );

		$posts = $this->posts( '{offset: 100, size: 6}' );
		$this->assertSame( array(), $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 0,
				'hasMore'     => false,
				'hasPrevious' => true,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * 最後のページがちょうど埋まる: hasMore false
	 * The last page is exactly full: hasMore false.
	 *
	 * @return void
	 */
	public function testLastPageExactlyFull() {
		$this->createPosts( 12 );

		$posts = $this->posts( '{offset: 6, size: 6}' );
		$this->assertCount( 6, $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 12,
				'hasMore'     => false,
				'hasPrevious' => true,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * size: 0 / size: 0.
	 *
	 * @return void
	 */
	public function testSizeZero() {
		$this->createPosts( 8 );

		$posts = $this->posts( '{offset: 0, size: 0}' );
		$this->assertSame( array(), $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 8,
				'hasMore'     => true,
				'hasPrevious' => false,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * size 省略: 0 件を返す（size 0 扱い。本番でも確認済み）
	 * size omitted: returns no nodes (treated as size 0, also observed in production).
	 *
	 * @return void
	 */
	public function testSizeOmitted() {
		$this->createPosts( 8 );

		$posts = $this->posts( '{offset: 2}' );
		$this->assertSame( array(), $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 8,
				'hasMore'     => true,
				'hasPrevious' => true,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * offset 省略: 先頭から size 件
	 * offset omitted: the first `size` nodes.
	 *
	 * @return void
	 */
	public function testOffsetOmitted() {
		$this->createPosts( 8 );

		$posts = $this->posts( '{size: 3}' );
		$this->assertSame( array( 'Post 01', 'Post 02', 'Post 03' ), wp_list_pluck( $posts['nodes'], 'title' ) );
		$this->assertFalse( $posts['pageInfo']['offsetPagination']['hasPrevious'] );
	}

	/**
	 * size > 100: WPGraphQL の上限 100 件で切られ、graphql_debug が出る
	 * size > 100: capped at WPGraphQL's maximum of 100 with a graphql_debug message.
	 *
	 * 取得は size+1 件なので、101 件以上あっても hasMore は「size より多いか」で決まる（上流の挙動）
	 * The query fetches size+1 rows, so hasMore compares against size, not the cap (upstream behaviour).
	 *
	 * @return void
	 */
	public function testSizeOverMaxIsCappedAt100() {
		$this->createPosts( 105 );

		$res   = $this->graphql(
			array(
				'query' => 'query { posts(where: { offsetPagination: { offset: 0, size: 150 } }) { nodes { databaseId } ' . self::PAGE_INFO . ' } }',
			)
		);
		$posts = $res['data']['posts'];

		$this->assertCount( 100, $posts['nodes'] );
		$this->assertSame(
			array(
				'total'       => 105,
				'hasMore'     => false,
				'hasPrevious' => false,
			),
			$posts['pageInfo']['offsetPagination']
		);

		$messages = wp_list_pluck( $res['extensions']['debug'] ?? array(), 'message' );
		$this->assertContains(
			'The number of items requested by the connection (150) exceeds the max query amount. Only the first 100 items will be returned.',
			$messages
		);
	}

	/**
	 * categoryId と offsetPagination の併用
	 * offsetPagination combined with categoryId.
	 *
	 * @return void
	 */
	public function testCombinedWithCategoryId() {
		$cat = self::factory()->category->create( array( 'slug' => 'work' ) );
		$this->createPosts( 5 );
		$this->createPosts(
			3,
			array(
				'title_prefix'  => 'Work',
				'post_category' => array( $cat ),
			)
		);

		$posts = $this->posts( '{offset: 1, size: 1}', ', categoryId: ' . $cat );
		$this->assertSame( array( 'Work 02' ), wp_list_pluck( $posts['nodes'], 'title' ) );
		$this->assertSame(
			array(
				'total'       => 3,
				'hasMore'     => true,
				'hasPrevious' => true,
			),
			$posts['pageInfo']['offsetPagination']
		);
	}

	/**
	 * total を選ばないとき no_found_rows は true のまま、選んだときだけ false になる
	 * no_found_rows stays true unless total is selected.
	 *
	 * @return void
	 */
	public function testNoFoundRowsOnlyWhenTotalSelected() {
		$this->createPosts( 3 );

		$seen = array();
		$spy  = static function ( $query ) use ( &$seen ) {
			if ( 'post' === $query->get( 'post_type' ) || array( 'post' ) === $query->get( 'post_type' ) ) {
				$seen[] = $query->get( 'no_found_rows' );
			}
		};
		add_action( 'pre_get_posts', $spy );

		$this->queryOk( 'query { posts(where: { offsetPagination: { size: 2 } }) { nodes { title } pageInfo { offsetPagination { hasMore hasPrevious } } } }' );
		$this->queryOk( 'query { posts(where: { offsetPagination: { size: 2 } }) { nodes { title } } }' );
		$this->queryOk( 'query { posts(where: { offsetPagination: { size: 2 } }) { nodes { title } pageInfo { offsetPagination { total } } } }' );

		remove_action( 'pre_get_posts', $spy );
		$this->assertSame( array( true, true, false ), $seen );
	}

	/**
	 * ユーザー接続でも total を選んだときだけ count_total が true になる
	 * For user connections count_total is enabled only when total is selected.
	 *
	 * @return void
	 */
	public function testUserCountTotalOnlyWhenTotalSelected() {
		wp_set_current_user( 1 );
		self::factory()->user->create_many( 3 );

		$seen = array();
		$spy  = static function ( $query ) use ( &$seen ) {
			// 接続のクエリだけを見る（size 2 なので number は 3） / Only the connection query (size 2 → number 3).
			if ( 3 === (int) $query->get( 'number' ) ) {
				$seen[] = (bool) $query->get( 'count_total' );
			}
		};
		add_action( 'pre_get_users', $spy );

		// 1 回の GraphQL 実行で WP_User_Query が複数回走ることがあるので、実行ごとにまとめて見る
		// A single GraphQL execution may run WP_User_Query more than once, so check per execution.
		$this->queryOk( 'query { users(where: { offsetPagination: { size: 2 } }) { nodes { name } pageInfo { offsetPagination { hasMore } } } }' );
		$without = $seen;
		$seen    = array();
		$this->queryOk( 'query { users(where: { offsetPagination: { size: 2 } }) { nodes { name } pageInfo { offsetPagination { total } } } }' );
		$with = $seen;

		remove_action( 'pre_get_users', $spy );
		$this->assertNotEmpty( $without );
		$this->assertNotContains( true, $without );
		$this->assertContains( true, $with );
	}
}
