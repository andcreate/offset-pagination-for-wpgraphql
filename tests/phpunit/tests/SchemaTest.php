<?php
/**
 * Schema freeze tests.
 *
 * v1 で変えないと決めた型名とフィールド名を固定する。
 * Freezes the type and field names that v1 must not change.
 *
 * @package AndCreate\OffsetPaginationForWPGraphQL
 */

use AndCreate\OffsetPaginationForWPGraphQL\Tests\OffsetPaginationTestCase;

/**
 * スキーマ固定テスト / Schema freeze tests.
 */
class SchemaTest extends OffsetPaginationTestCase {

	/**
	 * camelCase の graphql_single_name を持つ CPT を登録する
	 * Register a CPT whose graphql_single_name is camelCase.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'rag_context',
			array(
				'public'              => true,
				'show_in_graphql'     => true,
				'graphql_single_name' => 'ragContext',
				'graphql_plural_name' => 'ragContexts',
			)
		);
		wp_set_current_user( 1 );
	}

	/**
	 * @return void
	 */
	public function tear_down() {
		unregister_post_type( 'rag_context' );
		parent::tear_down();
	}

	/**
	 * 型のフィールド名（OBJECT / INTERFACE は fields、INPUT_OBJECT は inputFields）を返す
	 * Return the field names of a type (fields for objects/interfaces, inputFields for input objects).
	 *
	 * @param string $name Type name.
	 * @return array{kind:string,fields:array<string,string>,interfaces:string[]}|null
	 */
	private function type( $name ) {
		$res  = $this->queryOk(
			'query ($name: String!) { __type(name: $name) { kind interfaces { name } fields { name type { name kind } } inputFields { name type { name kind } } } }',
			array( 'name' => $name )
		);
		$type = $res['data']['__type'];
		if ( null === $type ) {
			return null;
		}
		$fields = array();
		foreach ( array_merge( (array) $type['fields'], (array) $type['inputFields'] ) as $field ) {
			$fields[ $field['name'] ] = (string) ( $field['type']['name'] ?? $field['type']['kind'] );
		}
		return array(
			'kind'       => $type['kind'],
			'fields'     => $fields,
			'interfaces' => wp_list_pluck( (array) $type['interfaces'], 'name' ),
		);
	}

	/**
	 * input OffsetPagination { offset: Int, size: Int }
	 *
	 * @return void
	 */
	public function testOffsetPaginationInput() {
		$type = $this->type( 'OffsetPagination' );
		$this->assertSame( 'INPUT_OBJECT', $type['kind'] );
		ksort( $type['fields'] );
		$this->assertSame(
			array(
				'offset' => 'Int',
				'size'   => 'Int',
			),
			$type['fields']
		);
	}

	/**
	 * type OffsetPaginationPageInfo { total: Int, hasMore: Boolean, hasPrevious: Boolean }
	 *
	 * @return void
	 */
	public function testOffsetPaginationPageInfoObject() {
		$type = $this->type( 'OffsetPaginationPageInfo' );
		$this->assertSame( 'OBJECT', $type['kind'] );
		ksort( $type['fields'] );
		$this->assertSame(
			array(
				'hasMore'     => 'Boolean',
				'hasPrevious' => 'Boolean',
				'total'       => 'Int',
			),
			$type['fields']
		);
	}

	/**
	 * WPPageInfo.offsetPagination と、接続ごとの PageInfo への伝播
	 * WPPageInfo.offsetPagination and its propagation to per-connection PageInfo types.
	 *
	 * @return void
	 */
	public function testPageInfoFieldPropagates() {
		$this->assertSame( 'OffsetPaginationPageInfo', $this->type( 'WPPageInfo' )['fields']['offsetPagination'] );

		foreach ( array(
			'RootQueryToPostConnectionPageInfo',
			'RootQueryToPageConnectionPageInfo',
			'RootQueryToMediaItemConnectionPageInfo',
			'RootQueryToContentNodeConnectionPageInfo',
			'RootQueryToUserConnectionPageInfo',
			'RootQueryToRagContextConnectionPageInfo',
			'CategoryToPostConnectionPageInfo',
		) as $name ) {
			$type = $this->type( $name );
			$this->assertNotNull( $type, $name );
			$this->assertContains( 'WPPageInfo', $type['interfaces'], $name );
			$this->assertSame( 'OffsetPaginationPageInfo', $type['fields']['offsetPagination'] ?? null, $name );
		}
	}

	/**
	 * RootQuery の WhereArgs に offsetPagination がある（camelCase の CPT を含む）
	 * Root query WhereArgs have offsetPagination, including a camelCase CPT.
	 *
	 * @return void
	 */
	public function testRootQueryWhereArgs() {
		foreach ( array( 'Post', 'Page', 'MediaItem', 'RagContext', 'ContentNode', 'User' ) as $name ) {
			$type = $this->type( "RootQueryTo{$name}ConnectionWhereArgs" );
			$this->assertNotNull( $type, $name );
			$this->assertSame( 'OffsetPagination', $type['fields']['offsetPagination'] ?? null, $name );
		}
	}

	/**
	 * 入れ子の接続には v1 では登録しない
	 * Nested connections are not extended in v1.
	 *
	 * @return void
	 */
	public function testNestedWhereArgsAreNotExtended() {
		$type = $this->type( 'CategoryToPostConnectionWhereArgs' );
		$this->assertArrayNotHasKey( 'offsetPagination', $type['fields'] );
	}

	/**
	 * camelCase の CPT で実際に where: { offsetPagination } が通る
	 * where: { offsetPagination } works on a camelCase CPT at runtime.
	 *
	 * @return void
	 */
	public function testCamelCaseCptQuery() {
		$this->createPosts(
			3,
			array(
				'post_type'    => 'rag_context',
				'title_prefix' => 'Rag',
			)
		);

		$res = $this->queryOk( 'query { ragContexts(where: { orderby: { field: TITLE, order: ASC }, offsetPagination: { offset: 1, size: 1 } }) { nodes { title } pageInfo { offsetPagination { total hasMore hasPrevious } } } }' );
		$this->assertSame( array( 'Rag 02' ), wp_list_pluck( $res['data']['ragContexts']['nodes'], 'title' ) );
		$this->assertSame(
			array(
				'total'       => 3,
				'hasMore'     => true,
				'hasPrevious' => true,
			),
			$res['data']['ragContexts']['pageInfo']['offsetPagination']
		);
	}
}
