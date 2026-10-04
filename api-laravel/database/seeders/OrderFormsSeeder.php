<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' db/seeds/70_order_forms.rb: quotes, quote versions, quote
 * owners and order forms, written directly to the frozen tables (no
 * Quote/QuoteVersion/OrderForm models in the port yet).
 *
 * Upstream invariant: order forms only exist on APPROVED quote versions —
 * each order form gets a fresh dedicated quote/version, never the draft
 * quotes seeded below.
 *
 * NOTE: the frozen `quotes` table has no current_version_id column, so the
 * upstream `quote.update!(current_version: …)` calls have no equivalent —
 * dropped.
 */
class OrderFormsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $customer = Customer::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', 'cust_john-doe')->firstOrFail();

        $this->createQuoteChain($organization, $customer);
        $this->createDraftQuoteForEachCustomer($organization);

        foreach ([
            ['cust_john-doe', 'generated', null],
            ['cust_1', 'generated', null],
            ['cust_2', 'signed', null],
            ['cust_3', 'expired', null],
            ['cust_4', 'voided', null],
            ['cust_5', 'generated', now()->addDays(7)],
        ] as [$externalId, $status, $expiresAt]) {
            $quoteCustomer = Customer::query()
                ->where('organization_id', $organization->id)
                ->where('external_id', $externalId)->firstOrFail();

            $this->createApprovedQuoteWithOrderForm($organization, $quoteCustomer, $status, $expiresAt);
        }
    }

    private function createQuoteChain(Organization $organization, Customer $customer): void
    {
        $quoteId = $this->createQuote($organization->id, $customer->id, 'subscription_creation');

        $owners = User::query()->whereIn('email', ['gavin@hooli.com', 'dinesh@hooli.com'])->get();
        foreach ($owners as $user) {
            DB::table('quote_owners')->insertOrIgnore([
                'organization_id' => $organization->id,
                'quote_id' => $quoteId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $versionsCount = 3;
        for ($version = 1; $version <= $versionsCount; $version++) {
            $lastVersion = $version === $versionsCount;
            $this->createQuoteVersion(
                $organization->id,
                $quoteId,
                $lastVersion ? 'draft' : 'voided',
                $lastVersion ? null : 'manual',
                $lastVersion ? null : now(),
            );
        }
    }

    private function createDraftQuoteForEachCustomer(Organization $organization): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $customer = Customer::query()
                ->where('organization_id', $organization->id)
                ->where('external_id', "cust_{$i}")->firstOrFail();
            $quoteId = $this->createQuote($organization->id, $customer->id, 'one_off');
            $this->createQuoteVersion($organization->id, $quoteId, 'draft');
        }
    }

    private function createApprovedQuoteWithOrderForm(Organization $organization, Customer $customer, string $status, ?\Carbon\CarbonInterface $expiresAt): string
    {
        $quoteId = $this->createQuote($organization->id, $customer->id, 'one_off');
        $quoteVersionId = $this->createQuoteVersion($organization->id, $quoteId, 'approved', approvedAt: now());
        $this->createOrderForm($organization->id, $customer->id, $quoteVersionId, $status, $expiresAt);

        return $quoteVersionId;
    }

    private function createQuote(string $organizationId, string $customerId, string $orderType): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $sequentialId = (int) DB::table('quotes')->where('organization_id', $organizationId)->max('sequential_id') + 1;

        DB::table('quotes')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'order_type' => $orderType,
            'number' => sprintf('QT-%s-%04d', now()->year, $sequentialId),
            'sequential_id' => $sequentialId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createQuoteVersion(string $organizationId, string $quoteId, string $status, ?string $voidReason = null, ?\Carbon\CarbonInterface $voidedAt = null, ?\Carbon\CarbonInterface $approvedAt = null): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $sequentialId = (int) DB::table('quote_versions')->where('quote_id', $quoteId)->max('sequential_id') + 1;

        DB::table('quote_versions')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'quote_id' => $quoteId,
            'sequential_id' => $sequentialId,
            'status' => $status,
            'void_reason' => $voidReason,
            'voided_at' => $voidedAt,
            'approved_at' => $approvedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createOrderForm(string $organizationId, string $customerId, string $quoteVersionId, string $status, ?\Carbon\CarbonInterface $expiresAt): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $sequentialId = (int) DB::table('order_forms')->where('organization_id', $organizationId)->max('sequential_id') + 1;

        DB::table('order_forms')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'quote_version_id' => $quoteVersionId,
            'number' => sprintf('OF-%s-%04d', now()->year, $sequentialId),
            'sequential_id' => $sequentialId,
            'status' => $status,
            'expires_at' => $expiresAt ?? match ($status) {
                'expired' => now()->subDays(2),
                default => null,
            },
            'signed_at' => $status === 'signed' ? now()->subDay() : null,
            'voided_at' => in_array($status, ['expired', 'voided'], true) ? now()->subDay() : null,
            'void_reason' => match ($status) {
                'expired' => 'expired',
                'voided' => 'manual',
                default => null,
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
