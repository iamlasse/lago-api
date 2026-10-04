<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v2/product_categories',
    'ledger:rest:GET:/api/v2/product_categories',
    'ledger:rest:GET:/api/v2/product_categories/:code',
    'ledger:rest:PUT:/api/v2/product_categories/:code',
    'ledger:rest:PATCH:/api/v2/product_categories/:code',
    'ledger:rest:DELETE:/api/v2/product_categories/:code',
);

use App\Models\Product;
use App\Models\Organization;
use App\Models\ProductCategory;

/**
 * Port of Rails' spec/requests/api/v2/product_categories_controller_spec.rb.
 */
function productCategoriesTestOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'feature_flags' => ['product_catalog'],
    ], $attributes));

    return [$organization, $organization->apiKeys()->first()];
}

it('creates a product category', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization();

    $this->postJson('/api/v2/product_categories', ['product_category' => [
        'name' => 'Seats',
        'code' => 'seats',
        'description' => 'Seat licenses',
        'invoice_display_name' => 'Seats',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('product_category.code', 'seats')
                ->where('product_category.name', 'Seats')
                ->where('product_category.products_count', 0)
                ->has('product_category.lago_id')
                ->etc();
        });

    expect(ProductCategory::count())->toBe(1);
});

it('rejects a category without a name', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization();

    $this->postJson('/api/v2/product_categories', ['product_category' => [
        'code' => 'seats',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.name.0', 'value_is_mandatory')
            ->etc());
});

it('updates a product category', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization();

    $category = ProductCategory::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v2/product_categories/'.$category->code, ['product_category' => [
        'name' => 'Renamed',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('product_category.name', 'Renamed')
            ->etc());
});

it('rejects a category code that already exists', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization();

    $category = ProductCategory::factory()->create(['organization_id' => $organization->id]);
    ProductCategory::factory()->create(['organization_id' => $organization->id, 'code' => 'other']);

    $this->putJson('/api/v2/product_categories/'.$category->code, ['product_category' => [
        'code' => 'other',
        'name' => 'x',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.code.0', 'value_already_exist')
            ->etc());
});

it('deletes a product category and its products', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization();

    $category = ProductCategory::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'product_category_id' => $category->id,
    ]);

    $this->deleteJson('/api/v2/product_categories/'.$category->code, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect($category->fresh()->trashed())->toBeTrue()
        ->and($product->fresh()->trashed())->toBeTrue();
});

it('returns 403 without the product_catalog feature flag', function (): void {
    [$organization, $apiKey] = productCategoriesTestOrganization(['feature_flags' => []]);

    $this->postJson('/api/v2/product_categories', ['product_category' => [
        'name' => 'Seats',
        'code' => 'seats',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});
