# Filament Firebase Auth

Firebase Auth sign-in for Filament 5 panels through Laravel auth contracts — the same users and passwords as your other Firebase apps.

<p><a href="https://isapp.be/filament-firebase-auth" target="_blank"><img class="filament-hidden" src="https://static.isap.me/filament-firebase-auth.png" alt="Filament Firebase Auth by ISAPP" /></a></p>

[![Packagist](https://img.shields.io/packagist/v/isapp/filament-firebase-auth.svg)](https://packagist.org/packages/isapp/filament-firebase-auth)
[![Tests](https://github.com/isap-ou/filament-firebase-auth/actions/workflows/tests.yml/badge.svg)](https://github.com/isap-ou/filament-firebase-auth/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/php-v/isapp/filament-firebase-auth.svg)](https://packagist.org/packages/isapp/filament-firebase-auth)
[![License](https://img.shields.io/github/license/isap-ou/filament-firebase-auth.svg)](#license)

## What it is

A Filament 5 plugin that signs users in to Filament panels with [Firebase Authentication](https://firebase.google.com/docs/auth).
It plugs in through Laravel's own auth contracts: a `firebase` user provider, a `firebase` password broker and a model trait.
So Filament's login, password reset and email verification pages, guards, policies, `canAccessPanel()`, rate limits and events keep working as usual.

## What it is for

Your users already exist in a Firebase project, for example for a mobile app or another web app.
They must sign in to your Filament panels with the same email and password, or with the same Google account.

With this plugin, Firebase stays the only place that knows the passwords.
The Laravel database stores no password and no password hash.
The plugin adds one link table that connects your local records to Firebase users.

## Features

- Sign-in with email and password on Filament's own login page.
- Sign-in with social providers (Google, Apple, Microsoft, GitHub, Facebook, X (Twitter), Yahoo) through the Firebase JS SDK.
- Registration: the plugin creates the Firebase account, then the local record.
- Password reset on Filament's own pages. Firebase gets the new password.
- Email verification on Filament's own pages. The verified flag is shared with your other Firebase apps.
- Profile page: name, email and password change in Firebase, "log out other sessions", optional "log out everywhere" and account deletion.
- Filament's multi-factor authentication for email-and-password sign-in.
- One model, guard and set of rules per panel. Each panel decides if a sign-in creates a local record ("open" or "closed" panel).
- `make:filament-user` creates the Firebase account and the local record.
- A background session check logs a session out when the Firebase account is deleted, disabled or its tokens are revoked.
- Account deletion in Firebase is opt-in, with a safe default (`never`).
- Extension points as interfaces. You can bind your own class for each one.

## When not to use it

- You need token auth for an API (Sanctum, bearer ID tokens). The plugin is for panel sessions only.
- You use Firebase multi-tenancy (`tenantId`), phone sign-in or anonymous sign-in.
- You use Filament 3 or Filament 4. The plugin supports Filament 5 only.

Read [Limitations](#limitations) for the full list.

## How it works

1. The `firebase` user provider checks the email and the password with Firebase. Filament's login page calls it through Laravel's `UserProvider` contract.
2. After Firebase accepts the credentials, the plugin finds or creates the local record of the panel model and links it to the Firebase UID in the `firebase_identities` table.
3. Social buttons sign in with a Firebase popup in the browser. The server checks the Firebase ID token, then runs the same lookup.
4. The session stores a fingerprint of the Firebase state instead of a password hash. Laravel's `AuthenticateSession` logs the session out when that state changes.
5. Password reset, verification and profile changes use Filament's pages and write the result to Firebase.

Read the [architecture guide](https://github.com/isap-ou/filament-firebase-auth/blob/main/docs/architecture.md) for the details.

## Requirements

- PHP 8.3 or later
- Laravel 12.69.3 or later, or Laravel 13.33 or later
- Filament 5
- A Firebase project with a service account
- [`kreait/laravel-firebase`](https://github.com/kreait/laravel-firebase) 7.2 or later (Composer installs it with the plugin)

## Versioning

The plugin major version equals the Filament major version: plugin `5.x` works with Filament `5.x`.
Inside one major, releases follow semantic versioning.
Read [RELEASING.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/RELEASING.md) for the rules and [UPGRADING.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/UPGRADING.md) for upgrades between majors.

## Installation

Install the package:

```bash
composer require isapp/filament-firebase-auth
```

Publish the config file and the migration of the link table:

```bash
php artisan filament-firebase-auth:install
```

Before you run the migration, check the key type of your models. The link table uses Laravel's `morphs()` column.
It follows `Schema::morphUsingUuids()` and `Schema::morphUsingUlids()`.
If your panel models use UUID or ULID keys, call one of these methods in a service provider, or change `morphs()` to `uuidMorphs()` or `ulidMorphs()` in the published migration.

```bash
php artisan migrate
```

The built JavaScript of the social buttons ships in `resources/dist`. You do not need npm.
Publish the Filament assets as usual, for example after each update:

```bash
php artisan filament:assets
```

## Firebase setup

This section takes you from zero to a Firebase project that the plugin can use.
The [Firebase console](https://console.firebase.google.com/) changes its menus from time to time.
Newer consoles put **Authentication** under **Security**. If a label differs, look for the same words nearby.

### 1. Create or choose a Firebase project

- **Another app already uses a Firebase project** (for example your mobile app): use that project. Shared users are the reason for this plugin.
  Keep `delete_accounts` at `never` in this case. Read [Account deletion](#account-deletion).
- **No project yet:** in the Firebase console, create a new project, enter a project name and confirm. Google Analytics is not needed for this plugin.

Firebase guide: [Add Firebase to your JavaScript project](https://firebase.google.com/docs/web/setup).

### 2. Turn on the sign-in providers

Open **Authentication → Sign-in method**.

- **Email/Password:** enable it and save. The email and password sign-in of the plugin needs it.
  [Firebase guide](https://firebase.google.com/docs/auth/web/password-auth).
- Enable each social provider that you list in `providers()`. Every provider other than Google needs an app in the developer console of that provider.
  For most providers, the Firebase dialog shows an OAuth redirect URI in the form `https://YOUR-PROJECT-ID.firebaseapp.com/__/auth/handler`.
  Register this URI (or its `firebaseapp.com` domain) in the app of the provider.

| Provider | What Firebase asks for | Firebase guide |
|---|---|---|
| Google | Enable and save. The console can ask for a project support email. No other app is needed. | [Google](https://firebase.google.com/docs/auth/web/google-signin) |
| Apple | An Apple Developer Program membership: a Services ID, your Team ID, and a "Sign in with Apple" private key with its key ID. Register the redirect URI as a Return URL at Apple. | [Apple](https://firebase.google.com/docs/auth/web/apple) |
| Microsoft | An app registration in Microsoft Entra ID (formerly Azure Active Directory): its client ID and a client secret. Register the redirect URI there. Use `providerParameters(SocialProvider::Microsoft, ['tenant' => '...'])` to allow one tenant only. | [Microsoft](https://firebase.google.com/docs/auth/web/microsoft-oauth) |
| GitHub | A GitHub OAuth app: its client ID and client secret. Put the redirect URI in "Authorization callback URL". | [GitHub](https://firebase.google.com/docs/auth/web/github-auth) |
| Facebook | A Facebook for Developers app: its App ID and App Secret. Put the redirect URI in the Facebook Login settings of the app, in the list of OAuth redirect URIs. | [Facebook](https://firebase.google.com/docs/auth/web/facebook-login) |
| X (Twitter) | An X developer app: its API key and API secret. Put the redirect URI in the callback URL of the app. | [Twitter](https://firebase.google.com/docs/auth/web/twitter-login) |
| Yahoo | A Yahoo developer app with the OpenID Connect permissions `profile` and `email`: its client ID and client secret. Register the redirect URI there. | [Yahoo](https://firebase.google.com/docs/auth/web/yahoo-oauth) |

### 3. Add your domains to the authorized domains

Open **Authentication → Settings → Authorized domains** and click **Add domain**.
Add each domain that serves a Firebase panel, for example `admin.example.com`.
For local development, add `localhost` too: new projects no longer include it by default.
Without the domain, the social sign-in popup fails with `auth/unauthorized-domain`.

Firebase guide: the "Authorized domains" note in [Email link authentication](https://firebase.google.com/docs/auth/web/email-link-auth).

### 4. Create the server credentials (service account)

The server talks to Firebase through [`kreait/laravel-firebase`](https://github.com/kreait/laravel-firebase) with a service account.

1. Open **Project settings → Service accounts**.
2. Click **Generate new private key**, then **Generate key**. The browser downloads a JSON file.
3. Move the file into your app, outside the public directory, for example `storage/app/firebase/service-account.json`.
4. Add the file to `.gitignore`. **Never commit it.** Anyone with this file can manage every user of the project.

```gitignore
/storage/app/firebase/service-account.json
```

5. Point `kreait/laravel-firebase` to it in `.env`. A relative path starts at the root of your app:

```dotenv
FIREBASE_CREDENTIALS=storage/app/firebase/service-account.json
```

`kreait/laravel-firebase` also reads `GOOGLE_APPLICATION_CREDENTIALS` when `FIREBASE_CREDENTIALS` is empty.
On production, give each server its own copy of the file, for example through your secret store or deploy tool.

You only need the `kreait/laravel-firebase` config file to change more than the credentials (for example the HTTP timeout). To publish it:

```bash
php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider" --tag=config
```

Leave `FIREBASE_AUTH_TENANT_ID` empty. The plugin does not support Firebase multi-tenancy.

Firebase guide: [Add the Firebase Admin SDK to your server](https://firebase.google.com/docs/admin/setup).

### 5. Register a web app for the social buttons

The social buttons run the Firebase JS SDK in the browser. It needs the config of a Firebase web app.
You can skip this step when the panel has no social providers.

1. On the project overview page, click the **Web** icon (`</>`). When the project already has apps, click **Add app**, then **Web**.
2. Enter a nickname and click **Register app**. The plugin does not need Firebase Hosting.
3. Copy the values of the `firebaseConfig` object into `.env`:

| `firebaseConfig` key | Plugin env key |
|---|---|
| `apiKey` | `FIREBASE_WEB_API_KEY` |
| `authDomain` | `FIREBASE_WEB_AUTH_DOMAIN` |
| `projectId` | `FIREBASE_WEB_PROJECT_ID` |
| `appId` | `FIREBASE_WEB_APP_ID` |

```dotenv
FIREBASE_WEB_API_KEY=AIza...
FIREBASE_WEB_AUTH_DOMAIN=your-project-id.firebaseapp.com
FIREBASE_WEB_PROJECT_ID=your-project-id
FIREBASE_WEB_APP_ID=1:1234567890:web:abc123
```

You find the same values later under **Project settings → General → Your apps → Config**.

The web `apiKey` is not a secret. It only identifies your Firebase project, and every visitor of the login page can see it.
Still, restrict it: in the Google Cloud console, open **APIs & Services → Credentials** and edit the key.
Under **Application restrictions**, allow only your panel domains and your `authDomain` (`your-project-id.firebaseapp.com`) as HTTP referrers. The sign-in popup runs on the `authDomain` and uses the key too. Under **API restrictions**, allow only the APIs that Firebase Authentication needs (Identity Toolkit API and Token Service API).
Test the change on a test key first, because a wrong restriction breaks the sign-in.
Read [Learn about using and managing API keys for Firebase](https://firebase.google.com/docs/projects/api-keys).

### 6. Recommended settings

- **Email enumeration protection:** open **Authentication → Settings → User actions**. Firebase turns it on by default for projects created on or after September 15, 2023.
  The plugin works with it on or off, and shows the same failure message for every failed sign-in either way.
- **Password policy:** open **Authentication → Settings → Password policy** to require a minimum length or character types.
  Filament's registration and profile forms check Laravel's `Password::defaults()`. Set the same rules there, so the forms check them too.
- **Email templates:** the plugin does not use the Firebase email templates.
  Password reset, email verification and email change emails come from Filament and Laravel. Configure Laravel mail (`MAIL_MAILER` and the rest) for them.

Firebase guide: [password policy and email enumeration protection](https://firebase.google.com/docs/auth/web/password-auth).

### 7. Local development with the Firebase Auth Emulator

You can develop without a real Firebase project. The [Firebase Auth Emulator](https://firebase.google.com/docs/emulator-suite/connect_auth) needs Node and Java 21 or later.

```bash
npm install --save-dev --save-exact firebase-tools
npx --no-install firebase-tools emulators:start --only auth --project demo-your-app
```

Then set in `.env`:

```dotenv
FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099
FIREBASE_CREDENTIALS='{"type":"service_account","project_id":"demo-your-app","client_email":"emulator@demo-your-app.iam.gserviceaccount.com","private_key":"unused"}'
```

`kreait/firebase-php` then sends all Auth calls to the emulator. A project ID that starts with `demo-` needs no real Firebase project.
The emulator ignores credentials. The placeholder only gives `kreait/firebase-php` the project ID.
Without it, `kreait/firebase-php` looks for Google credentials on your machine: a slow probe of the cloud metadata server, or your real credentials.
Email and password sign-in, registration, password reset, verification, the profile page and `make:filament-user` work against the emulator.
The social buttons do not: test them against a real development project.
The emulator keeps its accounts in memory. They are gone after a restart.

### 8. Firebase troubleshooting

| Symptom | Cause and fix |
|---|---|
| The social button shows "The browser blocked the sign-in window." | The browser blocked the popup (`auth/popup-blocked`). Allow pop-ups for your site and click again. |
| The social button shows "The sign-in failed. Try again." at once | Often `auth/unauthorized-domain`: add the domain to the authorized domains (step 3). Or `auth/operation-not-allowed`: enable the provider in **Sign-in method** (step 2). Or the `FIREBASE_WEB_*` values are wrong (step 5). |
| "Unable to determine the Firebase Project ID" | `kreait/firebase-php` found no credentials. Check `FIREBASE_CREDENTIALS` and the file path (step 4). With the emulator, use the placeholder credentials (step 7). After a change of `.env`, run `php artisan config:clear`. |
| An error while `kreait/firebase-php` reads the credentials | The file is not the service account JSON, or it is incomplete. Generate a new key (step 4). Do not use the web config or a `google-services.json` file. |
| Every password sign-in fails, and the log says that Firebase failed | The service account is wrong or deleted, or the server cannot reach Firebase. The plugin logs the exception class only. Check step 4 and the network. |
| The social button shows "The page has expired. Reload the page and try again." | The CSRF token of the page expired (HTTP 419), for example after a long idle time. Reload the login page. |

## Configure the auth guard, provider and password broker

Each Firebase panel uses its own guard. The guard uses a user provider with the `firebase` driver.
The password broker of the panel also uses the `firebase` driver.

```php
// config/auth.php

'guards' => [
    'admin' => [
        'driver' => 'session',
        'provider' => 'firebase_admins',
    ],
],

'providers' => [
    'firebase_admins' => [
        'driver' => 'firebase',
        'model' => App\Models\Admin::class,
    ],
],

'passwords' => [
    'admins' => [
        'driver' => 'firebase',
        'provider' => 'firebase_admins',
        'table' => 'password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
],
```

The `firebase` broker stores its tokens in a database table, so `table` is required.
Laravel's default `password_reset_tokens` table works.

## Configure the panel

Login, password reset and email verification are Filament's own pages. You turn them on as usual.
Registration and profile need the plugin's pages, because Filament's pages would store a local password.
For multi-factor authentication, use the plugin's `AppAuthentication` and its required-MFA middleware.

```php
use Filament\Panel;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication;
use Isapp\FilamentFirebaseAuth\Pages;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->authGuard('admin')
        ->authPasswordBroker('admins')
        ->login()
        ->registration(Pages\Register::class)
        ->passwordReset()
        ->emailVerification()
        ->emailChangeVerification()
        ->profile(Pages\EditProfile::class)
        ->multiFactorAuthentication([
            AppAuthentication::make()->recoverable(),
        ])
        ->multiFactorAuthenticationRequiredMiddlewareName(EnsureMultiFactorAuthenticationIsEnabled::class)
        // ->middleware([...]), ->authMiddleware([...]) and the rest as usual.
        ->plugin(
            FilamentFirebaseAuthPlugin::make()
                ->createOnSignIn(false)
                ->providers([SocialProvider::Google, SocialProvider::Microsoft])
                ->providerParameters(SocialProvider::Microsoft, ['tenant' => 'your-tenant-id']),
        );
}
```

Call `->id()` before `->plugin()`. The plugin reads the panel middleware when you add it.

On the first request of the panel, the plugin checks this setup.
If the guard uses the `firebase` driver and the panel uses Filament's own registration page, profile page, app authentication or required-MFA middleware, the plugin throws a `LogicException`.
The message names the fix. The plugin never stores a local password silently.

### Plugin options

| Method | Default | What it does |
|---|---|---|
| `createOnSignIn(bool \| Closure)` | `true` | When `true`, a sign-in with a Firebase account that has no local record creates one ("open" panel). When `false`, only linked or linkable records sign in ("closed" panel). |
| `providers(array)` | `[]` | The `SocialProvider` cases that get a button on the login and registration pages, in this order. No providers: no buttons and no sign-in route. |
| `providerScopes(SocialProvider, array)` | `[]` | Extra OAuth scopes for one provider. Apple always asks for `email` and `name`. |
| `providerParameters(SocialProvider, array)` | `[]` | Custom OAuth parameters for one provider, for example `['tenant' => '...']` for Microsoft or `['prompt' => 'select_account']` for Google. |

`createOnSignIn()` does not control the registration page. Registration is Filament's own `->registration()` setting.

Set the providers in the panel configuration, not later at runtime.
The plugin adds the sign-in route only to a panel that has providers when Laravel loads the routes (also with `php artisan route:cache`).

## Prepare your users table

The plugin never stores a password. Your table can keep its `password` column, but the column must accept `null`.
Otherwise, the first sign-in of a new user fails with a message that points to this section.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }
};
```

You can also drop the column if no other code uses it.

The plugin also expects:

- a `name` and an `email` column on the table,
- `name` and `email` in the `$fillable` list of the model (the default record creation uses them).

Firebase stores emails in lower case. On a database with case-sensitive comparisons (SQLite, PostgreSQL), store local emails in lower case too.
Otherwise, a local record with `Jane@example.com` does not match the Firebase user `jane@example.com`.

## Prepare your model

Add the `HasFirebaseIdentity` trait to the model of each Firebase panel.
The model implements `MustVerifyEmail` and `CanResetPassword`, and uses `Notifiable`.

```php
namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Isapp\FilamentFirebaseAuth\Concerns\HasFirebaseIdentity;

class Admin extends Authenticatable implements CanResetPassword, FilamentUser, MustVerifyEmail
{
    use HasFirebaseIdentity;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return true; // Your own rule.
    }
}
```

The trait reads the verified flag from the link table and returns a session fingerprint from `getAuthPassword()`.
`markEmailAsVerified()` and `markEmailAsUnverified()` write the flag to Firebase, so the change shows in all apps on the Firebase project.
It also deletes the link when the record is deleted.

If your model uses Laravel's `Authenticatable` or `MustVerifyEmail` traits directly (not through `Illuminate\Foundation\Auth\User`), PHP reports a method collision.
Tell PHP to use the methods of the plugin trait:

```php
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Isapp\FilamentFirebaseAuth\Concerns\HasFirebaseIdentity;

class Admin extends Model implements AuthenticatableContract, CanResetPasswordContract, MustVerifyEmailContract
{
    use Notifiable;
    use Authenticatable, CanResetPassword, MustVerifyEmail, HasFirebaseIdentity {
        HasFirebaseIdentity::getAuthPassword insteadof Authenticatable;
        HasFirebaseIdentity::hasVerifiedEmail insteadof MustVerifyEmail;
        HasFirebaseIdentity::markEmailAsVerified insteadof MustVerifyEmail;
        HasFirebaseIdentity::markEmailAsUnverified insteadof MustVerifyEmail;
        HasFirebaseIdentity::sendEmailVerificationNotification insteadof MustVerifyEmail;
        HasFirebaseIdentity::getEmailForVerification insteadof MustVerifyEmail;
    }
}
```

Two panels can share one model. Then they share one record per Firebase user.
Two panels with different models get one record in each model for the same Firebase user.

## How a sign-in finds the local record

For each sign-in (password or social), the plugin applies these rules in order:

1. A link exists for this Firebase UID and model: use its record.
2. No link, but a record has the same email: link it, but only when Firebase has verified the email.
   Anyone can create an unverified Firebase account with another person's email, so an unverified email never links an existing record.
3. No link, no record, and the panel is open (`createOnSignIn(true)`): create the record.
4. Otherwise, the sign-in fails.

A disabled Firebase account, a record linked to another Firebase UID, and a user without panel access also fail.
Every failure shows Filament's own "These credentials do not match our records" message, so nobody can find out which emails exist.

## Social sign-in

Add providers with the `SocialProvider` enum: `Google`, `Apple`, `Microsoft`, `GitHub`, `Facebook`, `Twitter` and `Yahoo`.
The buttons appear under Filament's own login and registration forms through render hooks.
The JavaScript loads only on these pages.

The browser signs in with a Firebase popup and posts the Firebase ID token to a route of the panel.
The server checks the token with `verifyIdToken()`, also for revocation. The token must:

- come from one of the providers of this panel (a password, anonymous or custom token fails),
- prove a sign-in of the last 5 minutes.

The route has the panel middleware, the CSRF check and a rate limit of 5 attempts per minute for each panel and IP address.

Social sign-in trusts the provider and its own second factor. It does not show Filament's multi-factor challenge.
If you need a second factor for these users, turn it on at the provider (for example in Google Workspace or Microsoft Entra).
[`dutchcodingcompany/filament-socialite`](https://github.com/DutchCodingCompany/filament-socialite) takes the same approach.

## Two-factor authentication

Filament's multi-factor authentication protects email-and-password sign-in. Filament's login page runs the challenge as usual.

- Use `Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication` instead of Filament's `AppAuthentication`.
  Its set-up, disable and recovery code dialogs check the password with Firebase.
  Filament's own class compares a local hash, and there is none.
- Prepare the model for app authentication as Filament's "Multi-factor authentication" docs describe it: implement `HasAppAuthentication` (and `HasAppAuthenticationRecovery` for recovery codes), use the matching `InteractsWith...` traits, and add the `app_authentication_secret` (and `app_authentication_recovery_codes`) columns.
- Filament's email code authentication has no password step. It works without a plugin class.
- When multi-factor authentication is required, set the plugin middleware with `->multiFactorAuthenticationRequiredMiddlewareName(EnsureMultiFactorAuthenticationIsEnabled::class)`.
  A user who signs in only with a social provider then passes. All other users get Filament's behaviour.

Users without a Firebase password see no app authentication actions on the profile page.
The section heading can still show, with no content.

Set the plugin middleware whenever the panel uses multi-factor authentication.
The setup check reads `isRequired` once, when the panel boots. It cannot see a closure that depends on the user.

For a social-only user on a panel that requires multi-factor authentication, the middleware asks Firebase about the user's providers once per request.

## Sessions

### Session check

Another app or the Firebase console can delete or disable an account, or revoke its tokens (Firebase does this on a password change).
Firebase does not notify your app. So the plugin checks the Firebase user of each session in the background:

- After `revalidate_every` minutes (default `15`), the next panel request starts a check. The request never waits for Firebase.
- With `revalidate_queue` set to `null`, the check runs after the response in the same process. Set a queue name to run it on a queue worker.
- When the account is missing or disabled, or its tokens were revoked, the session fingerprint changes.
- Laravel's `AuthenticateSession` middleware then logs the session out on its next request, also a "remember me" session.
- When Firebase cannot be reached, nothing changes. The next interval tries again.

The plugin adds Filament's `AuthenticateSession` middleware to the panel if the panel has none. Set `revalidate_every` to `0` to turn the check off.

### Log out other sessions

The profile page has a "Log out other sessions" action. It logs out all other sessions of this record in this app. The current session stays signed in.

### Log out everywhere

Set `global_logout` to `true` to show a "Log out everywhere" action on the profile page.
It revokes the Firebase refresh tokens of the user. This also signs the person out of your other apps on the same Firebase project.
For this reason, it is off by default.

After a password change, an email change, "log out other sessions" and "log out everywhere", the plugin signs the current session in again with the new fingerprint.
Laravel fires its `Login` event for this.

## Account deletion

The plugin always deletes the local link when a record is deleted.
The `delete_accounts` config key decides what happens to the Firebase account:

| `delete_accounts` | What the plugin deletes in Firebase |
|---|---|
| `never` (default) | Nothing. Only local data. |
| `new_only` | Only an account that Firebase created during this sign-in, when a closed panel (`createOnSignIn(false)`) blocks it. For example, a stranger signs in with Google on a closed panel. |
| `orphaned` | Any account with no link left in this app: after a block on a closed panel, after the user deleted the account on the profile page, or after code or an admin deleted the record. |

> [!WARNING]
> **Do not use `orphaned` when other apps share the Firebase project.**
> The plugin can see only its own links. It cannot know that a mobile app or another web app still uses the account.
> With `orphaned`, the deletion of a record in this app deletes the Firebase account for every app, and the person loses access everywhere.
> A blocked sign-in on a closed panel deletes too: when a person with no link in this app signs in with the correct password or with a social provider such as Google (for example a user of your mobile app), `orphaned` deletes that person's Firebase account for all apps.
> Use `orphaned` only when this app is the only user of the Firebase project. Keep `never` in all other cases.

More facts:

- `new_only` compares the creation time and the last sign-in time of the Firebase account. Both must be within `new_account_window` seconds (default `60`) of each other and of now.
- The Firebase part runs after the database transaction commits. A Firebase failure never stops the local deletion. The plugin logs the UID and the exception class.
- A soft delete keeps the link. A force delete removes it.
- Bulk deletes through the query builder (`Admin::query()->where(...)->delete()`) skip model events. They remove neither the link nor the Firebase account. Delete records one by one, or clean up yourself.
- When registration fails after the plugin created a Firebase account, the plugin deletes that account in every mode, because it created it in the same request.

## Create users with `make:filament-user`

The plugin replaces Filament's `make:filament-user` command (and its aliases). The options stay the same:

```bash
php artisan make:filament-user --panel=admin --name="Jane Doe" --email=jane@example.com --password=secret
```

On a panel whose guard uses the `firebase` driver:

- No Firebase account has this email: the command creates one with a verified email, then the local record and the link.
- A Firebase account with this email exists: the command keeps its password and links it. It refuses a disabled account, an unverified email, and an account that another record of the panel model already uses.
- When the local step fails, the command deletes the Firebase account only if it created that account.

On any other panel, the command runs Filament's normal behaviour.

## Extension points

Bind your own class in the `register()` method of a service provider. The plugin only binds its defaults when nothing else is bound.

| Contract | Default | When it runs |
|---|---|---|
| `Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers` | `Actions\LocalUsers\CreateLocalUser` | A sign-in, registration or `make:filament-user` needs a new local record. |
| `Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers` | `Actions\LocalUsers\SyncLocalUser` | Each sign-in and each session check. Copies the Firebase name and email. |
| `Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion` | `Deletion\AccountDeletionPolicy` | The plugin may delete a Firebase account (see [Account deletion](#account-deletion)). |

Example: give each new admin a default role.

```php
namespace App\Actions;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;

class CreateAdmin implements CreatesLocalUsers
{
    public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable
    {
        return $model::query()->create([
            'name' => $firebaseUser->displayName ?? $firebaseUser->email,
            'email' => $firebaseUser->email,
            'role' => 'viewer',
        ]);
    }
}
```

`create()` fills only the attributes in the `$fillable` list of the model, so add `role` there, or use `forceCreate()`.

```php
// app/Providers/AppServiceProvider.php

use App\Actions\CreateAdmin;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;

public function register(): void
{
    $this->app->bind(CreatesLocalUsers::class, CreateAdmin::class);
}
```

The class gets the Firebase user only. Extra fields of the registration form do not reach it.
To save extra form fields, subclass the plugin's `Pages\Register` page and use Filament's `afterRegister()` hook.

A custom `SyncsLocalUsers` must also update the link columns, or the session check breaks.
The simplest way is to extend the default class:

```php
namespace App\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser;
use Kreait\Firebase\Auth\UserRecord;

class SyncAdmin extends SyncLocalUser
{
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void
    {
        parent::handle($user, $firebaseUser);

        $user->forceFill(['avatar_url' => $firebaseUser->photoUrl])->save();
    }
}
```

If you write the class from scratch, inject `Isapp\FilamentFirebaseAuth\Actions\Identity\RefreshFirebaseIdentity` and call its `handle()` with the link of the record.

A custom `DecidesAccountDeletion` runs only when `delete_accounts` is not `never`. In `never` mode, the plugin never asks it.

All other behaviour lives in single-purpose action classes in `Isapp\FilamentFirebaseAuth\Actions\...`, each with one `handle()` method.
You can inject them in your own code, for example `Actions\Firebase\FindOrCreateFirebaseUser` and `Actions\Identity\LinkFirebaseIdentity` to create users from a Filament resource.

## Configuration

`config/filament-firebase-auth.php`:

| Key | Default | Meaning |
|---|---|---|
| `table` | `firebase_identities` | The link table. |
| `delete_accounts` | `never` | `never`, `new_only` or `orphaned`. Read [Account deletion](#account-deletion). |
| `new_account_window` | `60` | Seconds. For `new_only`, an account younger than this counts as new. |
| `revalidate_every` | `15` | Minutes between session checks. `0` turns the check off. |
| `revalidate_queue` | `null` | Queue of the session check job. `null` runs it after the response. |
| `global_logout` | `false` | Shows "Log out everywhere" on the profile page. |
| `social_sign_in` | `js_sdk` | How social sign-in works. `js_sdk` is the only value. |
| `web.api_key`, `web.auth_domain`, `web.project_id`, `web.app_id` | env | The Firebase web config for the social buttons. |

The server credentials stay in the `kreait/laravel-firebase` config (`config/firebase.php`).

## Troubleshooting

**"If you cannot sign in, reset your password."**
An existing local record links to a Firebase account only when Firebase has verified the email.
A password reset proves that the person owns the email, so it marks the email as verified.
The next sign-in then links the record.

**The social sign-in popup is blocked or closes at once.**
Read [Firebase troubleshooting](#8-firebase-troubleshooting).

**"This email already has an account with another sign-in method."**
By default, Firebase allows one account per email address. The person created the account with another method, for example a password.
Sign in with that method. The plugin does not link providers to an existing Firebase account.

**The first sign-in of a new user fails, and the log names a NOT NULL `password` column.**
Read [Prepare your users table](#prepare-your-users-table).

**A `LogicException` names Filament's registration page, profile page, app authentication or middleware.**
Use the plugin class that the message names. Read [Configure the panel](#configure-the-panel).

**A password reset link fails.**
The reset finds the record by email. When another app changed the Firebase email, the local email follows on the next sign-in or session check.
Until then, the reset can fail. Users who sign in only with a social provider get no reset email, because they have no Firebase password.

**Sign-in fails, and the log says that Firebase failed.**
The plugin shows the normal failure message when Firebase cannot be reached. It logs the exception class only, never the password or token.
Check the service account and the network.

## Testing your app with the Firebase Auth Emulator

`kreait/firebase-php` sends its Auth calls to the [Firebase Auth Emulator](https://firebase.google.com/docs/emulator-suite/connect_auth) when `FIREBASE_AUTH_EMULATOR_HOST` is set.
All server-side Firebase calls of the plugin go through it, so your feature tests need no real Firebase project.

The emulator needs Node and Java 21 or later. Pin `firebase-tools` as a dev dependency of your app, so every machine runs the same version:

```bash
npm install --save-dev --save-exact firebase-tools
npm ci
npx --no-install firebase-tools emulators:exec --project demo-your-app --only auth "php artisan test"
```

`emulators:exec` starts the emulator, sets `FIREBASE_AUTH_EMULATOR_HOST` for the command, and stops the emulator after it.
A project ID that starts with `demo-` needs no real Firebase project.

The emulator ignores credentials, but `kreait/firebase-php` still needs a project ID.
Without credentials, it looks for Application Default Credentials: a slow probe of the cloud metadata server, or the real credentials of your machine.
Give it placeholder service account credentials in the testing environment, as the plugin's own emulator tests do.
The private key is never used.

```php
// For example in the setUp() of your emulator test case
config()->set('firebase.projects.app.credentials', [
    'type' => 'service_account',
    'project_id' => 'demo-your-app',
    'client_email' => 'emulator@demo-your-app.iam.gserviceaccount.com',
    'private_key' => 'unused',
]);
```

The social buttons in the browser do not connect to the emulator.

The plugin's unit and feature tests mock Firebase. An `emulator` test group runs against the Firebase Auth Emulator (`composer test:emulator`).
Read [CONTRIBUTING.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/.github/CONTRIBUTING.md) to run them.

## Limitations

- **No passkeys.** Firebase Authentication has no passkey sign-in yet.
- **No Firebase multi-factor authentication.** Filament's own multi-factor authentication works. Password sign-in for an account with a Firebase second factor (SMS or TOTP) enrolled is not supported in v1.
- **No custom `oidc.*` or `saml.*` providers.** Only the providers in the `SocialProvider` enum.
- **No API or token auth.** The plugin is for panel sessions only.
- **No Firebase multi-tenancy, phone sign-in or anonymous sign-in.**
- **Filament 5 only.**
- **Emails come from Laravel.** Password reset and verification emails use Filament's notifications, not the Firebase email templates.
- **Registration may reveal where an email exists.** The plugin shows the same "email taken" error for a local record and for a Firebase-only account. The error may still appear at a different step of the validation, so a careful visitor may tell the two cases apart.
- **No import of local password hashes** into Firebase.

## Security

If you find a security issue, send an email to [contact@isapp.be](mailto:contact@isapp.be). Do not open a public issue.
Read the [security policy](https://github.com/isap-ou/filament-firebase-auth/blob/main/.github/SECURITY.md).

## Contributing

Read [CONTRIBUTING.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/.github/CONTRIBUTING.md).
Changes are listed in [CHANGELOG.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/CHANGELOG.md).

## Credits

- [ISAPP](https://isapp.be)
- ISAP OÜ
- [All contributors](https://github.com/isap-ou/filament-firebase-auth/contributors)

## License

The MIT License (MIT). Read [LICENSE.md](https://github.com/isap-ou/filament-firebase-auth/blob/main/LICENSE.md).
