<?php

declare(strict_types=1);

use Tests\TestCase;
use Tests\Concerns\FrozenSchemaDatabase;

pest()->extend(TestCase::class)
    ->use(FrozenSchemaDatabase::class)
    ->in('Feature', 'Unit');
