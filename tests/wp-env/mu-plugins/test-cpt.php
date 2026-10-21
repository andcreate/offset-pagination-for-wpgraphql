<?php
/**
 * Plugin Name: Offset Pagination test fixtures
 * Description: wp-env 用のテスト専用 mu-plugin。camelCase の graphql_single_name を持つ CPT を登録する。
 *              Test-only mu-plugin for wp-env. Registers a CPT whose graphql_single_name is camelCase.
 */

add_action(
    'init',
    function () {
        // graphql_single_name が camelCase のとき WhereArgs の型名が合うかを確かめるための CPT
        // CPT used to check that WhereArgs type names match when graphql_single_name is camelCase
        register_post_type('rag_context', [
            'label' => 'RAG Contexts',
            'public' => true,
            'show_in_graphql' => true,
            'graphql_single_name' => 'ragContext',
            'graphql_plural_name' => 'ragContexts',
        ]);
    }
);
