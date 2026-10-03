<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::PostgresStore
 * (app/services/events/stores/postgres_store.rb) — the default events
 * store: aggregation SQL over the `events` table.
 *
 * SCOPE NOTE (events ingestion slice): only the store wiring lands here;
 * the aggregation query API (events/events_values/count/sum/max/last/
 * unique_count/weighted_sum + grouped/prorated variants, the
 * UniqueCountQuery/WeightedSumQuery SQL builders) throws until its
 * consumers are ported — see BaseStore's docblock. TODO(port).
 */
class PostgresStore extends BaseStore
{
    //
}
