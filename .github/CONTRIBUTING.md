# Contributing

We welcome contributions. We give full credit for each contribution.

Read this guide before you open an issue or a pull request.

## Etiquette

The maintainers build and maintain this project in their free time.
They give the code to everyone in the hope that it helps other developers.
Please be considerate when you open an issue or a pull request.

The maintainers make sure that each change is good for the project.
Developers have different skills, strengths and weaknesses.
Respect the decision of the maintainers if they do not accept your change.

## Viability

Before you request or send a new feature, think about other users of the project.
Many developers use this package, and their needs can be different from your needs.
Ask yourself whether other users will use the feature.

## Procedure

Before you open an issue:

- Try to reproduce the problem again, to make sure that it is not a one-time incident.
- Make sure that the project does not already have the feature.
- Look at the open pull requests. Make sure that nobody already works on a fix or on the feature.

Before you send a pull request:

- Make sure that the codebase does not already have the feature.
- Make sure that nobody already sent a pull request for the same feature or fix.

Do not report security issues in public. Read [SECURITY.md](SECURITY.md).

## Versions

The plugin major version equals the Filament major version.

- Filament 5 uses plugin `5.x`. The first release is `v5.0.0`.
- Inside one major, we follow [SemVer v2.0.0](https://semver.org/). A minor release adds features. A patch release fixes bugs.
- We do not accept breaking changes inside a Filament major. A breaking change waits for the next Filament major.
- Each plugin major supports exactly one Filament major.

Send your pull request to `main`. Read [RELEASING.md](../RELEASING.md) for the full rules.

## Requirements

- **Code style.** Run `composer lint` to apply [Laravel Pint](https://laravel.com/docs/pint). CI runs `composer test:lint`.
- **Static analysis.** Run `composer analyse`. CI runs PHPStan with Larastan.
- **Add tests.** We do not accept a change without tests. Run `composer test`. The tests use Pest and Orchestra Testbench. They never call a real Firebase project. They use a mock of `Kreait\Firebase\Contract\Auth`.
- **Emulator tests.** The `emulator` test group runs against the Firebase Auth Emulator. Run `npx firebase-tools emulators:exec --project demo-filament-firebase-auth --only auth "vendor/bin/pest --group=emulator"`. You need Node and Java 21 or later.
- **JavaScript.** If you change `resources/js`, run `npm ci && npm run build`. Commit the result in `resources/dist`. CI checks that the committed bundle matches the source.
- **No custom statics.** Do not add custom static methods, static properties or class constants, unless Laravel or Filament requires them. Use instance methods, Eloquent scopes and classes from the container.
- **Translations.** Put each new user-facing string in a translation file in `resources/lang`.
- **Document any change in behavior.** Keep `README.md` and the other documentation up to date. Add a line under `## [Unreleased]` in `CHANGELOG.md`.
- **One pull request per feature.** If you want to do more than one thing, send more than one pull request.
- **Send a coherent history.** Make sure that each commit in your pull request has a clear purpose. We use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `ci:`, `chore:`).

**Happy coding!**
