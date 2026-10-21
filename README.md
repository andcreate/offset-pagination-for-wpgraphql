# Offset Pagination for WPGraphQL

Adds traditional offset pagination (`offset` / `size`) to WPGraphQL connections.

This is a maintained fork of [WPGraphQL Offset Pagination](https://github.com/valu-digital/wp-graphql-offset-pagination)
by Esa-Matti Suuronen, Valu Digital Oy, updated and tested for WPGraphQL 2.x.
The GraphQL schema and the pagination behaviour are the same as the original plugin,
so existing queries keep working without changes.

Offset pagination is useful when you need:

-   Numbered links to "pages"
-   Ordering with custom SQL (see the original [tutorial](docs/tutorial.md))

The WPGraphQL cursors are faster. Prefer them when you do not need the features above.

## Requirements

| | Version |
|---|---|
| WordPress | 6.5 or later |
| PHP | 7.4 or later |
| WPGraphQL | 2.0.0 or later (tested up to 2.25.0) |

CI runs the test suite with WPGraphQL 2.0.0 and 2.25.0 on PHP 7.4 and 8.3 with the latest WordPress,
plus WordPress 6.5 with WPGraphQL 2.0.0 on PHP 7.4.

## Usage

```graphql
query Posts {
    posts(where: { offsetPagination: { size: 10, offset: 10 } }) {
        pageInfo {
            offsetPagination {
                # True when there are more nodes after this page.
                hasMore

                # True when offset > 0.
                hasPrevious

                # Total number of nodes in the connection. Selecting this field
                # enables total counting, which makes the query slower.
                total
            }
        }
        nodes {
            title
        }
    }
}
```

The `where: { offsetPagination }` argument is available on the root query connections of every
post type exposed to GraphQL (built-in and custom, e.g. `posts`, `pages`, `mediaItems`, `myCustomTypes`),
on `contentNodes` and on `users`.

`pageInfo.offsetPagination` is available on every connection whose page info implements `WPPageInfo`,
including nested ones such as `category { posts { pageInfo { offsetPagination { total } } } }`.
`total` is returned for post and user connections (`WP_Query` / `WP_User_Query`) even without the
`offsetPagination` input, and is `null` for other connections such as terms.

### Behaviour worth knowing

These are the original plugin's behaviours. This version keeps them on purpose and the test suite freezes them.

-   **`size` omitted**: treated as `size: 0`, so no nodes are returned. Always pass `size`.
-   **`size: 0`**: returns no nodes. `hasMore` is `true` when the connection has any node.
-   **`size` over 100**: WPGraphQL caps a connection at 100 nodes (`graphql_connection_max_query_amount`),
    so only 100 nodes are returned and a `graphql_debug` message is added. `hasMore` is computed
    against `size`, not against the cap, so it can be `false` even though more than 100 nodes exist.
    The database query still asks for `size + 1` rows, so large `size` values make the query heavier.
-   **`offset` at or past the end**: no nodes, `hasMore: false`, and `total` is `0` because WordPress
    does not count rows for an empty result page.
-   **Nested connections**: `where: { offsetPagination }` is only on root query connections.
    `category { posts(where: { offsetPagination: ... }) }` is a GraphQL error.

## Installation

Download the plugin from the [releases](https://github.com/andcreate/offset-pagination-for-wpgraphql/releases)
or clone it into `wp-content/plugins/offset-pagination-for-wpgraphql`, then activate it.
The plugin has no runtime Composer dependencies.

## Migrating from WPGraphQL Offset Pagination

The schema is identical, so front-end queries do not change.

1. Back up the site and record the responses of the queries you depend on.
2. Upload this plugin, **deactivate** "WPGraphQL Offset Pagination", then activate this plugin.
3. Run the same queries again and compare the responses with step 1.
4. Update WPGraphQL if needed and compare again.
5. Keep the original plugin deactivated for a while before deleting it.

To roll back, deactivate this plugin and activate the original one again.

If both plugins are active at the same time, this plugin registers nothing and shows an admin notice,
so the GraphQL types are never registered twice and the original plugin keeps working.

## Differences from the original plugin

-   Name, slug, text domain, PHP namespace (`AndCreate\OffsetPaginationForWPGraphQL`) and the version
    constant (`OFFSET_PAGINATION_FOR_WPGRAPHQL_VERSION`) are new, so both plugins can be installed side by side.
-   Initializes on `plugins_loaded` and stays inactive when the original plugin is active.
-   Adds the `Requires Plugins`, `Requires WPGraphQL` and `WPGraphQL tested up to` headers, so WPGraphQL
    no longer reports it as an untested extension.
-   No bundled Composer autoloader; the plugin class is loaded directly.
-   New test suite (PHPUnit on wp-env), PHPStan, PHPCS and GitHub Actions.

## Development

Requirements: Docker, Node.js 18.12+ and Bash.

```bash
# Test environment: WPGraphQL 2.25.0 and the original plugin for the coexistence checks
npx -y @wordpress/env@12.0.0 start --config=.wp-env.test.json
npx -y @wordpress/env@12.0.0 run cli --config=.wp-env.test.json --env-cwd=wp-content/plugins/offset-pagination-for-wpgraphql composer install

# PHPUnit (a separate table prefix keeps the test install away from the site tables)
npx -y @wordpress/env@12.0.0 run cli --config=.wp-env.test.json --env-cwd=wp-content/plugins/offset-pagination-for-wpgraphql env WORDPRESS_TABLE_PREFIX=wptests_ vendor/bin/phpunit

# Coexistence with the original plugin, in both activation orders
bash tests/coexistence/run.sh

# Static analysis (host PHP)
composer install
composer phpstan
composer phpcs
```

To test another WPGraphQL or PHP version, create `.wp-env.test.override.json`
(it replaces the `plugins` list, so list all three plugins):

```json
{
    "phpVersion": "7.4",
    "plugins": [
        "https://downloads.wordpress.org/plugin/wp-graphql.2.0.0.zip",
        ".",
        "valu-digital/wp-graphql-offset-pagination#db85d9b676377f4ddda96859f56e14c1e32dd0ce"
    ]
}
```

`.wp-env.json` is a separate environment for manual checks on port 8888.

The original Codeception setup is kept in `archive/upstream-dev/` for reference.

## Credits

Originally written by Esa-Matti Suuronen, Valu Digital Oy, as
[WPGraphQL Offset Pagination](https://github.com/valu-digital/wp-graphql-offset-pagination),
itself a reimplementation of [darylldoyle/wp-graphql-offset-pagination](https://github.com/darylldoyle/wp-graphql-offset-pagination)
by Daryll Doyle.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
