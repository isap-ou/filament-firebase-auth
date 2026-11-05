<?php

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

it('names only config keys that exist', function () {
    $missing = [];

    foreach (boostFiles() as $path => $content) {
        preg_match_all('/filament-firebase-auth\.([a-z_]+(?:\.[a-z_]+)*)/', $content, $matches);

        foreach (array_unique($matches[1]) as $key) {
            // The config file name, not a key.
            if ($key === 'php') {
                continue;
            }

            if (! config()->has("filament-firebase-auth.{$key}")) {
                $missing[] = basename($path) . ': ' . $key;
            }
        }
    }

    expect($missing)->toBe([]);
});
