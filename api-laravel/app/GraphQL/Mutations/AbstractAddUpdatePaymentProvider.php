<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PaymentProviders\AdyenService;
use App\Services\PaymentProviders\CashfreeService;
use App\Services\PaymentProviders\MoneyhashService;
use App\Services\PaymentProviders\GocardlessService;
use App\Services\PaymentProviders\FlutterwaveService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\PaymentProviders\Stripe\RegisterService;

/**
 * Shared body of the frozen SDL's add/update payment-provider mutations
 * (port of Rails' Mutations::PaymentProviders::<Provider>::Base): the
 * per-provider `create_or_update` service with the input merged over the
 * organization scope. The concrete classes carry the graphql_name, input
 * type and REQUIRED_PERMISSION gate (TODO(port): the permission checks land
 * with the roles/Permission slice — context permissions are not populated
 * yet).
 */
abstract class AbstractAddUpdatePaymentProvider
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = match ($this->slug()) {
            'stripe' => RegisterService::call(organizationId: $organization->id, args: $input),
            'adyen' => AdyenService::call(organization: $organization, args: $input),
            'cashfree' => CashfreeService::call(organization: $organization, args: $input),
            'flutterwave' => FlutterwaveService::call(organization: $organization, args: $input),
            'gocardless' => GocardlessService::call(organization: $organization, args: $input),
            'moneyhash' => MoneyhashService::call(organization: $organization, args: $input),
        };

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->{$this->slug().'_provider'};
    }

    /** The provider slug this mutation connects ("stripe", "adyen", …). */
    abstract protected function slug(): string;

    /** Rails: REQUIRED_PERMISSION ("organization:integrations:create|update"). */
    abstract protected function requiredPermission(): string;
}
