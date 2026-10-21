<?php
/**
 * Base test case.
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

namespace AndCreate\OffsetPaginationForWPGraphQL\Tests;

use Tests\WPGraphQL\TestCase\WPGraphQLUnitTestCase;

/**
 * テスト共通の下準備とヘルパー
 * Shared setup and helpers for the tests.
 */
abstract class OffsetPaginationTestCase extends WPGraphQLUnitTestCase {

	/**
	 * 各テストの後にスキーマを捨てる（上流テストと同じ）
	 * Clear the schema after each test (same as the upstream tests).
	 *
	 * @return void
	 */
	public function tear_down() {
		parent::tear_down();
		\WPGraphQL::clear_schema();
	}

	/**
	 * 「Post 01」「Post 02」… の題名で投稿を作る
	 * Create posts titled "Post 01", "Post 02", ...
	 *
	 * @param int                 $count Number of posts.
	 * @param array<string,mixed> $args  Extra post args. `title_prefix` changes the title prefix.
	 * @return int[] Created post IDs.
	 */
	protected function createPosts( $count, $args = array() ) {
		$title_prefix = 'Post';

		if ( isset( $args['title_prefix'] ) ) {
			$title_prefix = $args['title_prefix'];
			unset( $args['title_prefix'] );
		}

		$ids = array();
		foreach ( range( 1, $count ) as $number ) {
			$number = str_pad( (string) $number, 2, '0', STR_PAD_LEFT );
			$ids[]  = self::factory()->post->create(
				array_merge(
					array(
						'post_title' => "$title_prefix $number",
					),
					$args
				)
			);
		}
		return $ids;
	}

	/**
	 * GraphQL を実行し、エラーが無いことを確かめて結果を返す
	 * Run a GraphQL query, assert it has no errors and return the result.
	 *
	 * @param string              $query     GraphQL query.
	 * @param array<string,mixed> $variables Variables.
	 * @return array<string,mixed>
	 */
	protected function queryOk( $query, $variables = array() ) {
		$res = $this->graphql(
			array(
				'query'     => $query,
				'variables' => $variables,
			)
		);
		$this->assertArrayNotHasKey( 'errors', $res, print_r( $res, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		return $res;
	}
}
