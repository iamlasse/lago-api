<?php

declare(strict_types=1);

// Generator: serializers inventory from app/serializers/**/*.rb.
// Row ids: ser:<Ruby dotted class name>, e.g. ser:V1.CustomerSerializer.

if (! function_exists('inv_gen_serializers')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     */
    function inv_gen_serializers(string $railsPath): array
    {
        $files = inv_scan_ruby_files($railsPath, 'app/serializers');

        $rows = [];

        foreach ($files as $file) {
            $relative = mb_ltrim(mb_substr($file, mb_strlen($railsPath.'/app/serializers/')), '/');

            $rows[] = [
                'id' => 'ser:'.inv_ruby_class_from_path($relative),
                'name' => inv_ruby_namespace_from_path($relative),
                'source' => 'app/serializers/'.$relative,
            ];
        }

        return inv_sort_rows($rows);
    }
}
