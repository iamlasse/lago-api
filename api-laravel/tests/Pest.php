<?php

use Tests\Concerns\FrozenSchemaDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(FrozenSchemaDatabase::class)
    ->in('Feature', 'Unit');
