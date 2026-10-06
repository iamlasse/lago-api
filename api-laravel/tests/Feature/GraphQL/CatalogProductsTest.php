<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFilter;
use Illuminate\Support\Str;

/**
 * Ports of Rails' spec/graphql/mutations/products/*_spec.rb,
 * product_categories/*_spec.rb, product_filters/*_spec.rb and the matching
 * resolvers over the frozen SDL.
 *
 * Ledger rows: gql:query:product, gql:query:products, gql:query:productCategory,
 * gql:query:productCategories, gql:query:productFilter, gql:query:productFilters,
 * gql:mutation:createProduct, gql:mutation:updateProduct,
 * gql:mutation:destroyProduct, gql:mutation:createProductCategory,
 * gql:mutation:updateProductCategory, gql:mutation:destroyProductCategory,
 * gql:mutation:createProductFilter, gql:mutation:updateProductFilter,
 * gql:mutation:destroyProductFilter.
 */
function gqlCatalogSetup(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_PRODUCT_MUTATION = <<<'GQL'
mutation($input: CreateProductInput!) {
    createProduct(input: $input) { id code name productType }
}
GQL;

const PRODUCTS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String, $productCategoryIds: [ID!], $withoutProductCategory: Boolean) {
    products(page: $page, limit: $limit, searchTerm: $searchTerm, productCategoryIds: $productCategoryIds, withoutProductCategory: $withoutProductCategory) {
        collection { id code name }
        metadata { totalCount }
    }
}
GQL;

const PRODUCT_QUERY = <<<'GQL'
query($id: ID!) {
    product(id: $id) { id code name }
}
GQL;

const UPDATE_PRODUCT_MUTATION = <<<'GQL'
mutation($input: UpdateProductInput!) {
    updateProduct(input: $input) { id code name }
}
GQL;

const DESTROY_PRODUCT_MUTATION = <<<'GQL'
mutation($input: DestroyProductInput!) {
    destroyProduct(input: $input) { id }
}
GQL;

const CREATE_PRODUCT_CATEGORY_MUTATION = <<<'GQL'
mutation($input: CreateProductCategoryInput!) {
    createProductCategory(input: $input) { id code name }
}
GQL;

const PRODUCT_CATEGORIES_QUERY = <<<'GQL'
query($searchTerm: String) {
    productCategories(searchTerm: $searchTerm) {
        collection { id code name }
        metadata { totalCount }
    }
}
GQL;

const PRODUCT_CATEGORY_QUERY = <<<'GQL'
query($id: ID!) {
    productCategory(id: $id) { id code }
}
GQL;

const UPDATE_PRODUCT_CATEGORY_MUTATION = <<<'GQL'
mutation($input: UpdateProductCategoryInput!) {
    updateProductCategory(input: $input) { id name }
}
GQL;

const DESTROY_PRODUCT_CATEGORY_MUTATION = <<<'GQL'
mutation($input: DestroyProductCategoryInput!) {
    destroyProductCategory(input: $input) { id }
}
GQL;

const CREATE_PRODUCT_FILTER_MUTATION = <<<'GQL'
mutation($input: CreateProductFilterInput!) {
    createProductFilter(input: $input) { id code name }
}
GQL;

const PRODUCT_FILTERS_QUERY = <<<'GQL'
query($productId: ID) {
    productFilters(productId: $productId) {
        collection { id code name }
        metadata { totalCount }
    }
}
GQL;

const PRODUCT_FILTER_QUERY = <<<'GQL'
query($id: ID!) {
    productFilter(id: $id) { id code }
}
GQL;

const UPDATE_PRODUCT_FILTER_MUTATION = <<<'GQL'
mutation($input: UpdateProductFilterInput!) {
    updateProductFilter(input: $input) { id name }
}
GQL;

const DESTROY_PRODUCT_FILTER_MUTATION = <<<'GQL'
mutation($input: DestroyProductFilterInput!) {
    destroyProductFilter(input: $input) { id }
}
GQL;

it('creates, fetches, updates and destroys a product', function (): void {
    [$organization, $user] = gqlCatalogSetup();

    $metric = \App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $id = gqlPost(CREATE_PRODUCT_MUTATION, ['input' => [
        'name' => 'Seats product',
        'code' => 'seats_product',
        'productType' => 'metered',
        'billableMetricId' => $metric->id,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createProduct.id');

    expect($id)->not->toBeNull();

    expect(gqlPost(PRODUCT_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.product.code'))->toBe('seats_product');

    // An unknown id answers the not_found error envelope.
    gqlPost(PRODUCT_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_PRODUCT_MUTATION, ['input' => [
        'id' => $id,
        'name' => 'Renamed product',
        'code' => 'seats_product',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateProduct.name'))
        ->toBe('Renamed product');

    expect(gqlPost(DESTROY_PRODUCT_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyProduct.id'))->toBe($id);
});

it('lists products through products with the category filters', function (): void {
    [$organization, $user] = gqlCatalogSetup();

    $category = ProductCategory::factory()->create(['organization_id' => $organization->id]);

    Product::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Categorized',
        'code' => 'categorized',
        'product_category_id' => $category->id,
    ]);

    Product::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Free floating',
        'code' => 'floating',
    ]);

    $payload = gqlPost(PRODUCTS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.products');

    expect(count($payload['collection']))->toBe(2);

    $found = gqlPost(PRODUCTS_QUERY, ['searchTerm' => 'categorized'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.products');

    expect(count($found['collection']))->toBe(1)
        ->and($found['collection'][0]['code'])->toBe('categorized');

    $onlyCategorized = gqlPost(PRODUCTS_QUERY, ['productCategoryIds' => [$category->id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.products');

    expect(count($onlyCategorized['collection']))->toBe(1);

    // Rails: without_product_category selects the "no category" value.
    $withoutCategory = gqlPost(PRODUCTS_QUERY, ['withoutProductCategory' => true], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.products');

    expect(count($withoutCategory['collection']))->toBe(1)
        ->and($withoutCategory['collection'][0]['code'])->toBe('floating');
});

it('answers 403 feature_unavailable without the product_catalog flag', function (): void {
    $organization = Organization::factory()->create(['feature_flags' => []]);
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    gqlPost(PRODUCTS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'feature_unavailable');

    gqlPost(PRODUCT_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'feature_unavailable');
});

it('creates, fetches, updates and destroys a product category', function (): void {
    [$organization, $user] = gqlCatalogSetup();

    $id = gqlPost(CREATE_PRODUCT_CATEGORY_MUTATION, ['input' => [
        'name' => 'Category 1',
        'code' => 'category_1',
        'description' => 'A category',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createProductCategory.id');

    expect($id)->not->toBeNull();

    expect(gqlPost(PRODUCT_CATEGORY_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.productCategory.code'))->toBe('category_1');

    // An unknown id answers the not_found error envelope.
    gqlPost(PRODUCT_CATEGORY_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_PRODUCT_CATEGORY_MUTATION, ['input' => [
        'id' => $id,
        'name' => 'Renamed category',
        'code' => 'category_1',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateProductCategory.name'))
        ->toBe('Renamed category');

    $listed = gqlPost(PRODUCT_CATEGORIES_QUERY, ['searchTerm' => 'renamed'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.productCategories');

    expect(count($listed['collection']))->toBe(1);

    expect(gqlPost(DESTROY_PRODUCT_CATEGORY_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyProductCategory.id'))->toBe($id);
});

it('creates, fetches, updates and destroys a product filter', function (): void {
    [$organization, $user] = gqlCatalogSetup();

    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'filterable',
    ]);

    $metric = \App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $metricFilter = \App\Models\BillableMetricFilter::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'key' => 'region',
        'values' => ['eu', 'us'],
    ]);

    $product->billable_metric_id = $metric->id;
    $product->save();

    $id = gqlPost(CREATE_PRODUCT_FILTER_MUTATION, ['input' => [
        'productId' => $product->id,
        'name' => 'Region filter',
        'code' => 'region',
        'values' => [['billableMetricFilterId' => $metricFilter->id, 'value' => 'eu']],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createProductFilter.id');

    expect($id)->not->toBeNull();

    expect(gqlPost(PRODUCT_FILTER_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.productFilter.code'))->toBe('region');

    // An unknown id answers the not_found error envelope.
    gqlPost(PRODUCT_FILTER_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_PRODUCT_FILTER_MUTATION, ['input' => [
        'id' => $id,
        'name' => 'Renamed filter',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateProductFilter.name'))
        ->toBe('Renamed filter');

    $listed = gqlPost(PRODUCT_FILTERS_QUERY, ['productId' => $product->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.productFilters');

    expect(count($listed['collection']))->toBe(1)
        ->and($listed['collection'][0]['code'])->toBe('region');

    expect(gqlPost(DESTROY_PRODUCT_FILTER_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyProductFilter.id'))->toBe($id);
});
