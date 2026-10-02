<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;

// Repo root without booting the app — these generators are plain PHP.
defined('ROOT') || define('ROOT', dirname(__DIR__, 3));

use function inv_gen_routes_from_source;

require_once ROOT.'/scripts/inventory/lib/util.php';
require_once ROOT.'/scripts/inventory/lib/routes.php';

/**
 * The static route parser against the rails-mini fixture: the DSL subset the
 * real config/routes*.rb uses. Everything this produces is provisional by
 * construction — these tests pin the parser, not the live Rails table.
 */
class RouteParserTest extends TestCase
{
    private static string $railsMini = '';

    public static function setUpBeforeClass(): void
    {
        self::$railsMini = ROOT.'/tests/Unit/Inventory/fixtures/rails-mini';
    }

    public function test_namespaced_resources_honour_only_and_param(): void
    {
        $rows = $this->rows();

        $this->assertSame('api/v1/customers#index', $rows['rest:GET:/api/v1/customers']['handler']);
        $this->assertSame('api/v1/customers#create', $rows['rest:POST:/api/v1/customers']['handler']);
        $this->assertSame('api/v1/customers#show', $rows['rest:GET:/api/v1/customers/:external_id']['handler']);
        $this->assertSame('api/v1/customers#destroy', $rows['rest:DELETE:/api/v1/customers/:external_id']['handler']);

        // only: %i[create index show destroy] — no new/edit/update.
        $this->assertArrayNotHasKey('rest:GET:/api/v1/customers/new', $rows);
        $this->assertArrayNotHasKey('rest:PATCH:/api/v1/customers/:external_id', $rows);
    }

    public function test_bare_get_inside_resources_is_a_member_route(): void
    {
        $row = $this->rows()['rest:GET:/api/v1/customers/:external_id/portal_url'];

        $this->assertSame('api/v1/customers#portal_url', $row['handler']);
        $this->assertSame('member', $row['on']);
    }

    public function test_scope_module_nests_controllers_but_not_paths(): void
    {
        $row = $this->rows()['rest:GET:/api/v1/customers/:external_id/invoices'];

        $this->assertSame('api/v1/customers/invoices#index', $row['handler']);
    }

    public function test_explicit_to_overrides_controller(): void
    {
        $this->assertSame(
            'api/v1/organizations#show',
            $this->rows()['rest:GET:/api/v1/organizations']['handler']
        );
    }

    public function test_empty_resources_block_with_on_collection_routes(): void
    {
        $row = $this->rows()['rest:POST:/api/v1/webhooks/stripe/:organization_id'];

        $this->assertSame('api/v1/webhooks#stripe', $row['handler']);
        $this->assertSame('collection', $row['on']);
    }

    public function test_top_level_literal_routes(): void
    {
        $rows = $this->rows();

        $this->assertSame('graphql#execute', $rows['rest:POST:/graphql']['handler']);
        $this->assertSame('application#health', $rows['rest:GET:/health']['handler']);
    }

    public function test_every_row_is_marked_provisional(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertTrue($row['provisional'], $row['id'].' must be provisional');
        }
    }

    public function test_rows_are_sorted_and_unique(): void
    {
        $rows = inv_gen_routes_from_source(self::$railsMini);
        $ids = array_column($rows, 'id');
        $sorted = $ids;

        sort($sorted);

        $this->assertSame($sorted, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    private function rows(): array
    {
        static $rows = null;

        return $rows ??= array_column(inv_gen_routes_from_source(self::$railsMini), null, 'id');
    }
}
