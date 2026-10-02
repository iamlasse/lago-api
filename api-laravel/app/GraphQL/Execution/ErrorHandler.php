<?php

namespace App\GraphQL\Execution;

use App\GraphQL\Exceptions\ExecutionError;
use Closure;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Execution\ErrorHandler;

/**
 * Formats GraphQL errors as `{message, extensions: {status, code, details?}}`
 * — the wire shape Rails produces via ExecutionErrorResponder.
 *
 * App\GraphQL\Exceptions\ExecutionError already carries its final extensions,
 * so this handler mainly guarantees that anything a resolver throws from the
 * ported service layer surfaces with those extensions instead of Lighthouse's
 * defaults; every other error passes through untouched (Validation /
 * Reporting handlers keep their jobs).
 */
class LagoErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        if ($error === null) {
            return $next(null);
        }

        $underlying = $error->getPrevious();

        if ($underlying instanceof ExecutionError) {
            return $next(new Error(
                $underlying->getMessage(),
                $error->getNodes(),
                $error->getSource(),
                $error->getPositions(),
                $error->getPath(),
                $underlying,
                $underlying->getExtensions(),
            ));
        }

        return $next($error);
    }
}
