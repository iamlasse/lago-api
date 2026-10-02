<?php

// Generator: services inventory from app/services/**/*.rb.
// Row ids: svc:<dotted namespace class name>, e.g. svc:Invoices.CalculateFeesService.

if (! function_exists('inv_gen_services')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     */
    function inv_gen_services(string $railsPath): array
    {
        $files = inv_scan_ruby_files($railsPath, 'app/services');

        $rows = [];

        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($railsPath.'/app/services/')), '/');

            $rows[] = [
                'id' => 'svc:'.inv_ruby_class_from_path($relative),
                'name' => inv_ruby_namespace_from_path($relative),
                'source' => 'app/services/'.$relative,
            ];
        }

        return inv_sort_rows($rows);
    }
}
