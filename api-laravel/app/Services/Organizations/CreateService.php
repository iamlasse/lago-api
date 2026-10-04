<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use Illuminate\Support\Str;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\BillingEntity;
use App\Services\BaseService;
use App\Enums\DocumentNumbering;
use App\Enums\EntityDocumentNumbering;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Organizations::CreateService
 * (app/services/organizations/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): the LAGO_CLICKHOUSE_ENABLED branch (ClickHouse event store).
 * - TODO(port): activity logs + security logs (activity_loggable /
 *   register_security_log tails).
 */
class CreateService extends BaseService
{
    /**
     * Port of Organizations::Sluggable (app/models/concerns/organizations/
     * sluggable.rb): the model-level before_validation slug hook has no
     * Eloquent equivalent in the port, so it lives where organizations are
     * created.
     */
    private const SLUG_FORMAT = '/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?\z/';

    private const RESERVED_SLUGS = [
        'auth', 'login', 'sign-up', 'forgot-password', 'reset-password', 'invitation',
        'customer-portal', '404', 'forbidden', 'api', 'admin', 'graphql', 'webhooks', 'google', 'okta', 'entra',
        'settings', 'new', 'design-system', 'devtool',
        'customers', 'customer', 'plans', 'plan', 'invoices', 'invoice', 'subscriptions',
        'coupons', 'coupon', 'add-ons', 'add-on', 'billable-metrics', 'billable-metric',
        'credit-notes', 'analytics', 'analytics-v2', 'forecasts', 'payments', 'payment',
        'features', 'feature', 'tax', 'webhook', 'api-keys', 'create', 'update', 'duplicate',
    ];

    public function __construct(private readonly array $params)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('organization');
        $params = $this->params;

        // Rails' wire value is the enum NAME (per_customer/per_organization);
        // the frozen column stores the Rails enum ORDER (integer).
        $wireNumbering = $params['document_numbering'] ?? null;
        $organizationNumbering = $wireNumbering === null
            ? null
            : DocumentNumbering::from($wireNumbering === 'per_organization' ? 1 : 0);

        // Rails: params.slice(:name, :document_numbering, :premium_integrations)
        // — absent keys fall through to the column defaults.
        $organization = new Organization(array_filter([
            'name' => $params['name'] ?? null,
            'document_numbering' => $organizationNumbering?->value,
            'premium_integrations' => $params['premium_integrations'] ?? null,
        ], static fn ($value): bool => $value !== null));
        // `slug` is not in the model's Fillable list (the models slice owns
        // that) — set it directly.
        $organization->forceFill(['slug' => $this->generateSlug((string) ($params['name'] ?? ''))]);

        try {
            $errors = $organization->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $organization->save();

            $organization->apiKeys()->create([]);

            // TODO: remove when we do not support document_numbering per
            // organization — the first billing entity downgrades
            // per_organization to per_billing_entity.
            $entityDocumentNumbering = $wireNumbering === 'per_organization'
                ? EntityDocumentNumbering::PerBillingEntity
                : EntityDocumentNumbering::PerCustomer;

            // NOTE: ensure first billing entity has the same id as the
            // organization to ease the migration to multi entities.
            $billingEntity = new BillingEntity([
                'organization_id' => $organization->id,
                'name' => $params['name'] ?? null,
                'code' => $params['code'] ?? ($params['name'] !== null ? Str::slug((string) $params['name'], '_') : null),
                'document_numbering' => $entityDocumentNumbering,
            ]);
            $billingEntity->id = $organization->id;

            $entityErrors = $billingEntity->validateAttributes();

            if ($entityErrors !== []) {
                $result->recordValidationFailure($entityErrors)->raiseIfError();
            }

            $billingEntity->save();

            $result->organization = $organization;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Port of `Organizations::Sluggable#generate_slug` — parameterized
     * candidate, falling back to a random `org-xxxxx` when too short,
     * all-digit or reserved; uniqueness suffix otherwise.
     */
    private function generateSlug(string $name): string
    {
        $candidate = Str::of($name)->transliterate()->slug()->substr(0, 40)->trim('-')->toString();

        $needsRandomCandidate = mb_strlen($candidate) < 3
            || preg_match('/\A\d+\z/', $candidate) === 1
            || in_array($candidate, self::RESERVED_SLUGS, true);

        if ($needsRandomCandidate) {
            do {
                $candidate = 'org-'.mb_strtolower(Str::random(5));
            } while (Organization::query()->where('slug', $candidate)->exists());

            return $candidate;
        }

        $base = mb_substr($candidate, 0, 36);

        while (Organization::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.mb_strtolower(Str::random(3));
        }

        return $candidate;
    }
}
