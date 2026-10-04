<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\CreditNotesController as BaseCreditNotesController;

/**
 * Port of Rails' Api::V1::Customers::CreditNotesController
 * (app/controllers/api/v1/customers/credit_notes_controller.rb) — nested
 * under customers/:external_id, where the base controller resolves the
 * customer from the organization (404 envelope when unknown).
 *
 * The index behavior comes from the CreditNoteIndex concern; extending the
 * top-level CreditNotesController is the port of that include.
 */
class CreditNotesController extends BaseCreditNotesController
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        return $this->creditNoteIndex($request, $customer->external_id);
    }

    /** Rails: Customers::BaseController#find_customer. */
    private function findCustomer(Request $request): Customer
    {
        $customer = Customer::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('external_id', $request->route('external_id'))
            ->first();

        if ($customer === null) {
            throw new NotFoundException('customer');
        }

        return $customer;
    }
}
