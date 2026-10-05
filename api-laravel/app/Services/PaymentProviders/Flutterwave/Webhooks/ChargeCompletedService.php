<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Flutterwave\Webhooks;

use Throwable;
use LogicException;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Values\FlutterwavePayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\Failures\FailedResult;
use App\Services\PaymentProviders\FindService;
use App\Services\Invoices\Payments\CashfreeService;
use App\Services\Invoices\Payments\FlutterwaveService;

/**
 * Port of Rails' Flutterwave::Webhooks::ChargeCompletedService —
 * "successful" transactions are re-verified against the Flutterwave API
 * (GET /transactions/{id}/verify with the provider's secret key) before the
 * invoice payment status is moved. The meta's lago_invoice_id /
 * lago_payable_id (or the tx_ref fallback) is the provider payment id; the
 * meta's lago_payable_type picks the payable's service (only the Invoice
 * service is ported — an unknown type is Rails' NameError, which the
 * caller logs and swallows).
 */
class ChargeCompletedService extends BaseService
{
    public const SUCCESS_STATUSES = ['successful'];

    public function __construct(
        private readonly string $organizationId,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            $transactionData = $event['data'] ?? [];
            $transactionStatus = $transactionData['status'] ?? null;

            if (! in_array($transactionStatus, self::SUCCESS_STATUSES, true)) {
                return $result;
            }

            $providerPaymentId = $transactionData['meta']['lago_invoice_id']
                ?? $transactionData['meta']['lago_payable_id']
                ?? $transactionData['tx_ref']
                ?? null;

            if ($providerPaymentId === null) {
                return $result;
            }

            // Validate payable_type first to raise NameError for invalid types.
            $paymentServiceClass = self::paymentServiceClass($transactionData);

            $verifiedTransaction = $this->verifyTransaction($transactionData);

            if ($verifiedTransaction === null) {
                return $result;
            }

            $payable = self::findPayable($transactionData, (string) $providerPaymentId);

            if ($payable === null) {
                return $result;
            }

            $paymentServiceClass::updatePaymentStatus(
                organizationId: $this->organizationId,
                status: $verifiedTransaction['status'],
                flutterwavePayment: new FlutterwavePayment(
                    id: (string) $providerPaymentId,
                    status: $verifiedTransaction['status'],
                    metadata: self::buildMetadata($transactionData, $verifiedTransaction),
                ),
                amountCents: self::verifiedAmountCents($verifiedTransaction),
            )->raiseIfError();

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Rails: PAYMENT_SERVICE_CLASS_MAP.fetch(payable_type || "Invoice") —
     * only the Invoice service is ported.
     *
     * @param  array<string, mixed>  $transactionData
     */
    private static function paymentServiceClass(array $transactionData): string
    {
        $payableType = $transactionData['meta']['lago_payable_type'] ?? 'Invoice';

        if ($payableType === 'Invoice') {
            return FlutterwaveService::class;
        }

        throw new LogicException("Invalid lago_payable_type: {$payableType}");
    }

    /**
     * Rails: find_payable — the payable must exist for the event to move
     * anything.
     *
     * @param  array<string, mixed>  $transactionData
     */
    private static function findPayable(array $transactionData, string $providerPaymentId): ?Invoice
    {
        $payableType = $transactionData['meta']['lago_payable_type'] ?? 'Invoice';

        if ($payableType === 'Invoice') {
            return Invoice::query()->find($providerPaymentId);
        }

        // Rails also looks up PaymentRequest rows; TODO(port) with the
        // payment-request slice.
        return null;
    }

    /**
     * Rails: build_metadata.
     *
     * @param  array<string, mixed>  $transactionData
     * @param  array<string, mixed>  $verifiedTransaction
     * @return array<string, mixed>
     */
    private static function buildMetadata(array $transactionData, array $verifiedTransaction): array
    {
        return [
            'lago_invoice_id' => $transactionData['meta']['lago_invoice_id']
                ?? $transactionData['meta']['lago_payable_id']
                ?? $transactionData['tx_ref']
                ?? null,
            'lago_payable_type' => $transactionData['meta']['lago_payable_type'] ?? 'Invoice',
            'flutterwave_transaction_id' => $verifiedTransaction['id'],
            'flw_ref' => $verifiedTransaction['reference'],
            'reference' => $verifiedTransaction['reference'],
            'amount' => $verifiedTransaction['amount'],
            'currency' => $verifiedTransaction['currency'],
            'payment_type' => 'one-time',
        ];
    }

    /**
     * Rails: verified_amount_cents — Money.from_amount(amount.to_d,
     * currency).cents.
     *
     * @param  array<string, mixed>  $verifiedTransaction
     */
    private static function verifiedAmountCents(array $verifiedTransaction): ?int
    {
        if ($verifiedTransaction['amount'] === null || $verifiedTransaction['currency'] === null) {
            return null;
        }

        return CashfreeService::amountToCents($verifiedTransaction['amount']);
    }

    /**
     * Rails: verify_transaction — GET {api_url}/transactions/{id}/verify
     * with the provider's secret key; only a "success" envelope with a
     * "successful" data status verifies.
     *
     * @param  array<string, mixed>  $transactionData
     * @return array<string, mixed>|null
     */
    private function verifyTransaction(array $transactionData): ?array
    {
        $paymentProviderResult = FindService::call(
            organizationId: $this->organizationId,
            paymentProviderType: 'flutterwave',
        );

        if ($paymentProviderResult->failure()) {
            return null;
        }

        $paymentProvider = $paymentProviderResult->payment_provider;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.(string) ($paymentProvider->secretKey() ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->get(\App\Models\PaymentProvider::FLUTTERWAVE_API_URL.'/transactions/'.($transactionData['id'] ?? '').'/verify');

            $body = $response->json() ?? [];
        } catch (Throwable $e) {
            // Rails: rescue LagoHttpClient::HttpError -> error log, nil.
            Log::error('Error verifying Flutterwave transaction: '.$e->getMessage());

            return null;
        }

        if (($body['status'] ?? null) === 'success' && (($body['data']['status'] ?? null) === 'successful')) {
            return [
                'id' => $body['data']['id'] ?? null,
                'status' => $body['data']['status'] ?? null,
                'amount' => $body['data']['amount'] ?? null,
                'currency' => $body['data']['currency'] ?? null,
                'customer' => $body['data']['customer'] ?? null,
                'reference' => $body['data']['tx_ref'] ?? null,
            ];
        }

        Log::warning('Flutterwave transaction verification failed: '.json_encode($body));

        return null;
    }
}
