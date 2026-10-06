<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Integration;
use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use Illuminate\Database\Eloquent\Builder;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::IntegrationsResolver
 * (app/graphql/resolvers/integrations_resolver.rb): "Query organization's
 * integrations" — kaminari pagination and the STI type filter (the wire
 * enum names map onto the Rails class names through
 * Integrations::BaseIntegration.integration_type).
 *
 * TODO(port): the REQUIRED_PERMISSION gates
 * (%w[organization:integrations:view customers:view]).
 */
class Integrations
{
    /** Rails: Integrations::BaseIntegration.integration_type. */
    private const TYPES = [
        'netsuite' => 'Integrations::NetsuiteIntegration',
        'okta' => 'Integrations::OktaIntegration',
        'entra_id' => 'Integrations::EntraIdIntegration',
        'anrok' => 'Integrations::AnrokIntegration',
        'avalara' => 'Integrations::AvalaraIntegration',
        'xero' => 'Integrations::XeroIntegration',
        'hubspot' => 'Integrations::HubspotIntegration',
        'salesforce' => 'Integrations::SalesforceIntegration',
    ];

    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        [$page, $limit] = Page::normalizePageAndLimit($args['page'] ?? null, $args['limit'] ?? null);

        /** @var Builder $scope */
        $scope = Integration::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id);

        if (($args['types'] ?? null) !== null) {
            $scope->whereIn('type', array_map(
                fn (string $type): string => self::TYPES[$type] ?? '',
                (array) $args['types'],
            ));
        }

        $total = (clone $scope)->count();

        return new Page(
            collection: $scope->orderBy('id')->forPage($page, $limit)->get()->all(),
            metadata: (object) [
                'currentPage' => $page,
                'limitValue' => $limit,
                'totalPages' => max(1, (int) ceil($total / $limit)),
                'totalCount' => $total,
            ],
        );
    }
}
