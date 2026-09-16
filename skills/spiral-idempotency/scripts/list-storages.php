<?php

declare(strict_types=1);

/**
 * List the storage aliases declared in the project's idempotency config — the values usable in
 * `#[Idempotent(storage: '<alias>')]` — with their driver, guarantee, and key parameters.
 *
 * Usage:
 *   php list-storages.php [--root=PATH] [--config=FILE]
 *
 * --root=PATH    Project root (where vendor/ lives). Default: the current working directory.
 * --config=FILE  Config file path. Default: <root>/app/config/idempotency.php.
 *
 * Read-only. Loads the project's autoloader and includes the config file; a config relying on the
 * booted application container may fail to load — in that case read the config file directly.
 *
 * Exit codes: 0 ok, 1 missing autoloader/config, 2 config failed to load or has no storages.
 */

$root = getcwd();
$configPath = null;
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--root=')) {
        $root = \rtrim(\substr($arg, 7), '/\\');
    } elseif (\str_starts_with($arg, '--config=')) {
        $configPath = \substr($arg, 9);
    }
}
$configPath ??= "$root/app/config/idempotency.php";

$autoload = "$root/vendor/autoload.php";
if (!\is_file($autoload)) {
    \fwrite(STDERR, "No autoloader at `$autoload` — pass the project root via --root=PATH.\n");
    exit(1);
}
require $autoload;

if (!\is_file($configPath)) {
    \fwrite(STDERR, "No config at `$configPath` — the package is not configured yet (see references/setup.md).\n");
    exit(1);
}

try {
    /** @psalm-suppress UnresolvableInclude */
    $config = require $configPath;
} catch (\Throwable $e) {
    \fwrite(STDERR, "Config failed to load: {$e->getMessage()}\n");
    \fwrite(STDERR, "It likely needs the booted application — read `$configPath` directly instead.\n");
    exit(2);
}

if (!\is_array($config) || !\is_array($config['storages'] ?? null) || $config['storages'] === []) {
    \fwrite(STDERR, "`$configPath` declares no storages.\n");
    exit(2);
}

/** Render a config value compactly. */
function renderValue(mixed $value): string
{
    return match (true) {
        $value instanceof \UnitEnum => $value->name,
        \is_bool($value) => $value ? 'true' : 'false',
        $value === null => 'null',
        \is_scalar($value) => \var_export($value, true),
        default => \get_debug_type($value),
    };
}

echo "# Idempotency storages — `$configPath`\n\n";

$default = $config['default'] ?? null;
\is_string($default) and print("Default alias: '$default'\n\n");

foreach ($config['storages'] as $alias => $storage) {
    echo "## '$alias'" . ($alias === $default ? '  (default)' : '') . "\n";
    if (!\is_object($storage)) {
        echo '  (unexpected value: ' . \get_debug_type($storage) . ")\n\n";
        continue;
    }
    echo '  driver config: ' . $storage::class . "\n";
    if (\method_exists($storage, 'guarantee')) {
        try {
            $g = $storage->guarantee();
            echo '  guarantee:     ' . ($g instanceof \UnitEnum ? $g->name : renderValue($g)) . "\n";
        } catch (\Throwable $e) {
            echo "  guarantee:     <failed: {$e->getMessage()}>\n";
        }
    }
    foreach ((new \ReflectionObject($storage))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
        if ($prop->getName() === 'guarantee' || !$prop->isInitialized($storage)) {
            continue;
        }
        \printf("  %-14s %s\n", $prop->getName() . ':', renderValue($prop->getValue($storage)));
    }
    echo "\n";
}

if (\is_array($config['transports'] ?? null)) {
    echo "## Transports (resolution middleware, outer → inner)\n";
    foreach ($config['transports'] as $name => $middleware) {
        $list = \array_map(
            static fn(mixed $m): string => \is_object($m) ? $m::class : (string) $m,
            \is_array($middleware) ? $middleware : [$middleware],
        );
        echo "  $name:\n    " . \implode("\n    ", $list) . "\n";
    }
    echo "\n";
}

echo "Use an alias above in #[Idempotent(storage: '<alias>')]; match its guarantee to the operation\n";
echo "(see SKILL.md). If none fits, add a new storage to the config rather than misusing one.\n";
echo \is_string($default)
    ? "Omitting `storage:` falls back to the default alias '$default'.\n"
    : "No default alias is configured, so `storage:` is mandatory in every #[Idempotent].\n";
