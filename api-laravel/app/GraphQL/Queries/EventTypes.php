<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Support\WebhookEventTypes;
use App\GraphQL\Guards\AuthenticableApiUser;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::EventTypesResolver
 * (app/graphql/resolvers/event_types_resolver.rb): "Query Event Types for
 * Webhook Endpoints" — the frozen webhook_event_types.yml config.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class EventTypes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);

        $types = [];

        foreach (WebhookEventTypes::CONFIG as $key => $eventType) {
            $types[] = [
                // GraphQL-ruby serializes the returned enum value (the
                // "alert.triggered" name) back to the enum's gql name
                // ("alert_triggered"); the port emits it directly.
                'key' => str_replace('.', '_', (string) $key),
                'name' => $eventType['name'],
                'description' => $eventType['description'],
                // Rails: category.parameterize(separator: "_").upcase.
                'category' => mb_strtoupper((string) \Illuminate\Support\Str::slug($eventType['category'], '_')),
                'deprecated' => (bool) $eventType['deprecated'],
            ];
        }

        return $types;
    }
}
