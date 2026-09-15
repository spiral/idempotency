<?php

declare(strict_types=1);

/**
 * Detect the infrastructure spiral/idempotency can plug into in THIS project.
 *
 * Usage:
 *   php inspect-environment.php [--root=PATH]
 *
 * --root=PATH  Project root (where composer.json lives). Default: the current working directory.
 *
 * Read-only: prints a report, writes nothing. The report covers:
 *   - relevant installed packages and versions (incl. the cycle/database >= 2.21 requirement);
 *   - which transports (HTTP / queue / gRPC / events) and storage drivers (Cycle SQL / Redis / in-memory) are available;
 *   - configured database engines (app/config/database.php) and docker-compose DB/Redis services;
 *   - which idempotency bootloaders are already registered and whether the config file exists.
 *
 * Exit codes: 0 ok, 1 no composer project at --root.
 */

$root = getcwd();
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--root=')) {
        $root = \rtrim(\substr($arg, 7), '/\\');
    }
}

if (!\is_file($root . '/composer.json')) {
    \fwrite(STDERR, "No composer.json under `$root` — pass the project root via --root=PATH.\n");
    exit(1);
}

/** @return array<string, string> package => pretty version */
function installedPackages(string $root): array
{
    $file = $root . '/vendor/composer/installed.php';
    if (\is_file($file)) {
        /** @psalm-suppress UnresolvableInclude */
        $data = require $file;
        $out = [];
        foreach ($data['versions'] ?? [] as $name => $info) {
            $out[$name] = $info['pretty_version'] ?? ($info['version'] ?? '?');
        }
        return $out;
    }
    // Fallback: composer.lock (vendor not installed yet)
    $lock = $root . '/composer.lock';
    if (\is_file($lock)) {
        $data = \json_decode((string) \file_get_contents($lock), true) ?? [];
        $out = [];
        foreach (\array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
            $out[$pkg['name']] = $pkg['version'] ?? '?';
        }
        return $out;
    }
    return [];
}

/** Search PHP files under $dir for any of the needles; returns needle => first file hit. */
function grepFiles(string $dir, array $needles): array
{
    $hits = [];
    if (!\is_dir($dir)) {
        return $hits;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = (string) \file_get_contents($file->getPathname());
        foreach ($needles as $needle) {
            if (!isset($hits[$needle]) && \str_contains($content, $needle)) {
                $hits[$needle] = $file->getPathname();
            }
        }
        if (\count($hits) === \count($needles)) {
            break;
        }
    }
    return $hits;
}

$packages = installedPackages($root);
$has = static fn(string $name): bool => isset($packages[$name]);
$ver = static fn(string $name): string => $packages[$name] ?? '—';

echo "# spiral/idempotency — environment report\n";
echo "Project root: $root\n\n";

// ---- Packages ---------------------------------------------------------------------------------
echo "## Packages\n";
$interesting = [
    'spiral/idempotency'       => 'the package itself',
    'spiral/framework'         => 'Spiral framework',
    'spiral/interceptors'      => 'required for the #[Idempotent] attribute path',
    'cycle/database'           => 'bundled SQL storage driver (lease/inbox/at-most-once)',
    'spiral/cycle-bridge'      => 'tables in the ORM schema (CycleSchemaBootloader)',
    'spiral/queue'             => 'queue transport',
    'spiral/roadrunner-bridge' => 'RoadRunner dispatchers (queue/gRPC scopes)',
    'spiral/roadrunner-grpc'   => 'gRPC transport',
    'spiral/events'            => 'PSR-14 events transport',
    'predis/predis'            => 'default client for the Redis/Valkey lease backend (RedisLeaseConfig)',
    'psr/http-message'         => 'HTTP transport (PSR-7)',
    'psr/http-factory'         => 'HTTP transport (PSR-17, response snapshots)',
];
foreach ($interesting as $name => $role) {
    \printf("  [%s] %-26s %-12s %s\n", $has($name) ? 'x' : ' ', $name, $ver($name), $role);
}

if ($has('cycle/database')) {
    $normalized = \ltrim($ver('cycle/database'), 'v^~');
    if (\preg_match('/^\d+\.\d+/', $normalized) && \version_compare($normalized, '2.21.0', '<')) {
        echo "  WARNING: cycle/database {$ver('cycle/database')} < 2.21 — the upsert `DO NOTHING`\n";
        echo "  affected-row dedup is broken on MySQL/Postgres below that version. Upgrade it.\n";
    }
}

// ---- Transports & drivers ---------------------------------------------------------------------
echo "\n## Available transports / drivers\n";
$line = static fn(bool $ok, string $what, string $why) => \printf("  [%s] %-14s %s\n", $ok ? 'x' : ' ', $what, $why);
$line($has('psr/http-message') && $has('psr/http-factory'), 'HTTP', 'HttpIdempotencyBootloader + transports.http');
$line($has('spiral/queue'), 'Queue', 'QueueIdempotencyBootloader + transports.queue (consume interceptor)');
$line($has('spiral/roadrunner-grpc') && $has('spiral/roadrunner-bridge'), 'gRPC', 'GrpcIdempotencyBootloader + transports.grpc');
$line($has('spiral/events'), 'Events', 'EventsIdempotencyBootloader (no transports entry — PSR-14, no interceptor)');
$line($has('cycle/database'), 'Cycle SQL', 'CycleLeaseConfig / CycleInboxConfig / CycleAtMostOnceConfig');
$redisClient = match (true) {
    $has('predis/predis') => 'predis/predis found: bind \\Predis\\ClientInterface',
    \extension_loaded('redis') => 'ext-redis found: bind RedisCommands to an adapter over \\Redis',
    default => 'no Redis client found: bind RedisCommands to an adapter over the app\'s client, or install predis/predis',
};
$line($has('predis/predis') || \extension_loaded('redis'), 'Redis lease', "RedisLeaseConfig (AtLeastOnce only) — {$redisClient}");
$line(true, 'Memory lease', 'MemoryLeaseConfig (AtLeastOnce, process-local) — no dependency; tests and local development only');

// ---- Config files -----------------------------------------------------------------------------
echo "\n## Config files\n";
foreach (['idempotency', 'database', 'queue', 'grpc'] as $cfg) {
    $path = "$root/app/config/$cfg.php";
    \printf("  [%s] app/config/%s.php\n", \is_file($path) ? 'x' : ' ', $cfg);
}

// Database engines: naive but effective — driver class names in the database config.
$dbConfig = "$root/app/config/database.php";
if (\is_file($dbConfig)) {
    $content = (string) \file_get_contents($dbConfig);
    $engines = \array_filter(
        ['Postgres', 'MySQL', 'SQLite', 'SQLServer'],
        static fn(string $e): bool => \stripos($content, $e) !== false,
    );
    echo '  Database engines mentioned in database.php: ' . ($engines ? \implode(', ', $engines) : 'none detected') . "\n";
}

// docker-compose services worth knowing about.
foreach (['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $compose) {
    $path = "$root/$compose";
    if (\is_file($path)) {
        $content = \strtolower((string) \file_get_contents($path));
        $services = \array_filter(
            ['postgres', 'mysql', 'mariadb', 'redis', 'valkey', 'roadrunner'],
            static fn(string $s): bool => \str_contains($content, $s),
        );
        echo "  $compose services detected: " . ($services ? \implode(', ', $services) : 'none of interest') . "\n";
        break;
    }
}

// ---- Bootloaders ------------------------------------------------------------------------------
echo "\n## Idempotency bootloaders registered (searched app/src)\n";
$bootloaders = [
    'IdempotencyBootloader',
    'HttpIdempotencyBootloader',
    'QueueIdempotencyBootloader',
    'GrpcIdempotencyBootloader',
    'EventsIdempotencyBootloader',
    'CycleSchemaBootloader',
];
$hits = grepFiles("$root/app/src", $bootloaders);
// `IdempotencyBootloader` is a substring of the transport ones — require a standalone match.
foreach ($bootloaders as $b) {
    $found = isset($hits[$b]);
    if ($b === 'IdempotencyBootloader' && $found) {
        $content = (string) \file_get_contents($hits[$b]);
        $found = (bool) \preg_match('/\\\\IdempotencyBootloader::class/', $content);
    }
    \printf("  [%s] %s%s\n", $found ? 'x' : ' ', $b, isset($hits[$b]) ? '  (' . $hits[$b] . ')' : '');
}

echo "\nNext: follow references/setup.md for the missing pieces; verify aliases with scripts/list-storages.php.\n";
