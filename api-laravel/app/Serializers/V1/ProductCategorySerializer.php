<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\ProductCategory;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::ProductCategorySerializer
 * (app/serializers/v2/product_category_serializer.rb).
 */
final class ProductCategorySerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var ProductCategory $productCategory */
        $productCategory = $this->model;

        return [
            'lago_id' => $productCategory->id,
            'name' => $productCategory->name,
            'code' => $productCategory->code,
            'description' => $productCategory->description,
            'invoice_display_name' => $productCategory->invoice_display_name,
            // Preloaded by the index so products_count reads the loaded
            // association.
            'products_count' => $productCategory->products->count(),
            'created_at' => $this->serializeDatetime($productCategory->created_at),
            'updated_at' => $this->serializeDatetime($productCategory->updated_at),
        ];
    }
}
