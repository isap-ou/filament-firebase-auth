<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * The Laravel Boost files that the package ships in `resources/boost`.
 *
 * @return array<string, string> The file contents by path.
 */
function boostFiles(): array
{
    $root = dirname(__DIR__, 2) . '/resources/boost';

    $paths = [
        "{$root}/guidelines/core.blade.php",
        "{$root}/skills/filament-firebase-auth-development/SKILL.md",
        "{$root}/skills/filament-firebase-auth-development/references/filament-firebase-auth-guide.md",
    ];

    $files = [];

    foreach ($paths as $path) {
        $files[$path] = (string) file_get_contents($path);
    }

    return $files;
}

it('ships boost guidelines that compile', function () {
    $html = Blade::render(file_get_contents(__DIR__ . '/../../resources/boost/guidelines/core.blade.php'));

    expect($html)->toContain('filament-firebase-auth-development')
        ->and($html)->toContain('## Filament Firebase Auth');
});

it('ships a skill with valid frontmatter', function () {
    $dir = __DIR__ . '/../../resources/boost/skills/filament-firebase-auth-development';
    $front = Yaml::parse(Str::between(file_get_contents("{$dir}/SKILL.md"), "---\n", "\n---"));

    expect($front['name'])->toBe('filament-firebase-auth-development')
        ->and($front['name'])->toBe(basename($dir))
        ->and($front['description'])->not->toBeEmpty()
        ->and($front['license'])->toBe('MIT')
        ->and($front['metadata']['author'])->toBe('ISAPP')
        ->and(file_exists("{$dir}/references/filament-firebase-auth-guide.md"))->toBeTrue();
});

it('names only symbols that exist', function () {
    $found = [];
    $missing = [];

    foreach (boostFiles() as $path => $content) {
        preg_match_all('/Isapp(?:\\\\{1,2}[A-Za-z][A-Za-z0-9]*)+/', $content, $matches);

        foreach (array_unique($matches[0]) as $name) {
            $name = str_replace('\\\\', '\\', $name);
            $found[] = $name;

            $exists = class_exists($name) || interface_exists($name) || trait_exists($name)
                // A namespace, for example `Isapp\FilamentFirebaseAuth\Actions\Firebase`, is a folder in `src/`.
                || is_dir(dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', Str::after($name, 'Isapp\\FilamentFirebaseAuth')));

            if (! $exists) {
                $missing[] = basename($path) . ': ' . $name;
            }
        }
    }

    expect($found)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

/**
 * The config keys in the first column of the "Config keys" table of the reference guide.
 *
 * @return list<string>
 */
function boostGuideConfigKeys(): array
{
    $guide = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/boost/skills/filament-firebase-auth-development/references/filament-firebase-auth-guide.md');
    $section = Str::before(Str::after($guide, "\n## Config keys\n"), "\n## ");
    $keys = [];

    foreach (explode("\n", $section) as $line) {
        // A table row: `| key | default | meaning |`. The header and the separator have no backticks in the first cell.
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $firstCell = explode('|', $line)[1];

        preg_match_all('/`([a-z_]+(?:\.[a-z_]+)*)`/', $firstCell, $matches);

        array_push($keys, ...$matches[1]);
    }

    return array_values(array_unique($keys));
}

it('names only config keys that exist', function () {
    $keys = boostGuideConfigKeys();
    $missing = [];

    foreach ($keys as $key) {
        if (! config()->has("filament-firebase-auth.{$key}")) {
            $missing[] = "guide table: {$key}";
        }
    }

    foreach (boostFiles() as $path => $content) {
        preg_match_all('/filament-firebase-auth\.([a-z_]+(?:\.[a-z_]+)*)/', $content, $matches);

        foreach (array_unique($matches[1]) as $key) {
            // The config file name, not a key.
            if ($key === 'php') {
                continue;
            }

            $keys[] = $key;

            if (! config()->has("filament-firebase-auth.{$key}")) {
                $missing[] = basename($path) . ': ' . $key;
            }
        }
    }

    expect($keys)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('documents every config key in the guide table', function () {
    $documented = boostGuideConfigKeys();
    $config = require dirname(__DIR__, 2) . '/config/filament-firebase-auth.php';

    expect($documented)->toEqualCanonicalizing(array_keys(Arr::dot($config)));
});
