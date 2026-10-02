<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\GraphQL\Providers\LagoSchemaServiceProvider;

return [
    AppServiceProvider::class,
    LagoSchemaServiceProvider::class,
];
