<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use App\GraphQL\Interfaces\AbstractLagoTypeResolver;

/**
 * Type resolver for the frozen SDL's `union Payable` — an "unimplemented" stub
 * (see graphql/FULL_SCHEMA_NOTES.md).
 */
class Payable extends AbstractLagoTypeResolver {}
