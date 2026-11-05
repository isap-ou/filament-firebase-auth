# Changelog

All notable changes to `isapp/filament-firebase-auth` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Inside one major version, the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The plugin major version equals the Filament major version. Read `RELEASING.md` for the rules.

## [Unreleased]

## v5.0.0 - 2026-11-05

### Added

- Firebase Authentication for Filament 5 panels through Laravel contracts: the `firebase` user provider driver and the `firebase` password broker driver. Filament's own login, password reset and email verification pages stay. The plugin never stores a password or a password hash.
- Email and password sign-in on Filament's login page. Firebase checks the password. Every failure shows Filament's own failure message.
- Social sign-in with the Firebase JS SDK: Google, Apple, Microsoft, GitHub, Facebook, Twitter and Yahoo buttons on the login and registration pages, with custom scopes and parameters per provider. The server checks the ID token for revocation, the provider and a recent sign-in.
- Open and closed panels (`createOnSignIn()`), and the link of existing records by verified email.
- `Pages\Register`: registration that creates the Firebase account.
- `Pages\EditProfile`: name, email and password changes in Firebase, Filament's email change verification, "Log out other sessions", "Log out everywhere" (`global_logout`) and "Delete account".
- Password reset through the `firebase` broker. A reset proves the email and links the record.
- Email verification that writes the Firebase `emailVerified` flag.
- Compatibility with Filament's multi-factor authentication: `MultiFactor\AppAuthentication` and the `EnsureMultiFactorAuthenticationIsEnabled` middleware.
- A background session check (`revalidate_every`, `revalidate_queue`). A deleted, disabled or revoked Firebase account, a newly verified email or a lost password provider logs out older sessions, also "remember me" sessions.
- Account deletion modes for the Firebase account: `never` (default), `new_only` and `orphaned` (`delete_accounts`, `new_account_window`).
- A `make:filament-user` command that creates or links the Firebase account.
- Extension contracts: `CreatesLocalUsers`, `SyncsLocalUsers`, `DecidesAccountDeletion` and `SocialSignInMethod`, and single-purpose action classes.
- A setup check (`EnsurePanelUsesFirebasePages`) for every Firebase panel. It rejects Filament's own registration page, profile page, app authentication and required-MFA middleware, a password broker without the `firebase` driver, and a model without the `HasFirebaseIdentity` trait.
- Support for extra credentials and `withQuery()` on the `firebase` user provider, as in Laravel's `eloquent` driver.
- Laravel Boost guidelines and a Boost skill.
- Support for the Firebase Auth Emulator in local development and tests, and an `emulator` test group.
