<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Services\BaseResult;
use App\Models\IntegrationItem;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Integrations::Aggregator::ItemsService
 * (…/aggregator/items_service.rb) — the Nango items pull behind the
 * `fetchIntegrationItems` mutation: the integration's `standard` items are
 * replaced transactionally from the cursor-paginated provider feed, with
 * Xero's incremental-sync duplicates deduplicated.
 */
class ItemsService extends BaseService
{
    public const LIMIT = 450;

    public const MAX_SUBSEQUENT_REQUESTS = 15;

    /** @var string|null */
    private $cursor;

    /** @var list<IntegrationItem> */
    private array $items = [];

    public function __construct(
        \App\Models\Integration $integration,
    ) {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/items';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('items');

        $this->cursor = null;
        $this->items = [];

        DB::transaction(function (): void {
            $fetchedItems = [];

            // Rails: integration.integration_items.where(item_type:
            // :standard).destroy_all — item_type 0 is the :standard position.
            IntegrationItem::query()
                ->where('integration_id', $this->integration->id)
                ->where('item_type', 0)
                ->delete();

            for ($i = 0; $i < self::MAX_SUBSEQUENT_REQUESTS; $i++) {
                $response = $this->http_client()->get(headers: $this->headers(), params: $this->params());

                foreach ((array) (is_array($response) ? ($response['records'] ?? []) : []) as $record) {
                    if (is_array($record)) {
                        $fetchedItems[] = $record;
                    }
                }

                $this->cursor = is_array($response) ? ($response['next_cursor'] ?? null) : null;

                if ($this->cursorBlank()) {
                    break;
                }
            }

            $this->handleItems($this->deduplicateItems($fetchedItems));
        });

        $result->items = $this->items;

        return $result;
    }

    /**
     * @return array<string, string|null>
     */
    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }

    private function handleItems(array $records): void
    {
        foreach ($records as $item) {
            $externalIdKey = method_exists($this->integration, 'externalIdKey')
                ? $this->integration->externalIdKey()
                : 'id';

            $integrationItem = new IntegrationItem([
                'organization_id' => $this->integration->organization_id,
                'integration_id' => $this->integration->id,
                'external_id' => $item[$externalIdKey] ?? null,
                'external_account_code' => $item['account_code'] ?? null,
                'external_name' => $item['name'] ?? null,
                'item_type' => 0, // :standard
            ]);

            $integrationItem->save();

            $this->items[] = $integrationItem;
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function params(): array
    {
        return $this->cursorBlank()
            ? ['limit' => self::LIMIT]
            : ['limit' => self::LIMIT, 'cursor' => (string) $this->cursor];
    }

    /**
     * Rails: deduplicate_items — Nango's incremental sync stores duplicate
     * `item_code` rows for Xero (its external_id_key); the most recently
     * modified duplicate wins (per `_nango_metadata.last_modified_at`).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function deduplicateItems(array $items): array
    {
        $externalIdKey = method_exists($this->integration, 'externalIdKey')
            ? $this->integration->externalIdKey()
            : 'id';

        $groups = [];

        foreach ($items as $item) {
            $groups[(string) ($item[$externalIdKey] ?? '')][] = $item;
        }

        $result = [];

        foreach ($groups as $duplicates) {
            $winner = null;
            $winnerAt = '';

            foreach ($duplicates as $item) {
                $lastModifiedAt = $item['_nango_metadata']['last_modified_at'] ?? '';

                if ($winner === null || (is_string($lastModifiedAt) && $lastModifiedAt > $winnerAt)) {
                    $winner = $item;
                    $winnerAt = is_string($lastModifiedAt) ? $lastModifiedAt : '';
                }
            }

            if ($winner !== null) {
                $result[] = $winner;
            }
        }

        return $result;
    }

    private function cursorBlank(): bool
    {
        return $this->cursor === null || $this->cursor === '';
    }
}
