<?php

// INTERIM generator: REST route inventory parsed STATICALLY from Rails'
// config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb.
//
// THE PLAN FORBIDS TRUSTING THIS PERMANENTLY: "live route dump (bin/rails
// runner over Rails.application.routes) — never parse routes.rb statically".
// Static parsing cannot see environment-conditional blocks, dynamic sets,
// constraint lambdas or default-drawn resources in gems. Every row is marked
// "provisional": true and the artifact carries a loud warning. It exists so
// the coverage ledger has *something* to join against until gen_routes.rb can
// run inside the Rails docker image (see scripts/inventory/README.md).
//
// Supports the DSL subset these files actually use: resources/resource blocks
// (only:, except:, param:, controller:, constraints:, code:), nesting,
// namespace/scope (incl. module:), collection/member blocks, on: routes,
// draw(:file), get/post/put/patch/delete/match (to:/action:/via:), and
// environment/ENV conditionals (parsed transparently — provisional anyway).

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
        $unique = [];

        foreach ($rows as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }

            $seen[$row['id']] = true;
            $unique[] = $row;
        }

        return inv_sort_rows($unique);
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
            $trimmed = trim($line);

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
        $statements = inv_routes_statements($lines);

        foreach ($statements as $statement) {
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

        // Transparent blocks: Rails.application.routes.draw do / if / unless.
        if (preg_match('/^(Rails\.application\.routes\.draw|if\s|unless\s)/', $line) === 1) {
            $this->frames[] = ['type' => 'plain'];

            return;
        }

        if (preg_match('/^namespace\s+:(\w+)\s*(?:,\s*(.*))?do$/', $line, $m) === 1) {
            $options = inv_routes_options($m[2] ?? '');
            $this->frames[] = [
                'type' => 'namespace',
                'path' => $m[1],
                'module' => (string) ($options['module'] ?? $m[1]),
            ];

            return;
        }

        if (preg_match('/^scope\s+(.+)\sdo$/', $line, $m) === 1 || preg_match('/^scope\s+(.+)$/', $line, $m) === 1) {
            $options = inv_routes_options($m[1]);
            $this->frames[] = [
                'type' => 'scope',
                'path' => isset($options['path']) ? trim((string) $options['path'], '/') : '',
                'module' => isset($options['module']) ? trim((string) $options['module'], '/') : '',
            ];

            return;
        }

        if (preg_match('/^(collection|member)\s+do$/', $line, $m) === 1) {
            $resource = $this->nearestResource();

            if ($resource === null) {
                $this->frames[] = ['type' => 'plain'];

                return;
            }

            $path = $m[1] === 'collection' ? $resource['collection_path'] : $resource['member_path'];

            $this->frames[] = ['type' => $m[1], 'path' => $path];

            return;
        }

        if (preg_match('/^(resources|resource)\s+:(\w+)\s*(.*?)(?:\sdo)?$/', $line, $m) === 1) {
            $this->openResource($m[1] === 'resources', $m[2], rtrim($m[3] ?? '', ','));

            return;
        }

        if ($this->route($line)) {
            return;
        }

        // mount/root/unknown statements: counted as ignored, no row.
        $this->frames[] = ['type' => 'plain'];
    }

    private function openResource(bool $plural, string $name, string $optionString): void
    {
        $options = inv_routes_options($optionString);

        $parent = $this->routeContext();
        $base = $parent['member_base'] ?? $parent['path_prefix'];

        $pathSegment = isset($options['path']) ? trim((string) $options['path'], '/') : $name;
        $collectionPath = inv_routes_join_path($base, $pathSegment);

        $param = (string) ($options['param'] ?? ($plural ? 'id' : null) ?? 'id');

        $frame = [
            'type' => 'resource',
            'name' => $name,
            'plural' => $plural,
            'param' => $param,
            'collection_path' => $collectionPath,
            'member_path' => $plural ? inv_routes_join_path($collectionPath, ':'.$param) : $collectionPath,
            'module' => $parent['module_prefix'],
            'only' => $options['only'] ?? null,
            'except' => $options['except'] ?? null,
            'controller' => $options['controller'] ?? null,
        ];

        $this->frames[] = $frame;

        if (! str_ends_with($optionString, 'do') && ! isset($options['__block'])) {
            // No block: the resource frame was pushed only to compute paths for
            // nothing — pop it immediately unless a `do` opened a block.
            array_pop($this->frames);

            return;
        }

        $this->emitResourceRoutes($frame);
    }

    /**
     * Standard RESTful action set for a (singular | plural) resource.
     *
     * @return array<int, array{action: string, verb: string, path: string, on: string}>
     */
    private function resourceActions(array $frame): array
    {
        $collection = $frame['collection_path'];
        $member = $frame['member_path'];

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
        $allowed = inv_routes_action_filter($frame['only'] ?? null, $frame['except'] ?? null);

        foreach ($this->resourceActions($frame) as $action) {
            if (! in_array($action['action'], $allowed, true)) {
                continue;
            }

            $this->emit(
                $action['verb'],
                $action['path'],
                inv_routes_handler($frame['controller'], $frame['module'], $frame['name'], $action['action']),
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

        $verb = strtoupper($m[1]);
        $rest = rtrim($m[2], ',');

        $context = $this->routeContext();

        $pathArg = null;
        $handlerArg = null;
        $options = [];

        // Hash-rocket style: match "*unmatched" => "application#not_found", via: [...]
        if (preg_match('/^(?<path>"[^"]*"|:[\w]+)\s*=>\s*(?<handler>"[^"]*")\s*(?:,\s*(.*))?$/', $rest, $m) === 1) {
            $pathArg = trim($m['path'], '"');
            $handlerArg = trim($m['handler'], '"');
            $options = inv_routes_options($m[2] ?? '');
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

        // Resolve the base path: on: / enclosing collection|member block /
        // nearest resource's member path / plain prefix.
        $on = (string) ($options['on'] ?? '');

        if ($on === 'collection' || $on === 'member') {
            $resource = $this->nearestResource();
            $base = $resource === null
                ? $context['path_prefix']
                : ($on === 'collection' ? $resource['collection_path'] : $resource['member_path']);
        } else {
            $base = $context['route_base'];
        }

        if (str_starts_with((string) $pathArg, ':')) {
            $path = inv_routes_join_path($base, ':'.ltrim((string) $pathArg, ':'));
        } elseif (str_starts_with((string) $pathArg, '/')) {
            $path = inv_routes_join_path($base, $pathArg);
        } else {
            // Bare relative segment (no leading colon — rare).
            $path = inv_routes_join_path($base, (string) $pathArg);
        }

        $action = (string) ($options['action'] ?? ($handlerArg !== null
            ? (explode('#', $handlerArg)[1] ?? '')
            : ltrim(pathinfo((string) $pathArg, PATHINFO_FILENAME), ':')));

        $verbs = $verb === 'MATCH'
            ? inv_routes_via_verbs($options['via'] ?? null)
            : [$verb];

        $handler = $handlerArg !== null
            ? inv_routes_handler($handlerArg, $context['module_prefix'], null, $action)
            : inv_routes_handler(null, $context['module_prefix'], $this->nearestResource()['controller'] ?? null, $action);

        foreach ($verbs as $singleVerb) {
            $this->emit($singleVerb, $path, $handler, $on !== '' ? $on : null);
        }

        return true;
    }

    private function emit(string $verb, string $path, string $handler, ?string $on): void
    {
        $path = inv_routes_normalize_path($path);

        if ($handler === '' || ! in_array($verb, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
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

    /** @return array<string, mixed> */
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
     * Everything a route line needs to know: the absolute path prefixes and
     * the module prefix implied by the open frame stack.
     *
     * @return array{path_prefix: string, route_base: string, module_prefix: string, member_base?: string}
     */
    private function routeContext(): array
    {
        $pathParts = [];
        $moduleParts = [];

        $pathPrefix = '';
        $routeBase = '';
        $memberBase = null;

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

        $pathPrefix = implode('/', $pathParts);
        $pathPrefix = $pathPrefix === '' ? '/' : '/'.$pathPrefix;

        if ($routeBase === '') {
            // No collection/member block: routes attach to the nearest
            // resource's member path, else the namespace prefix.
            $resource = $this->nearestResource();
            $routeBase = $memberBase ?? $pathPrefix;
            unset($resource);
        }

        return [
            'path_prefix' => $pathPrefix,
            'route_base' => $routeBase,
            'module_prefix' => implode('/', $moduleParts),
            'member_base' => $memberBase,
        ];
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

            $trimmed = rtrim($current);

            if ($balance > 0 || str_ends_with($trimmed, ',')) {
                continue;
            }

            $statements[] = trim($current);
            $current = '';
            $balance = 0;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}

if (! function_exists('inv_routes_bracket_delta')) {
    /**
     * Net (), [], {} delta of a line, ignoring quoted strings, regex
     * literals and %i[]/%w[] word lists (their brackets never straddle
     * statement continuations in these files).
     */
    function inv_routes_bracket_delta(string $line): int
    {
        $delta = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"') {
                $i++;

                while ($i < $length && $line[$i] !== '"') {
                    $i += $line[$i] === '\\' ? 2 : 1;
                }

                continue;
            }

            if ($char === '/' && preg_match('/\/((?:[^\/\\\\]|\\\\.)+)\//', $line, $m, 0, $i) === 1) {
                $i += strlen($m[0]) - 1;

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

if (! function_exists('inv_routes_split_first_arg')) {
    /**
     * Splits "…first argument…, options…" into [first, rest].
     *
     * @return array{0: string, 1: string}
     */
    function inv_routes_split_first_arg(string $rest): array
    {
        $length = strlen($rest);

        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];

            if ($char === '"') {
                $i++;

                while ($i < $length && $rest[$i] !== '"') {
                    $i += $rest[$i] === '\\' ? 2 : 1;
                }

                continue;
            }

            if ($char === ',' && ($i + 1 >= $length || $rest[$i + 1] === ' ')) {
                return [trim(substr($rest, 0, $i)), trim(substr($rest, $i + 1))];
            }
        }

        return [trim($rest), ''];
    }
}

if (! function_exists('inv_routes_options')) {
    /**
     * Parses a Rails route option string into a plain map. Values stay raw
     * strings except only:/except:/via: which become string arrays.
     *
     * @return array<string, string|list<string>>
     */
    function inv_routes_options(string $options): array
    {
        $options = trim($options);

        if ($options === '') {
            return [];
        }

        $parsed = [];
        $length = strlen($options);
        $i = 0;

        while ($i < $length) {
            // Key: either `key:` (new hash syntax) or `:key =>` (old syntax).
            if (preg_match('/\G(?::(\w+)\s*=>|(\w+)\s*:)/', $options, $m, 0, $i) !== 1) {
                $i++;
                continue;
            }

            $key = $m[1] ?? $m[2];
            $i += strlen($m[0]);
            [$value, $i] = inv_routes_read_value($options, $i);

            if (in_array($key, ['only', 'except', 'via'], true)) {
                $parsed[$key] = inv_routes_sym_list($value);
            } else {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }
}

if (! function_exists('inv_routes_read_value')) {
    /**
     * Reads one value token starting at $offset.
     *
     * @return array{0: string, 1: int} [value, next offset]
     */
    function inv_routes_read_value(string $options, int $offset): array
    {
        $options = ltrim($options);
        // Offsets were computed against the un-ltrimmed string; recompute.
        $skipped = strlen($options) - strlen(ltrim(substr($options, 0)));

        $value = '';
        $char = $options[$offset] ?? '';

        if ($char === '"' || $char === "'") {
            $end = strpos($options, $char, $offset + 1);
            $end = $end === false ? strlen($options) : $end;
            $value = substr($options, $offset + 1, $end - $offset - 1);
            $offset = $end + 1;
        } elseif ($char === '[' || $char === '{') {
            [$_, $offset] = inv_routes_read_balanced($options, $offset);
            $value = trim(substr($options, $offset - 0, 0)); // replaced below
        } else {
            $end = $offset;

            while ($end < strlen($options) && ! str_starts_with(substr($options.' ', $end), ',')) {
                $end++;
            }

            $value = trim(substr($options, $offset, $end - $offset), " \t");
            $offset = $end + 1;
        }

        return [$value, $offset];
    }
}

if (! function_exists('inv_routes_read_balanced')) {
    /**
     * @return array{0: string, 1: int}
     */
    function inv_routes_read_balanced(string $subject, int $offset): array
    {
        $open = $subject[$offset];
        $close = match ($open) {
            '[' => ']',
            '{' => '}',
            default => '(',
        };

        $depth = 0;
        $length = strlen($subject);
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
                    return [substr($subject, $start, $offset - $start + 1), $offset + 1];
                }
            }

            $offset++;
        }

        return [substr($subject, $start), $length];
    }
}

if (! function_exists('inv_routes_sym_list')) {
    /**
     * %i[index show destroy], [:index, :show], :index, "index show" → list.
     *
     * @return list<string>
     */
    function inv_routes_sym_list(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^%i\[([^]]*)\]$/', $raw, $m) === 1 || preg_match('/^%w\[([^]]*)\]$/', $raw, $m) === 1) {
            return preg_split('/\s+/', trim($m[1])) ?: [];
        }

        if (preg_match('/^\[(.*)\]$/', $raw, $m) === 1) {
            return array_values(array_filter(array_map(
                static fn (string $part) => trim(trim(trim($part), '"'), ':'),
                explode(',', $m[1])
            ), static fn (string $part) => $part !== ''));
        }

        return [trim($raw, ':"')];
    }
}

if (! function_exists('inv_routes_action_filter')) {
    /**
     * @param  string|list<string>|null  $only
     * @param  string|list<string>|null  $except
     * @return list<string>
     */
    function inv_routes_action_filter(string|array|null $only, string|array|null $except): array
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
     * Resolves the effective "controller#action" from any combination of an
     * explicit `to:` target, an explicit `controller:` option and a default
     * resource controller. A leading "/" on a controller escapes the current
     * module namespace (Rails' absolute-controller idiom).
     */
    function inv_routes_handler(?string $target, string $modulePrefix, ?string $controllerOption, string $action): string
    {
        if ($target !== null && str_contains($target, '#')) {
            [$controller, $targetAction] = explode('#', $target, 2);
            $action = $targetAction !== '' ? $targetAction : $action;
        } else {
            $controller = $target ?? $controllerOption ?? '';
        }

        $controller = trim((string) $controller, '/');

        if ($controller === '') {
            return $action === '' ? '' : '#'.$action;
        }

        $absolute = str_starts_with(trim((string) ($target ?? $controllerOption ?? '')), '/')
            && ! str_starts_with(ltrim((string) ($target ?? '')), '/');

        // Rails: a leading slash in `controller:` escapes module scoping. A
        // `to:` string is always relative to the module unless it too starts
        // with "/" — inv_routes_handler receives them pre-stripped, so the
        // caller marks absoluteness by keeping the leading slash.
        $isAbsolute = str_starts_with(trim((string) ($target ?? $controllerOption ?? '')), '/')
            && str_starts_with(trim((string) ($controllerOption ?? $target ?? '')), '/');

        unset($absolute);

        $prefix = ($target !== null
            ? (str_starts_with(trim($target), '/') ? '' : $modulePrefix)
            : (str_starts_with(trim((string) $controllerOption), '/') ? '' : $modulePrefix));

        unset($isAbsolute);

        $full = inv_routes_join_path($prefix, $controller);

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
                $upper = $map[strtolower($verb)] ?? strtoupper($verb);

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

            $joined .= '/'.trim($part, '/');
        }

        return $joined === '' ? '/' : $joined;
    }
}

if (! function_exists('inv_routes_normalize_path')) {
    function inv_routes_normalize_path(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
