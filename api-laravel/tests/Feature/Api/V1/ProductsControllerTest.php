<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v2/products',
    'ledger:rest:GET:/api/v2/products',
    'ledger:rest:GET:/api/v2/products/:code',
    'ledger:rest:PUT:/api/v2/products/:code',
    'ledger:rest:PATCH:/api/v2/products/:code',
    'ledger:rest:DELETE:/api/v2/products/:code',
    'ledger:rest:GET:/api/v2/products/:code/filters',
    'ledger:rest:POST:/api/v2/products/:code/filters',
);

use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\ProductFilter;
use App\Models\BillableMetric;
use App\Models\ProductCategory;

/**
 * Port of Rails' spec/requests/api/v2/products_controller_spec.rb (and the
 * products/filters spec) — the v2 catalog products endpoints, gated on the
 * organization's product_catalog feature flag.
 */
function productsTestOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'feature_flags' => ['product_catalog'],
    ], $attributes));

    return [$organization, $organization->apiKeys()->first()];
}

it('creates a metered product', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v2/products', ['product' => [
        'name' => 'API calls',
        'code' => 'api_calls',
        'product_type' => 'metered',
        'description' => 'API call unit',
        'invoice_display_name' => 'API usage',
        'billable_metric_code' => $metric->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($metric): void {
            $json->where('product.code', 'api_calls')
                ->where('product.name', 'API calls')
                ->where('product.product_type', 'metered')
                ->where('product.billable_metric_code', $metric->code)
                ->where('product.product_category_code', null)
                ->where('product.filters_count', 0)
                ->has('product.lago_id')
                ->etc();
        });

    expect(Product::count())->toBe(1);
});

it('creates a fixed product in a product category', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $category = ProductCategory::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v2/products', ['product' => [
        'name' => 'Seat',
        'code' => 'seat',
        'product_type' => 'fixed',
        'product_category_code' => $category->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('product.product_type', 'fixed')
            ->where('product.billable_metric_code', null)
            ->where('product.product_category_code', $category->code)
            ->etc());
});

it('rejects a metered product without a billable metric', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $this->postJson('/api/v2/products', ['product' => [
        'name' => 'API calls',
        'code' => 'api_calls',
        'product_type' => 'metered',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'validation_errors')
            ->where('error_details.billable_metric_code.0', 'value_is_mandatory')
            ->etc());
});

it('returns not_found for an unknown billable_metric_code', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $this->postJson('/api/v2/products', ['product' => [
        'name' => 'API calls',
        'code' => 'api_calls',
        'product_type' => 'metered',
        'billable_metric_code' => 'nope',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJson(['status' => 404, 'error' => 'Not Found', 'code' => 'billable_metrics_not_found']);
});

it('rejects a duplicate product code in the organization', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    Product::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'dup',
        'product_type' => 'fixed',
        'billable_metric_id' => null,
    ]);

    $this->postJson('/api/v2/products', ['product' => [
        'name' => 'Another',
        'code' => 'dup',
        'product_type' => 'fixed',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.code.0', 'value_already_exist')
            ->etc());
});

it('shows a product by code', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'seat',
        'product_type' => 'fixed',
        'billable_metric_id' => null,
    ]);

    $this->getJson('/api/v2/products/seat', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('product.lago_id', $product->id)
            ->where('product.code', 'seat')
            ->etc());
});

it('returns not_found for an unknown product', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $this->getJson('/api/v2/products/'.Str::uuid(), ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('soft-deletes a product and cascades to its filters', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'seat',
        'product_type' => 'fixed',
        'billable_metric_id' => null,
    ]);

    $this->deleteJson('/api/v2/products/seat', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('product.lago_id', $product->id)
            ->etc());

    expect($product->fresh()->trashed())->toBeTrue();
});

it('returns 403 without the product_catalog feature flag', function (): void {
    [$organization, $apiKey] = productsTestOrganization(['feature_flags' => []]);

    $this->getJson('/api/v2/products', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson(['status' => 403, 'error' => 'Forbidden', 'code' => 'feature_unavailable']);
});

it('rejects an unknown product_type on index', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $this->getJson('/api/v2/products?product_type=weird', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(['status' => 422, 'error' => 'Unprocessable Entity', 'code' => 'validation_errors',
            'error_details' => ['product_type' => ['value_is_invalid']]]);
});

it('indexes products filtered by product_type', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    Product::factory()->count(2)->create(['organization_id' => $organization->id]);
    Product::factory()->create([
        'organization_id' => $organization->id,
        'product_type' => 'fixed',
        'billable_metric_id' => null,
    ]);

    $this->getJson('/api/v2/products?product_type=fixed', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('products', 1)
                ->where('products.0.product_type', 'fixed')
                ->has('meta')
                ->etc();
        });
});

// -- Nested filters -----------------------------------------------------------------

it('creates a product filter with resolved values', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $metricFilter = App\Models\BillableMetricFilter::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'key' => 'region',
        'values' => ['eu', 'us'],
    ]);

    $this->postJson('/api/v2/products/'.$product->code.'/filters', ['filter' => [
        'name' => 'EU traffic',
        'code' => 'eu_traffic',
        'values' => [['key' => 'region', 'value' => 'eu']],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('filter.code', 'eu_traffic')
                ->where('filter.values.0.key', 'region')
                ->where('filter.values.0.value', 'eu')
                ->etc();
        });

    expect(ProductFilter::where('product_id', $product->id)->count())->toBe(1);
});

it('rejects a filter on a fixed product', function (): void {
    [$organization, $apiKey] = productsTestOrganization();

    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'product_type' => 'fixed',
        'billable_metric_id' => null,
    ]);

    $this->postJson('/api/v2/products/'.$product->code.'/filters', ['filter' => [
        'name' => 'EU traffic',
        'code' => 'eu_traffic',
        'values' => [],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.product.0', 'not_allowed_for_product_type')
            ->etc());
});
