<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataExport;
use App\Models\DataExportPart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for `data_export_parts` (Rails' :data_export_part factory).
 *
 * @extends Factory<DataExportPart>
 */
class DataExportPartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'data_export_id' => DataExportFactory::new(),
            'organization_id' => OrganizationFactory::new(),
            'index' => 0,
            'object_ids' => [],
            'completed' => false,
            'csv_lines' => null,
        ];
    }

    public function forDataExport(DataExport $dataExport): static
    {
        return $this->state(fn (): array => [
            'data_export_id' => $dataExport->id,
            'organization_id' => $dataExport->organization_id,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed' => true]);
    }
}
