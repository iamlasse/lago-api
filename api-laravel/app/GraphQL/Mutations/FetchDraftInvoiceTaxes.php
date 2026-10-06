<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::FetchDraftInvoiceTaxes
 * (app/graphql/mutations/integrations/fetch_draft_invoice_taxes.rb):
 * "Fetches taxes for one-off invoice" — the one-off invoice previewed from
 * the UI is not persisted, so Rails builds lightweight DraftInvoice /
 * DraftFee stand-ins and reports them through
 * Integrations::Aggregator::Taxes::Invoices::CreateDraftService.
 *
 * TODO(port): the draft stand-in leg of the tax aggregator — the ported
 * CreateDraftService is typed to a persisted Invoice and reads model-level
 * accessors (Fee#itemKey, Fee#subTotalExcludingTaxesAmountCents) the Rails
 * stand-ins deliberately replace with "fail loudly" stubs. Until the payload
 * factory accepts draft documents, the mutation resolves the customer (the
 * not_found envelope) and answers an empty collection when no tax
 * integration covers the customer — the same observable envelope Rails'
 * service produces without an integration.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:create") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class FetchDraftInvoiceTaxes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id: args[:customer_id]).
        $customer = $organization->customers()->find($input['customer_id'] ?? null);

        if ($customer === null) {
            throw Errors::notFoundError('customer');
        }

        // Rails: the CreateDraftService result answers an empty fee list when
        // the customer carries no tax integration (or it is not a tax
        // integration type) — see the TODO(port) above for the draft leg.
        $fees = $this->draftTaxes($customer, $input);

        return new Page(
            collection: $fees,
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => max(1, count($fees)),
                'totalPages' => 1,
                'totalCount' => count($fees),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<object>
     */
    private function draftTaxes(Customer $customer, array $input): array
    {
        $taxCustomer = $customer->taxCustomer();

        if ($taxCustomer === null) {
            return [];
        }

        $integration = $taxCustomer->integration ?? null;

        if ($integration === null || ! in_array($integration->type, \App\Models\Integration::INTEGRATION_TAX_TYPES, true)) {
            return [];
        }

        // TODO(port): the draft stand-in call into
        // Integrations\Aggregator\Taxes\Invoices\CreateDraftService (see the
        // class docblock).
        return [];
    }
}
