<?php

declare(strict_types=1);

// INTERIM generator: REST route inventory parsed STATICALLY from Rails'
// config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb.
//
// THE PLAN FORBIDS TRUSTING THIS PERMANENTLY: "live route dump (bin/rails
// runner over Rails.application.routes) — never parse routes.rb statically".
// Static parsing cannot see environment-conditional blocks, gem defaults, or
// constraint lambdas. Every row is marked "provisional": true and the
// artifact carries a loud warning. It exists so the coverage ledger has
// something to join against until gen_routes.rb runs inside the Rails docker
// image (see scripts/inventory/README.md).
//
// Supports the DSL subset these files actually use: resources/resource blocks
// (only:, except:, param:, controller:, module:, path:, constraints:, code:),
// nesting, namespace/scope (incl. module:), collection/member blocks, on:
// routes, draw(:file), get/post/put/patch/delete/match (to:/action:/via:),
// and environment/ENV conditionals (parsed transparently — provisional
// anyway, so env-specific routes are included on purpose).

if (! function_exists('inv_gen_routes_from_source')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     */
    function inv_gen_routes_from_source(string $railsPath): array
    {
        $lines = inv_routes_load_file($railsPath, 'config/routes.rb', 0);

        $parser = new InvRouteParser($railsPath);
        $rows = $parser->parse($lines);

        $seen = [];

        return inv_sort_rows(array_values(array_filter($rows, function (array $row) use (&$seen): bool {
            if (isset($seen[$row['id']])) {
                return false;
            }

            $seen[$row['id']] = true;

            return true;
        })));
    }
}

if (! function_exists('inv_routes_load_file')) {
    /**
     * @return string[] logical lines, comments stripped, draw(:x) spliced in
     */
    function inv_routes_load_file(string $railsPath, string $relative, int $depth): array
    {
        if ($depth > 3) {
            throw new RuntimeException("draw() nesting too deep at [$relative]");
        }

        inv_assert_rails_path($railsPath, $relative);

        $lines = [];

        foreach (preg_split('/\r?\n/', (string) file_get_contents($railsPath.'/'.$relative)) as $line) {
            $trimmed = mb_trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^draw\(\s*:(\w+)\s*\)/', $trimmed, $m) === 1) {
                $lines = array_merge(
                    $lines,
                    inv_routes_load_file($railsPath, "config/routes/{$m[1]}.rb", $depth + 1)
                );

                continue;
            }

            $lines[] = $trimmed;
        }

        return $lines;
    }
}

if (! function_exists('inv_routes_statements')) {
    /**
     * Joins physical lines into logical statements (continues while brackets
     * are unbalanced or the line ends with a trailing comma).
     *
     * @param  string[]  $lines
     * @return string[]
     */
    function inv_routes_statements(array $lines): array
    {
        $statements = [];
        $current = '';
        $balance = 0;

        foreach ($lines as $line) {
            $current = $current === '' ? $line : $current.' '.$line;
            $balance += inv_routes_bracket_delta($line);

            if ($balance > 0 || str_ends_with(mb_rtrim($current), ',')) {
                continue;
            }

            $statements[] = mb_trim($current);
            $current = '';
            $balance = 0;
        }

        if (mb_trim($current) !== '') {
            $statements[] = mb_trim($current);
        }

        return $statements;
    }
}

if (! function_exists('inv_routes_bracket_delta')) {
    /**
     * Net (), [], {} delta of a line, ignoring double-quoted strings and
     * /regex/ literals.
     */
    function inv_routes_bracket_delta(string $line): int
    {
        $delta = 0;
        $length = mb_strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"') {
                $i++;

                while ($i < $length && $line[$i] !== '"') {
                    $i += $line[$i] === '\\' ? 2 : 1;
                }

                continue;
            }

            if ($char === '/' && preg_match('/\/(?:[^\/\\\\]|\\\\.)*\//', $line, $m, 0, $i) === 1) {
                $i += mb_strlen($m[0]) - 1;

                continue;
            }

            $delta += match ($char) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
        }

        return $delta;
    }
}

class InvRouteParser
{
    /** @var array<int, array<string, mixed>> */
    private array $frames = [];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly string $railsPath) {}

    /**
     * @param  string[]  $lines
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $lines): array
    {
        foreach (inv_routes_statements($lines) as $statement) {
            $this->statement($statement);
        }

        return $this->rows;
    }

    private function statement(string $line): void
    {
        if ($line === 'end') {
            array_pop($this->frames);

            return;
        }

        // Transparent blocks: routes.draw do / if / unless.
        if (preg_match('/^(Rails\.application\.routes\.draw\b|if\b|unless\b)/', $line) === 1) {
            $this->frames[] = ['type' => 'plain'];

            return;
        }

        if (preg_match('/^namespace\s+:(\w+)\s*(?:,\s*(.*?))?\s*do$/', $line, $m) === 1) {
            $options = inv_routes_options($m[2] ?? '');
            $this->frames[] = [
                'type' => 'namespace',
                'path' => $m[1],
                'module' => isset($options['module']) ? mb_ltrim((string) $options['module'], ':/') : $m[1],
            ];

            return;
        }

        if (preg_match('/^scope\b\s*(.*?)\s*do$/', $line, $m) === 1) {
            $options = inv_routes_options($m[1]);
            $this->frames[] = [
                'type' => 'scope',
                'path' => isset($options['path']) ? mb_trim((string) $options['path'], '/') : '',
                'module' => isset($options['module']) ? mb_trim(mb_ltrim(mb_trim((string) $options['module'], ':'), '/')) : '',
            ];

            return;
        }

        if (preg_match('/^(collection|member)\s+do$/', $line, $m) === 1) {
            $resource = $this->nearestResource();

            if ($resource === null) {
                $this->frames[] = ['type' => 'plain'];

                return;
            }

            $this->frames[] = [
                'type' => $m[1],
                'path' => $m[1] === 'collection' ? $resource['collection_path'] : $resource['member_path'],
            ];

            return;
        }

        if (preg_match('/^(resources|resource)\s+:(\w+)\s*(.*?)(?:\s+(do))?$/', $line, $m) === 1) {
            $this->openResource($m[1] === 'resources', $m[2], $m[3], ($m[4] ?? '') === 'do');

            return;
        }

        if ($this->route($line)) {
            return;
        }

        // mount / root / unknown statements are ignored.
        $this->frames[] = ['type' => 'plain'];
    }

    private function openResource(bool $plural, string $name, string $optionString, bool $hasBlock): void
    {
        $options = inv_routes_options($optionString);

        $context = $this->routeContext();

        $base = $context['member_base'] ?? $context['path_prefix'];
        $pathSegment = isset($options['path']) ? mb_trim((string) $options['path'], '/') : $name;
        $collectionPath = inv_routes_join_path($base, $pathSegment);

        $param = mb_ltrim((string) ($options['param'] ?? 'id'), ':');

        $frame = [
            'type' => 'resource',
            'name' => $name,
            'plural' => $plural,
            'param' => $param,
            'collection_path' => $collectionPath,
            'member_path' => $plural ? inv_routes_join_path($collectionPath, ':'.$param) : $collectionPath,
            'module' => $context['module_prefix'],
            'only' => $options['only'] ?? null,
            'except' => $options['except'] ?? null,
            'controller' => $options['controller'] ?? null,
        ];

        $this->frames[] = $frame;

        $this->emitResourceRoutes($frame);

        if (! $hasBlock) {
            array_pop($this->frames);
        }
    }

    /**
     * Standard RESTful action set for a (plural | singular) resource.
     *
     * @return array<int, array{action: string, verb: string, path: string, on: string}>
     */
    private function resourceActions(array $frame): array
    {
        $collection = (string) $frame['collection_path'];
        $member = (string) $frame['member_path'];

        if ($frame['plural']) {
            return [
                ['action' => 'index', 'verb' => 'GET', 'path' => $collection, 'on' => 'collection'],
                ['action' => 'create', 'verb' => 'POST', 'path' => $collection, 'on' => 'collection'],
                ['action' => 'new', 'verb' => 'GET', 'path' => inv_routes_join_path($collection, 'new'), 'on' => 'collection'],
                ['action' => 'show', 'verb' => 'GET', 'path' => $member, 'on' => 'member'],
                ['action' => 'edit', 'verb' => 'GET', 'path' => inv_routes_join_path($member, 'edit'), 'on' => 'member'],
                ['action' => 'update', 'verb' => 'PATCH', 'path' => $member, 'on' => 'member'],
                ['action' => 'update', 'verb' => 'PUT', 'path' => $member, 'on' => 'member'],
                ['action' => 'destroy', 'verb' => 'DELETE', 'path' => $member, 'on' => 'member'],
            ];
        }

        return [
            ['action' => 'create', 'verb' => 'POST', 'path' => $member, 'on' => 'member'],
            ['action' => 'new', 'verb' => 'GET', 'path' => inv_routes_join_path($member, 'new'), 'on' => 'member'],
            ['action' => 'show', 'verb' => 'GET', 'path' => $member, 'on' => 'member'],
            ['action' => 'edit', 'verb' => 'GET', 'path' => inv_routes_join_path($member, 'edit'), 'on' => 'member'],
            ['action' => 'update', 'verb' => 'PATCH', 'path' => $member, 'on' => 'member'],
            ['action' => 'update', 'verb' => 'PUT', 'path' => $member, 'on' => 'member'],
            ['action' => 'destroy', 'verb' => 'DELETE', 'path' => $member, 'on' => 'member'],
        ];
    }

    private function emitResourceRoutes(array $frame): void
    {
        $allowed = inv_routes_action_filter($frame['only'], $frame['except']);

        foreach ($this->resourceActions($frame) as $action) {
            if (! in_array($action['action'], $allowed, true)) {
                continue;
            }

            $this->emit(
                $action['verb'],
                $action['path'],
                inv_routes_handler(null, (string) $frame['module'], $frame['controller'], $frame['name'], $action['action']),
                $action['on']
            );
        }
    }

    /**
     * Handles get/post/put/patch/delete/match; returns false when the line is
     * not a route declaration.
     */
    private function route(string $line): bool
    {
        if (preg_match('/^(get|post|put|patch|delete|match)\s+(.+)$/', $line, $m) !== 1) {
            return false;
        }

        $verb = mb_strtoupper($m[1]);
        $rest = mb_rtrim($m[2], ',');
        $context = $this->routeContext();

        $pathArg = null;
        $handlerArg = null;
        $options = [];

        // Hash-rocket style: match "*unmatched" => "application#not_found", :via => [...]
        if (preg_match('/^(?<path>"[^"]*")\s*=>\s*(?<handler>"[^"]*")\s*(?:,\s*(.*))?$/', $rest, $m) === 1) {
            $pathArg = mb_trim($m['path'], '"');
            $handlerArg = mb_trim($m['handler'], '"');
            $options = inv_routes_options($m[1] ?? '');
        } else {
            [$pathArg, $optionString] = inv_routes_split_first_arg($rest);
            $options = inv_routes_options($optionString);

            if (isset($options['to'])) {
                $handlerArg = (string) $options['to'];
            }
        }

        if ($pathArg === null) {
            return true;
        }

        // Path base: explicit on: wins, then the enclosing collection/member
        // block, then the nearest resource's member path (a bare `get :x`
        // inside a resources block is a member route), then the namespace
        // prefix.
        $on = (string) ($options['on'] ?? '');
        $resource = $this->nearestResource();

        $base = match ($on) {
            'collection' => $resource['collection_path'] ?? $context['path_prefix'],
            'member' => $resource['member_path'] ?? $context['path_prefix'],
            default => $context['route_base'],
        };

        $quoted = str_starts_with((string) $pathArg, '"');
        $rawPath = mb_trim((string) $pathArg, '"');

        if ($quoted) {
            // Quoted segments are literal, ":key" included — keep params.
            $path = inv_routes_join_path($base, $rawPath);
            $action = (string) ($options['action'] ?? mb_ltrim(basename($rawPath), ':'));
        } elseif (str_starts_with($rawPath, ':')) {
            // Symbol segment: a literal path part named after the symbol.
            $segment = mb_ltrim($rawPath, ':');
            $path = inv_routes_join_path($base, $segment);
            $action = (string) ($options['action'] ?? $segment);
        } else {
            $path = inv_routes_join_path($base, $rawPath);
            $action = (string) ($options['action'] ?? mb_ltrim(basename($rawPath), ':'));
        }

        $action = mb_ltrim($action, ':');

        if ($handlerArg !== null) {
            $handler = inv_routes_handler($handlerArg, $context['module_prefix'], $options['controller'] ?? null, '', $action);
        } else {
            // No to:: the controller is the enclosing resource's (explicit
            // option, else the resource name under the current module).
            $controller = $options['controller']
                ?? ($resource['controller'] ?? null)
                ?? ($resource['name'] ?? '');
            $handler = inv_routes_handler(null, $context['module_prefix'], $controller, '', $action);
        }

        foreach ($verb === 'MATCH' ? inv_routes_via_verbs($options['via'] ?? null) : [$verb] as $singleVerb) {
            $this->emit($singleVerb, $path, $handler, $on !== '' ? $on : null);
        }

        return true;
    }

    private function emit(string $verb, string $path, string $handler, ?string $on): void
    {
        $path = inv_routes_normalize_path($path);

        if ($handler === '' || str_starts_with($handler, '#') || ! in_array($verb, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $this->rows[] = [
            'id' => "rest:$verb:$path",
            'verb' => $verb,
            'path' => $path,
            'handler' => $handler,
            'on' => $on,
            'provisional' => true,
            'source' => 'config/routes.rb (static parse — provisional)',
        ];
    }

    /** @return array<string, mixed>|null */
    private function nearestResource(): ?array
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            if ($this->frames[$i]['type'] === 'resource') {
                return $this->frames[$i];
            }
        }

        return null;
    }

    /**
     * Everything a route line needs to know, implied by the open frames:
     * the namespace path prefix, the default base for bare route lines and
     * the module prefix controllers resolve against.
     *
     * @return array{path_prefix: string, route_base: string, module_prefix: string, member_base: ?string}
     */
    private function routeContext(): array
    {
        $pathParts = [];
        $moduleParts = [];
        $memberBase = null;
        $routeBase = '';

        foreach ($this->frames as $frame) {
            switch ($frame['type']) {
                case 'namespace':
                case 'scope':
                    if (($frame['path'] ?? '') !== '') {
                        $pathParts[] = $frame['path'];
                    }

                    if (($frame['module'] ?? '') !== '') {
                        $moduleParts[] = $frame['module'];
                    }

                    break;

                case 'resource':
                    $memberBase = $frame['member_path'];

                    break;

                case 'collection':
                case 'member':
                    $routeBase = $frame['path'];

                    break;
            }
        }

        $pathPrefix = $pathParts === [] ? '/' : '/'.implode('/', $pathParts);

        return [
            'path_prefix' => $pathPrefix,
            'route_base' => $routeBase !== '' ? $routeBase : ($memberBase ?? $pathPrefix),
            'module_prefix' => implode('/', $moduleParts),
            'member_base' => $memberBase,
        ];
    }
}

if (! function_exists('inv_routes_split_first_arg')) {
    /**
     * Splits "first argument, options" into [first (raw), options].
     *
     * @return array{0: string, 1: string}
     */
    function inv_routes_split_first_arg(string $rest): array
    {
        $length = mb_strlen($rest);

        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];

            if ($char === '"') {
                $i++;

                while ($i < $length && $rest[$i] !== '"') {
                    $i += $rest[$i] === '\\' ? 2 : 1;
                }

                continue;
            }

            if ($char === ',') {
                return [mb_trim(mb_substr($rest, 0, $i)), mb_trim(mb_substr($rest, $i + 1))];
            }
        }

        return [mb_trim($rest), ''];
    }
}

if (! function_exists('inv_routes_options')) {
    /**
     * Parses a Rails route option string into a plain map. only:/except:/via:
     * values become string arrays; everything else stays a raw string.
     *
     * @return array<string, string|list<string>>
     */
    function inv_routes_options(string $options): array
    {
        $options = mb_trim($options);

        if ($options === '') {
            return [];
        }

        $parsed = [];
        $length = mb_strlen($options);
        $i = 0;

        while ($i < $length) {
            if (preg_match('/\G\s*(?::(\w+)\s*=>|(\w+)\s*:)/', $options, $m, 0, $i) !== 1) {
                $i++;

                continue;
            }

            $key = (($m[1] ?? '') !== '') ? $m[1] : $m[2];
            $i += mb_strlen($m[0]);
            [$value, $i] = inv_routes_read_value($options, $i);

            $parsed[$key] = in_array($key, ['only', 'except', 'via'], true)
                ? inv_routes_sym_list($value)
                : $value;
        }

        return $parsed;
    }
}

if (! function_exists('inv_routes_read_value')) {
    /**
     * Reads one value token starting at $offset (quotes, %i[] word lists,
     * balanced [] / {} / (), lambda blocks, or a bare token up to the next
     * top-level comma).
     *
     * @return array{0: string, 1: int} [raw value, next offset]
     */
    function inv_routes_read_value(string $subject, int $offset): array
    {
        $length = mb_strlen($subject);

        while ($offset < $length && in_array($subject[$offset], [' ', "\t"], true)) {
            $offset++;
        }

        if ($offset >= $length) {
            return ['', $offset];
        }

        $char = $subject[$offset];

        if ($char === '"' || $char === "'") {
            $end = mb_strpos($subject, $char, $offset + 1);
            $end = $end === false ? $length : $end;

            return [mb_substr($subject, $offset + 1, $end - $offset - 1), $end + 1];
        }

        if ($char === '[' || $char === '{' || $char === '(') {
            [$raw, $end] = inv_routes_read_balanced($subject, $offset);

            return [$raw, $end];
        }

        $end = $offset;
        $depth = 0;

        while ($end < $length) {
            $current = $subject[$end];

            if ($current === '"') {
                $end++;

                while ($end < $length && $subject[$end] !== '"') {
                    $end += $subject[$end] === '\\' ? 2 : 1;
                }
            } elseif ($current === '{' || $current === '(') {
                $depth++;
            } elseif ($current === '}' || $current === ')') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($current === ',' && $depth === 0) {
                break;
            }

            $end++;
        }

        return [mb_trim(mb_substr($subject, $offset, $end - $offset)), $end + 1];
    }
}

if (! function_exists('inv_routes_read_balanced')) {
    /**
     * @return array{0: string, 1: int} [balanced raw text, next offset]
     */
    function inv_routes_read_balanced(string $subject, int $offset): array
    {
        $open = $subject[$offset];
        $close = match ($open) {
            '[' => ']',
            '{' => '}',
            default => ')',
        };

        $depth = 0;
        $length = mb_strlen($subject);
        $start = $offset;

        while ($offset < $length) {
            $char = $subject[$offset];

            if ($char === '"') {
                $offset++;

                while ($offset < $length && $subject[$offset] !== '"') {
                    $offset += $subject[$offset] === '\\' ? 2 : 1;
                }
            } elseif ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return [mb_substr($subject, $start, $offset - $start + 1), $offset + 1];
                }
            }

            $offset++;
        }

        return [mb_substr($subject, $start), $length];
    }
}

if (! function_exists('inv_routes_sym_list')) {
    /**
     * %i[index show destroy], [:index, "show"], :index, "index show" → list.
     *
     * @return list<string>
     */
    function inv_routes_sym_list(string $raw): array
    {
        $raw = mb_trim($raw);

        if (preg_match('/^%?[iw]?\[?\s*\]?$/', $raw) === 1) {
            return [];
        }

        if (preg_match('/^(?:%[iw])?\[(.*)\]$/s', $raw, $m) === 1) {
            $raw = $m[1];
        }

        $parts = str_contains($raw, ',') ? explode(',', $raw) : (preg_split('/\s+/', mb_trim($raw)) ?: []);

        return array_values(array_filter(
            array_map(static fn (string $part): string => mb_trim(mb_trim(mb_trim($part), '"'), ':'), $parts),
            static fn (string $part): bool => $part !== ''
        ));
    }
}

if (! function_exists('inv_routes_action_filter')) {
    /**
     * @param  mixed  $only  string|list<string>|null
     * @param  mixed  $except  string|list<string>|null
     * @return list<string>
     */
    function inv_routes_action_filter(mixed $only, mixed $except): array
    {
        $all = ['index', 'new', 'create', 'show', 'edit', 'update', 'destroy'];

        if (is_array($only)) {
            return $only === [] ? [] : array_values(array_intersect($all, $only));
        }

        if (is_array($except)) {
            return array_values(array_diff($all, $except));
        }

        return $all;
    }
}

if (! function_exists('inv_routes_handler')) {
    /**
     * Resolves the effective "controller#action". A controller is absolute
     * (escapes the module prefix) only when its raw source string started
     * with "/" — Rails' `controller: "/api/v1/plans/charges"` idiom.
     */
    function inv_routes_handler(?string $target, string $modulePrefix, string|array|null $controllerOption, string $fallbackController, string $action): string
    {
        $controller = null;

        if ($target !== null) {
            [$controller, $targetAction] = array_pad(explode('#', $target, 2), 2, '');

            if ($targetAction !== '') {
                $action = $targetAction;
            }
        } elseif ($controllerOption !== null && $controllerOption !== '') {
            $controller = mb_trim((string) (is_array($controllerOption) ? '' : $controllerOption), '/');
        } else {
            $controller = $fallbackController;
        }

        $raw = $target ?? (is_string($controllerOption) ? $controllerOption : null);
        $absolute = $raw !== null && str_starts_with(mb_ltrim($raw), '/');

        $controller = mb_trim((string) $controller, '/');

        if ($controller === '') {
            return '';
        }

        $full = $absolute ? '/'.mb_ltrim($controller, '/') : inv_routes_join_path($modulePrefix, $controller);

        return $action === '' ? $full : $full.'#'.$action;
    }
}

if (! function_exists('inv_routes_via_verbs')) {
    /**
     * @return list<string>
     */
    function inv_routes_via_verbs(string|array|null $via): array
    {
        if ($via === null) {
            return [];
        }

        $map = ['get' => 'GET', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'];
        $verbs = [];

        foreach ((array) $via as $raw) {
            foreach (inv_routes_sym_list((string) $raw) as $verb) {
                $upper = $map[mb_strtolower($verb)] ?? mb_strtoupper($verb);

                if (! in_array($upper, $verbs, true)) {
                    $verbs[] = $upper;
                }
            }
        }

        return $verbs;
    }
}

if (! function_exists('inv_routes_join_path')) {
    function inv_routes_join_path(string ...$parts): string
    {
        $joined = '';

        foreach ($parts as $part) {
            if ($part === '' || $part === '/') {
                continue;
            }

            $joined .= '/'.mb_trim($part, '/');
        }

        return $joined === '' ? '/' : $joined;
    }
}

if (! function_exists('inv_routes_normalize_path')) {
    function inv_routes_normalize_path(string $path): string
    {
        $path = mb_rtrim((string) preg_replace('#/+#', '/', $path), '/');

        return $path === '' ? '/' : $path;
    }
}
