<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Quote;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\OrderExecutionMode;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\Failures\ValidationFailure;

/**
 * Port of Rails' Orders::BaseExecuteService
 * (app/services/orders/base_execute_service.rb).
 *
 * Internal: the premium/feature-flag gates live in Orders::ExecuteService,
 * always call through it. Subclasses implement createRecords(), returning
 * the ids of what they created for the execution record.
 */
abstract class BaseExecuteService extends BaseService
{
    public function __construct(
        protected readonly ?Order $order,
    ) {
        parent::__construct();
    }

    /**
     * Rails: `create_records` — the ids of what the execution created, for
     * the execution record.
     *
     * @return array<string, mixed>
     */
    abstract protected function createRecords(): array;

    public function execute(): BaseResult
    {
        $result = static::makeResult('order');
        $order = $this->order;

        if ($order === null) {
            return $result->notFoundFailure('order');
        }

        if ($order->isExecuted()) {
            $result->order = $order;

            return $result;
        }

        if ($order->execution_mode === null) {
            return $result->singleValidationFailure('value_is_mandatory', 'execution_mode');
        }

        try {
            DB::transaction(function () use ($order, $result): void {
                // Rails: Quotes::LockService.call(quote: order.quote) { ... }
                // — the quote row is locked for the whole execution.
                // TODO(port): the advisory xact lock (Quotes::LockService) —
                // the row lock below is the transactional spine until the
                // quotes slice lands.
                $quoteId = $order->orderForm?->quoteVersion?->quote_id;

                if ($quoteId !== null) {
                    Quote::query()->whereKey($quoteId)->lockForUpdate()->first();
                }

                $order->refresh();

                if ($order->isExecuted()) {
                    return;
                }

                $this->markExecuted(
                    $order->execution_mode === OrderExecutionMode::ExecuteInLago
                        ? $this->createRecords()
                        : [],
                    $result,
                );
            });

            $result->order = $order;

            return $result;
        } catch (FailedResult $e) {
            // Rails: rescue BaseService::FailedResult => e;
            // record_execution_failure!(e.result) — the raised failure is
            // the NESTED service's failed result, and that is what the
            // caller receives (its error carries the details the API
            // surfaces); the order itself moves to failed below.
            return $this->recordExecutionFailure($e->result, $e);
        }
    }

    /**
     * Rails: `mark_executed!` — flips the order to executed and writes the
     * execution record.
     *
     * @param  array<string, mixed>  $created
     */
    protected function markExecuted(array $created, BaseResult $result): void
    {
        $order = $this->order;
        assert($order !== null);

        $executedAt = now();

        $order->status = Order::STATUSES['executed'];
        $order->executed_at = $executedAt;
        $order->execution_record = $this->executionRecord([
            'executed_at' => $executedAt->toISOString(),
            ...$created,
        ]);

        if (($errors = $order->validateAttributes()) !== []) {
            // Rails: the update! would raise ActiveRecord::RecordInvalid —
            // unreachable through the service paths (the status/mode pairs
            // written here are valid); kept for parity.
            $result->recordValidationFailure($errors)->raiseIfError();
        }

        $order->save();

        // TODO(port) emission points, at their exact Rails positions:
        // SendWebhookJob.perform_after_commit("order.executed", order) and
        // Utils::ActivityLog.produce_after_commit(order, "order.executed").
    }

    /**
     * Rails: `record_execution_failure!` — the transaction has already
     * rolled back, so this trace is the only durable outcome of the
     * attempt. Recording it moves the order to failed, excluding it from
     * the executable scope; retrying is a deliberate manual action.
     */
    protected function recordExecutionFailure(BaseResult $result, FailedResult $error): BaseResult
    {
        $order = $this->order;
        assert($order !== null);

        $order->status = Order::STATUSES['failed'];
        $order->executed_at = null;
        $order->execution_record = $this->executionRecord([
            'errors' => $this->executionErrors($error),
        ]);
        $order->save();

        // Rails returns e.result (or the record-validation failure result)
        // — the failure the caller sees.
        return $result;
    }

    /**
     * Rails: `execution_record` — the defaults carry every key every order
     * type writes, so a reader never tells a missing key from an empty one.
     *
     * @param  array<string, mixed>  $written
     * @return array<string, mixed>
     */
    protected function executionRecord(array $written = []): array
    {
        return array_merge(
            Order::EXECUTION_RECORD_DEFAULTS,
            ['execution_mode' => $this->order?->execution_mode?->value],
            $written,
        );
    }

    /**
     * Rails: `billing_items` — the execution replays the snapshot the
     * order form was signed from.
     *
     * @return array<string, mixed>
     */
    protected function billingItems(): array
    {
        return $this->order?->billingSnapshot() ?? [];
    }

    /**
     * Rails: `effective_value` — a negotiated value overrides the catalog
     * snapshot it was drafted from.
     */
    protected function effectiveValue(array $item, string $field): mixed
    {
        return $item['overrides'][$field] ?? $item['payload'][$field] ?? null;
    }

    /**
     * Rails: `execution_errors` — validation messages flatten to their
     * codes, failures carrying a code record the code, anything else its
     * message.
     *
     * @return list<string>
     */
    protected function executionErrors(FailedResult $raised): array
    {
        // raiseIfError throws the failure it embedded on the result; the
        // embedded one carries the structured data.
        $failure = $raised->result->getError() ?? $raised;

        if ($failure instanceof ValidationFailure) {
            $messages = [];

            foreach ((array) $failure->messages as $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    $messages[] = (string) $message;
                }
            }

            return $messages;
        }

        if (isset($failure->code) && is_string($failure->code)) {
            return [$failure->code];
        }

        return [$failure->getMessage()];
    }
}
