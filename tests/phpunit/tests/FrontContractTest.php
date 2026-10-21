<?php
/**
 * Front-end contract tests.
 *
 * 実運用のフロントが流しているクエリ（data/front-contract-queries.json）をそのまま実行し、返る形と値を固定する。
 * Runs the queries used by a production front-end (data/front-contract-queries.json) verbatim and freezes the result.
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

use AndCreate\OffsetPaginationForWPGraphQL\Tests\OffsetPaginationTestCase;

/**
 * フロント契約テスト / Front-end contract tests.
 */
class FrontContractTest extends OffsetPaginationTestCase {

	/**
	 * 契約クエリ / Contract queries.
	 *
	 * @var array<string,mixed>
	 */
	private $q;

	/**
	 * Category IDs by slug.
	 *
	 * @var array<string,int>
	 */
	private $cat = array();

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->q = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/data/front-contract-queries.json' ), true );
	}

	/**
	 * 本番ベースラインと同じ構成を作る: 記事 $count 件すべてが information、新しい方から $work 件が work
	 * Build the production-baseline layout: all $count posts in "information", the oldest $work posts also in "work".
	 *
	 * @param int $count Number of posts.
	 * @param int $work  Number of posts also in "work".
	 * @return void
	 */
	private function createBaseline( $count = 8, $work = 2 ) {
		$this->cat['information'] = self::factory()->category->create(
			array(
				'slug' => 'information',
				'name' => 'Information',
			)
		);
		$this->cat['work']        = self::factory()->category->create(
			array(
				'slug' => 'work',
				'name' => 'Work',
			)
		);

		for ( $i = 1; $i <= $count; $i++ ) {
			$cats = array( $this->cat['information'] );
			if ( $i <= $work ) {
				$cats[] = $this->cat['work'];
			}
			self::factory()->post->create(
				array(
					'post_title'    => sprintf( 'Post %02d', $i ),
					'post_status'   => 'publish',
					'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 10:00:00' ) + $i * 3600 ),
					'post_category' => $cats,
				)
			);
		}
	}

	/**
	 * @param string $slug Category slug.
	 * @return int
	 */
	private function categoryIdBySlug( $slug ) {
		$res = $this->queryOk( $this->q['categoryIdBySlug'], array( 'id' => $slug ) );
		return $res['data']['category']['databaseId'];
	}

	/**
	 * @param array<string,mixed> $posts Connection result.
	 * @return string[]
	 */
	private function titles( $posts ) {
		return array_map(
			static function ( $edge ) {
				return $edge['node']['title'];
			},
			$posts['edges']
		);
	}

	/**
	 * `list` 1 ページ目と 2 ページ目（size 6、記事 8 件）
	 * `list` page 1 and page 2 (size 6, 8 posts).
	 *
	 * @return void
	 */
	public function testListPagesMatchBaseline() {
		$this->createBaseline();
		$size = $this->q['sizePerPage'];

		$p1 = $this->queryOk( $this->q['list'], array( 'offsetPagination' => array( 'offset' => 0, 'size' => $size ) ) );
		$this->assertSame( array( 'Post 08', 'Post 07', 'Post 06', 'Post 05', 'Post 04', 'Post 03' ), $this->titles( $p1['data']['posts'] ) );
		$this->assertSame( array( 'offsetPagination' => array( 'total' => 8 ) ), $p1['data']['posts']['pageInfo'] );

		$p2 = $this->queryOk( $this->q['list'], array( 'offsetPagination' => array( 'offset' => $size, 'size' => $size ) ) );
		$this->assertSame( array( 'Post 02', 'Post 01' ), $this->titles( $p2['data']['posts'] ) );
		$this->assertSame( array( 'offsetPagination' => array( 'total' => 8 ) ), $p2['data']['posts']['pageInfo'] );
	}

	/**
	 * `list` のノードが持つフィールドの形（カテゴリの入れ子を含む）
	 * Shape of the `list` node fields, including nested categories.
	 *
	 * @return void
	 */
	public function testListNodeShape() {
		$this->createBaseline();

		$res  = $this->queryOk( $this->q['list'], array( 'offsetPagination' => array( 'offset' => 6, 'size' => 6 ) ) );
		$node = $res['data']['posts']['edges'][1]['node'];

		$this->assertSame( array( 'id', 'title', 'slug', 'date', 'modified', 'excerpt', 'featuredImage', 'categories' ), array_keys( $node ) );
		$this->assertNull( $node['featuredImage'] );
		$slugs = wp_list_pluck( wp_list_pluck( $node['categories']['edges'], 'node' ), 'slug' );
		sort( $slugs );
		$this->assertSame( array( 'information', 'work' ), $slugs );
	}

	/**
	 * `listByCategory` を information と work で流す
	 * `listByCategory` for "information" and "work".
	 *
	 * @return void
	 */
	public function testListByCategoryMatchesBaseline() {
		$this->createBaseline();
		$size = $this->q['sizePerPage'];

		$info = $this->queryOk(
			$this->q['listByCategory'],
			array(
				'offsetPagination' => array( 'offset' => 0, 'size' => $size ),
				'categoryId'       => $this->categoryIdBySlug( 'information' ),
			)
		);
		$this->assertCount( 6, $info['data']['posts']['edges'] );
		$this->assertSame( 8, $info['data']['posts']['pageInfo']['offsetPagination']['total'] );

		$work = $this->queryOk(
			$this->q['listByCategory'],
			array(
				'offsetPagination' => array( 'offset' => 0, 'size' => $size ),
				'categoryId'       => $this->categoryIdBySlug( 'work' ),
			)
		);
		$this->assertSame( array( 'Post 02', 'Post 01' ), $this->titles( $work['data']['posts'] ) );
		$this->assertSame( 2, $work['data']['posts']['pageInfo']['offsetPagination']['total'] );
	}

	/**
	 * `allCategorySlugList`: where 無し・入れ子の接続でも total が返る
	 * `allCategorySlugList`: total is returned on a nested connection without where args.
	 *
	 * @return void
	 */
	public function testAllCategorySlugListReturnsNestedTotals() {
		$this->createBaseline();

		$res    = $this->queryOk( $this->q['allCategorySlugList'] );
		$totals = array();
		foreach ( $res['data']['categories']['edges'] as $edge ) {
			$totals[ $edge['node']['slug'] ] = $edge['node']['posts']['pageInfo']['offsetPagination']['total'];
		}

		// テスト用の install には消せない uncategorized があるので、契約の 2 つだけを見る
		// The test install has an undeletable "uncategorized" term, so only check the two contract slugs.
		$this->assertSame( 8, $totals['information'] );
		$this->assertSame( 2, $totals['work'] );
	}

	/**
	 * `total`: where 無しでも total が返る
	 * `total`: total is returned without where args.
	 *
	 * @return void
	 */
	public function testTotalWithoutWhereArgs() {
		$this->createBaseline();

		$res = $this->queryOk( $this->q['total'] );
		$this->assertSame( array( 'posts' => array( 'pageInfo' => array( 'offsetPagination' => array( 'total' => 8 ) ) ) ), $res['data'] );
	}

	/**
	 * 件数を変えた構成（15 件・work 7 件）: 6 / 6 / 3 件に分かれる
	 * A larger layout (15 posts, 7 in "work"): pages of 6 / 6 / 3.
	 *
	 * @return void
	 */
	public function testLargerLayoutPagesAndTotals() {
		$this->createBaseline( 15, 7 );

		$counts = array();
		foreach ( array( 0, 6, 12 ) as $offset ) {
			$res      = $this->queryOk( $this->q['list'], array( 'offsetPagination' => array( 'offset' => $offset, 'size' => 6 ) ) );
			$counts[] = count( $res['data']['posts']['edges'] );
			$this->assertSame( 15, $res['data']['posts']['pageInfo']['offsetPagination']['total'] );
		}
		$this->assertSame( array( 6, 6, 3 ), $counts );

		$work = $this->queryOk(
			$this->q['listByCategory'],
			array(
				'offsetPagination' => array( 'offset' => 6, 'size' => 6 ),
				'categoryId'       => $this->categoryIdBySlug( 'work' ),
			)
		);
		$this->assertSame( array( 'Post 01' ), $this->titles( $work['data']['posts'] ) );
		$this->assertSame( 7, $work['data']['posts']['pageInfo']['offsetPagination']['total'] );

		$res = $this->queryOk( $this->q['total'] );
		$this->assertSame( 15, $res['data']['posts']['pageInfo']['offsetPagination']['total'] );
	}

	/**
	 * 記事 0 件: total は 0、ノードは空
	 * No posts: total is 0 and there are no nodes.
	 *
	 * @return void
	 */
	public function testEmptySite() {
		$res = $this->queryOk( $this->q['list'], array( 'offsetPagination' => array( 'offset' => 0, 'size' => 6 ) ) );
		$this->assertSame( array(), $res['data']['posts']['edges'] );
		$this->assertSame( 0, $res['data']['posts']['pageInfo']['offsetPagination']['total'] );

		$res = $this->queryOk( $this->q['total'] );
		$this->assertSame( 0, $res['data']['posts']['pageInfo']['offsetPagination']['total'] );
	}
}
