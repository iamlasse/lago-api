<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * TODO(port): clickhouse store — the 954-line Events::Stores::ClickHouseStore
 * (aggregations over ClickHouse::EventsRaw/EventsEnriched). Selected by
 * StoreFactory only when LAGO_CLICKHOUSE_ENABLED is set and the organization
 * opted into the clickhouse events store; neither is the case in this
 * environment, so PostgresStore is the active path. Until the ClickHouse
 * client lands, every aggregation entry point throws.
 */
class ClickHouseStore extends BaseStore
{
    //
}
