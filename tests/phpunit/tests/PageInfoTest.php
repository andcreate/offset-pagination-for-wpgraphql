<?php
// 上流 tests/wpunit/PageInfoTest.php の移植。WPGraphQL 2.x と PHP 8 への対応以外、テストの中身は変えていない
// Ported from upstream tests/wpunit/PageInfoTest.php. Test bodies are unchanged except for WPGraphQL 2.x / PHP 8 compatibility.

class PageInfoTest extends \AndCreate\OffsetPaginationForWPGraphQL\Tests\OffsetPaginationTestCase
{
    public function set_up()
    {
        parent::set_up();

        register_post_type('test_cpt', [
            'show_ui' => true,
            'labels' => [
                'menu_name' => __('Docs', 'your-textdomain'),
            ],
            'supports' => ['title'],
            'show_in_graphql' => true,
            'hierarchical' => true,
            'graphql_single_name' => 'testCpt',
            'graphql_plural_name' => 'testCpts',
        ]);
    }

    public function tear_down()
    {
        parent::tear_down();
        \WPGraphQL::clear_schema();
    }

    function createPosts($count, $args = [])
    {
        $title_prefix = 'Post';

        if (isset($args['title_prefix'])) {
            $title_prefix = $args['title_prefix'];
            unset($args['title_prefix']);
        }

        foreach (range(1, $count) as $number) {
            $number = str_pad($number, 2, '0', STR_PAD_LEFT);
            self::factory()->post->create(
                array_merge(
                    [
                        'post_title' => "$title_prefix $number",
                    ],
                    $args
                )
            );
        }
    }

    public function testHasMoreTrueWithoutOffset()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5}
                }) {
                pageInfo {
                    offsetPagination {
                        hasMore
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $has_more =
            $res['data']['posts']['pageInfo']['offsetPagination']['hasMore'];

        $this->assertEquals(true, $has_more);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 01',
            'Post 02',
            'Post 03',
            'Post 04',
            'Post 05',
        ]);
    }

    public function testHasMoreTrueOneBeforeEnd()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5, offset: 4}
                }) {
                pageInfo {
                    offsetPagination {
                        hasMore
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 05',
            'Post 06',
            'Post 07',
            'Post 08',
            'Post 09',
        ]);

        $has_more =
            $res['data']['posts']['pageInfo']['offsetPagination']['hasMore'];
        $this->assertEquals(true, $has_more);
    }

    public function testHasMoreFalseOnEnd()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5, offset: 5}
                }) {
                pageInfo {
                    offsetPagination {
                        hasMore
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 06',
            'Post 07',
            'Post 08',
            'Post 09',
            'Post 10',
        ]);

        $has_more =
            $res['data']['posts']['pageInfo']['offsetPagination']['hasMore'];
        $this->assertEquals(false, $has_more);
    }

    public function testHasMoreFalsePastEnd()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5, offset: 7}
                }) {
                pageInfo {
                    offsetPagination {
                        hasMore
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, ['Post 08', 'Post 09', 'Post 10']);

        $has_more =
            $res['data']['posts']['pageInfo']['offsetPagination']['hasMore'];
        $this->assertEquals(false, $has_more);
    }

    public function testHasPreviousFalseWithoutOffset()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5}
                }) {
                pageInfo {
                    offsetPagination {
                        hasPrevious
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $has_previous =
            $res['data']['posts']['pageInfo']['offsetPagination'][
                'hasPrevious'
            ];
        $this->assertEquals(false, $has_previous);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 01',
            'Post 02',
            'Post 03',
            'Post 04',
            'Post 05',
        ]);
    }

    public function testHasPreviousFalseWithZeroOffset()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5, offset: 0}
                }) {
                pageInfo {
                    offsetPagination {
                        hasPrevious
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $has_previous =
            $res['data']['posts']['pageInfo']['offsetPagination'][
                'hasPrevious'
            ];
        $this->assertEquals(false, $has_previous);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 01',
            'Post 02',
            'Post 03',
            'Post 04',
            'Post 05',
        ]);
    }

    public function testHasPreviousTrueWithOffsetOne()
    {
        $this->createPosts(10);

        $res = graphql([
            'query' => '
            query Posts {
                posts(where: {
                    orderby: {field: TITLE, order: ASC},
                    offsetPagination: {size: 5, offset: 1}
                }) {
                pageInfo {
                    offsetPagination {
                        hasPrevious
                    }
                }
                nodes {
                    title
                  }
                }
            }
        ',
        ]);

        $has_previous =
            $res['data']['posts']['pageInfo']['offsetPagination'][
                'hasPrevious'
            ];
        $this->assertEquals(true, $has_previous);

        $nodes = $res['data']['posts']['nodes'];
        $titles = \wp_list_pluck($nodes, 'title');
        $this->assertEquals($titles, [
            'Post 02',
            'Post 03',
            'Post 04',
            'Post 05',
            'Post 06',
        ]);
    }
}
