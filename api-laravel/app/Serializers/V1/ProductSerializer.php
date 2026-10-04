<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Product;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::ProductSerializer
 * (app/serializers/v2/product_serializer.rb).
 */
final class ProductSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Product $product */
        $product = $this->model;

        return [
            'lago_id' => $product->id,
            'product_category_code' => $product->productCategory?->code,
            'billable_metric_code' => $product->billableMetric?->code,
            'name' => $product->name,
            'code' => $product->code,
            'description' => $product->description,
            'invoice_display_name' => $product->invoice_display_name,
            'product_type' => $product->getRawOriginal('product_type'),
            // Preloaded by the index so size() reads the loaded association.
            'filters_count' => $product->filters->count(),
            'created_at' => $this->serializeDatetime($product->created_at),
            'updated_at' => $this->serializeDatetime($product->updated_at),
        ];
    }
}
