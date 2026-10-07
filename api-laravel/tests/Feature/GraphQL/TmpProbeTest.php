<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';
require_once __DIR__.'/InvoiceCustomSectionsAndAdjustedFeesTest.php';

it('probe2', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);
    $organization = $organization->refresh();
    $f = adjGqlFeeFixture($organization);

    dump('fee exists', $f['invoice']->fees()->whereKey($f['fee']->id)->exists(), $f['fee']->id);

    $mutation = <<<'GQL'
mutation($input: CreateAdjustedFeeInput!) {
    createAdjustedFee(input: $input) { id units adjustedFee }
}
GQL;
    $response = gqlPost($mutation, ['input' => [
        'invoiceId' => $f['invoice']->id,
        'feeId' => $f['fee']->id,
        'units' => 5,
    ]], gqlAuthHeaders($user, $organization->id));

    dump('gql response', $response->json());
    expect(true)->toBeTrue();
});
