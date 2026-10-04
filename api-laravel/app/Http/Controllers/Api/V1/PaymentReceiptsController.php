<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\PaymentReceipt;
use Illuminate\Http\JsonResponse;
use App\Queries\PaymentReceiptsQuery;
use App\Services\Emails\ResendService;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\PaymentReceiptSerializer;

/**
 * Port of Rails' Api::V1::PaymentReceiptsController
 * (app/controllers/api/v1/payment_receipts_controller.rb): GET /payment_receipts
 * (invoice_id filter), GET /payment_receipts/:id and
 * POST /payment_receipts/:id/resend_email (Emails::ResendService — head :ok
 * on success). The v2 mirror reuses the same controller (Rails serves v2
 * from the api/v1 namespace).
 */
class PaymentReceiptsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'payment_receipt';

    public function index(Request $request): JsonResponse
    {
        $result = PaymentReceiptsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'invoice_id' => $this->scalarQuery($request, 'invoice_id'),
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->payment_receipts,
                    PaymentReceiptSerializer::class,
                    [
                        'collection_name' => 'payment_receipts',
                        'meta' => $this->paginationMetadata($result->payment_receipts),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $paymentReceipt = $this->findReceipt($request);

        if ($paymentReceipt === null) {
            throw new NotFoundException('payment_receipt');
        }

        return $this->renderSerializerJson(
            (new PaymentReceiptSerializer($paymentReceipt, ['root_name' => 'payment_receipt']))->toJson()
        );
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $paymentReceipt = $this->findReceipt($request);

        if ($paymentReceipt === null) {
            throw new NotFoundException('payment_receipt');
        }

        $result = ResendService::call(
            resource: $paymentReceipt,
            to: $this->emailParams($request, 'to'),
            cc: $this->emailParams($request, 'cc'),
            bcc: $this->emailParams($request, 'bcc'),
        );

        if ($result->success()) {
            // Rails: head(:ok).
            return response()->json(null, 200);
        }

        $this->renderErrorResponse($result);
    }

    protected function findReceipt(Request $request): ?PaymentReceipt
    {
        return PaymentReceipt::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('id', (string) $request->route('id'))
            ->first();
    }

    /** Rails: params.permit(to: [], cc: [], bcc: []). */
    protected function emailParams(Request $request, string $key): ?array
    {
        $value = $request->input($key);

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, 'is_scalar'));
    }

    /** A scalar (or absent) query value — arrays are dropped, like `permit`. */
    protected function scalarQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
