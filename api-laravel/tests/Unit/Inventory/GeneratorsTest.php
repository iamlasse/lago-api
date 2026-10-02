<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;

// Repo root without booting the app — these generators are plain PHP.
defined('ROOT') || define('ROOT', dirname(__DIR__, 3));

use function inv_gen_jobs;
use function inv_gen_tables;
use function inv_gen_graphql;
use function inv_gen_services;
use function inv_gen_serializers;

require_once ROOT.'/scripts/inventory/lib/util.php';
require_once ROOT.'/scripts/inventory/lib/graphql.php';
require_once ROOT.'/scripts/inventory/lib/serializers.php';
require_once ROOT.'/scripts/inventory/lib/jobs.php';
require_once ROOT.'/scripts/inventory/lib/services.php';
require_once ROOT.'/scripts/inventory/lib/tables.php';

/**
 * Generator mechanics against the committed rails-mini fixture (no database,
 * no app boot needed).
 */
class GeneratorsTest extends TestCase
{
    private static string $railsMini = '';

    public static function setUpBeforeClass(): void
    {
        self::$railsMini = ROOT.'/tests/Unit/Inventory/fixtures/rails-mini';
    }

    public function test_graphql_walks_query_and_mutation_roots(): void
    {
        $rows = inv_gen_graphql(self::$railsMini);

        $byId = array_column($rows, null, 'id');

        $this->assertSame(
            ['gql:mutation:loginUser', 'gql:query:currentUser', 'gql:query:organization'],
            array_column($rows, 'id'),
            'rows are sorted by id across both roots'
        );

        $this->assertSame('query', $byId['gql:query:organization']['kind']);
        $this->assertSame('User!', $byId['gql:query:currentUser']['return']);
        $this->assertSame('ID', $byId['gql:query:currentUser']['args'][0]['type']);
        $this->assertTrue($byId['gql:mutation:loginUser']['deprecated']);
    }

    public function test_serializers_use_dotted_names_and_source_paths(): void
    {
        $rows = inv_gen_serializers(self::$railsMini);

        $this->assertSame([
            ['id' => 'ser:ModelSerializer', 'name' => 'ModelSerializer', 'source' => 'app/serializers/model_serializer.rb'],
            ['id' => 'ser:V1.CustomerSerializer', 'name' => 'V1::CustomerSerializer', 'source' => 'app/serializers/v1/customer_serializer.rb'],
        ], $rows);
    }

    public function test_jobs_capture_queue_and_unique_and_retry_stanzas(): void
    {
        $rows = inv_gen_jobs(self::$railsMini);

        $byId = array_column($rows, null, 'id');

        $this->assertSame('(dynamic)', $byId['job:BillSubscriptionJob']['queue']);
        $this->assertSame(
            ['unique :until_executed, on_conflict: :log, lock_ttl: 12.hours'],
            $byId['job:BillSubscriptionJob']['unique']
        );
        $this->assertStringContainsString(
            'retry_on Sequenced::SequenceError',
            $byId['job:BillSubscriptionJob']['retry_on'][0] ?? ''
        );

        $this->assertSame('clock', $byId['job:Clock.SubscriptionsBillerJob']['queue']);
        $this->assertSame('Clock::SubscriptionsBillerJob', $byId['job:Clock.SubscriptionsBillerJob']['name']);
    }

    public function test_services_use_dotted_namespaces(): void
    {
        $rows = inv_gen_services(self::$railsMini);

        $this->assertSame([
            ['id' => 'svc:Invoices.CalculateFeesService', 'name' => 'Invoices::CalculateFeesService', 'source' => 'app/services/invoices/calculate_fees_service.rb'],
            ['id' => 'svc:Subscriptions.OrganizationBillingService', 'name' => 'Subscriptions::OrganizationBillingService', 'source' => 'app/services/subscriptions/organization_billing_service.rb'],
            ['id' => 'svc:Support.FrozenSql', 'name' => 'Support::FrozenSql', 'source' => 'app/services/support/frozen_sql.rb'],
        ], $rows);
    }

    public function test_tables_count_columns_and_hash_the_ordered_list(): void
    {
        $rows = inv_gen_tables(self::$railsMini);

        $byId = array_column($rows, null, 'id');

        $this->assertSame(
            ['table:customers', 'table:invoice_subscriptions'],
            array_column($rows, 'id'),
            'PARTITION OF blocks without their own CREATE body are excluded'
        );

        $customers = $byId['table:customers'];

        $this->assertSame(5, $customers['columns']);
        $this->assertSame(md5('id,external_id,metadata,created_at,note'), $customers['columns_hash']);
    }

    public function test_generators_are_deterministic(): void
    {
        $this->assertSame(
            inv_gen_graphql(self::$railsMini),
            inv_gen_graphql(self::$railsMini)
        );

        $this->assertSame(
            inv_gen_jobs(self::$railsMini),
            inv_gen_jobs(self::$railsMini)
        );
    }
}
