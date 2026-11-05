# Filament Firebase Auth Reference

Complete reference for `isapp/filament-firebase-auth` (Filament 5, Laravel 12.69.3+ or 13.33+, PHP 8.3+; see `composer.json` for the exact constraints). Source: https://github.com/isap-ou/filament-firebase-auth

The plugin major version follows the Filament major version: plugin `5.x` works with Filament `5.x`.

## Installation

```bash
composer require isapp/filament-firebase-auth
php artisan filament-firebase-auth:install   # publishes the config file and the migration
php artisan migrate
php artisan filament:assets
```

- Publish tags: `filament-firebase-auth-config`, `filament-firebase-auth-migrations`.
- The migration creates the link table (config key `table`, default `firebase_identities`).
- The link uses `morphs('authenticatable')`. For UUID or ULID keys, call `Schema::morphUsingUuids()` or `Schema::morphUsingUlids()` before the migration runs.

## Firebase credentials

The server credentials come from `kreait/laravel-firebase` (`config/firebase.php`):

```dotenv
FIREBASE_CREDENTIALS=/path/to/service-account.json
```

The social sign-in buttons use the Firebase JS SDK. They read the web config from `config/filament-firebase-auth.php`:

```dotenv
FIREBASE_WEB_API_KEY=
FIREBASE_WEB_AUTH_DOMAIN=your-project.firebaseapp.com
FIREBASE_WEB_PROJECT_ID=your-project
FIREBASE_WEB_APP_ID=
```

In the Firebase console: enable each sign-in provider, and add the app domain to "Authorized domains".

## Users table

The plugin never stores a password. Make the `password` column nullable, or remove it.

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('password')->nullable()->change();
});
```

A NOT NULL `password` column makes the first record creation fail with a `LogicException` that names the column.

## Link table

One row links one local record to one Firebase user. Model: `Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity`.

| Column | Meaning |
|---|---|
| `firebase_uid` | The Firebase UID. Unique per morph type. |
| `authenticatable_type`, `authenticatable_id` | The local record. Unique pair. |
| `email_verified_at` | Local copy of the Firebase `emailVerified` flag. |
| `synced_at` | The last copy from Firebase. |
| `tokens_valid_after` | Firebase `tokensValidAfterTime`. Part of the session fingerprint. |
| `invalidated_at` | Set when Firebase disables or deletes the user. Part of the session fingerprint. |
| `session_salt` | Rotated by "Log out other sessions". Part of the session fingerprint. |

```php
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;

$admin->firebaseIdentity;                // MorphOne relation
$admin->getFirebaseUid();                // ?string

FirebaseIdentity::query()->forFirebaseUid($uid, (new Admin)->getMorphClass())->first();
```

Never write the link columns by hand. Use `LinkFirebaseIdentity` and `RefreshFirebaseIdentity`.

## Auth config

Give each Firebase panel its own guard, user provider and password broker.

```php
// config/auth.php
'guards' => [
    'admin' => ['driver' => 'session', 'provider' => 'firebase_admins'],
],

'providers' => [
    'firebase_admins' => ['driver' => 'firebase', 'model' => App\Models\Admin::class],
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

- The `firebase` user provider extends Laravel's `EloquentUserProvider`. It reads `model` the same way.
- The `firebase` broker keeps Laravel's database tokens, so the `table` must exist.
- A broker without the `driver` key stays Laravel's own broker.

## Model

```php
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

    protected $fillable = ['name', 'email'];

    public function canAccessPanel(Panel $panel): bool
    {
        return str_ends_with($this->email, '@example.com');
    }
}
```

`HasFirebaseIdentity` needs `Notifiable`. It gives:

| Method | What it does |
|---|---|
| `firebaseIdentity()` | `MorphOne` relation to `FirebaseIdentity`. |
| `getFirebaseUid()` | The linked UID, or null. |
| `hasVerifiedEmail()` | Reads `email_verified_at` of the link. Never calls Firebase. |
| `markEmailAsVerified()`, `markEmailAsUnverified()` | Write the flag to Firebase and to the link. |
| `getEmailForVerification()`, `sendEmailVerificationNotification()` | Laravel's verification email. |
| `hasFirebasePasswordProvider()` | True when the Firebase user can sign in with a password. Calls Firebase once per instance. |
| `getAuthPassword()` | The session fingerprint, not a password. |
| `rotateSessionSalt()` | Logs out the other sessions of the record. |

The trait also drops any write to the password attribute, and deletes the link when the record is deleted (a soft delete keeps the link).

A model that uses Laravel's `Authenticatable` or `MustVerifyEmail` traits directly needs `insteadof`:

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
    use Authenticatable;
    use CanResetPassword;
    use MustVerifyEmail;
    use Notifiable;
    use HasFirebaseIdentity {
        HasFirebaseIdentity::getAuthPassword insteadof Authenticatable;
        HasFirebaseIdentity::hasVerifiedEmail insteadof MustVerifyEmail;
        HasFirebaseIdentity::markEmailAsVerified insteadof MustVerifyEmail;
        HasFirebaseIdentity::markEmailAsUnverified insteadof MustVerifyEmail;
        HasFirebaseIdentity::getEmailForVerification insteadof MustVerifyEmail;
        HasFirebaseIdentity::sendEmailVerificationNotification insteadof MustVerifyEmail;
    }
}
```

## Panel configuration

```php
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;

return $panel
    ->id('admin')
    ->path('admin')
    ->authGuard('admin')
    ->authPasswordBroker('admins')
    ->login()
    ->registration(Register::class)
    ->profile(EditProfile::class)
    ->emailVerification()
    ->emailChangeVerification()
    ->passwordReset()
    ->multiFactorAuthentication([AppAuthentication::make()], isRequired: true)
    ->multiFactorAuthenticationRequiredMiddlewareName(EnsureMultiFactorAuthenticationIsEnabled::class)
    ->plugin(
        FilamentFirebaseAuthPlugin::make()
            ->createOnSignIn(false)
            ->providers([SocialProvider::Google, SocialProvider::Apple, SocialProvider::Microsoft])
            ->providerScopes(SocialProvider::Microsoft, ['mail.read'])
            ->providerParameters(SocialProvider::Microsoft, ['tenant' => 'your-tenant-id']),
    );
```

On the first request of a panel whose guard uses the `firebase` driver, the plugin throws a `LogicException` when the panel uses:

- Filament's own registration page (fix: `->registration(Register::class)`),
- Filament's own profile page (fix: `->profile(EditProfile::class)`),
- Filament's own `AppAuthentication` (fix: `AppAuthentication::make()` from `Isapp\FilamentFirebaseAuth\MultiFactor`),
- Filament's own required multi-factor middleware (fix: `->multiFactorAuthenticationRequiredMiddlewareName(EnsureMultiFactorAuthenticationIsEnabled::class)`).

A host subclass of a plugin page passes the check.

## Plugin methods

`Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin`, plugin ID `filament-firebase-auth`.

| Method | Default | What it does |
|---|---|---|
| `make()` | | Resolves the plugin from the container. |
| `createOnSignIn(bool \| Closure $condition = true)` | `true` | `true`: a Firebase user without a local record gets one on sign-in. `false`: closed panel, only linked or linkable records sign in. |
| `providers(array $providers)` | `[]` | `SocialProvider` cases that get a button on the login and registration pages, in this order. Strings are refused. |
| `providerScopes(SocialProvider $provider, array $scopes)` | `[]` | Extra OAuth scopes. They add to `SocialProvider::getDefaultScopes()` (Apple: `email`, `name`). |
| `providerParameters(SocialProvider $provider, array $parameters)` | `[]` | Custom OAuth parameters, for example `['tenant' => '...']` for Microsoft. |
| `shouldCreateOnSignIn()`, `getProviders()`, `getProviderScopes()`, `getProviderParameters()` | | Getters. |

`Isapp\FilamentFirebaseAuth\Enums\SocialProvider` cases (value = Firebase provider ID): `Google` (`google.com`), `Apple` (`apple.com`), `Microsoft` (`microsoft.com`), `GitHub` (`github.com`), `Facebook` (`facebook.com`), `Twitter` (`twitter.com`), `Yahoo` (`yahoo.com`).

The plugin also adds to the panel:

- the persistent auth middleware `ScheduleFirebaseSessionCheck`, and Filament's `AuthenticateSession` when the panel has no session authentication middleware;
- the route `POST firebase/sign-in` (route name suffix `firebase.sign-in`, rate limiter `filament-firebase-auth`: 5 attempts per minute per panel and IP), only when the panel has providers;
- the buttons in the render hooks `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER` and `PanelsRenderHook::AUTH_REGISTER_FORM_AFTER`.

## Config keys

`config/filament-firebase-auth.php`:

| Key | Default | Meaning |
|---|---|---|
| `table` | `firebase_identities` | The link table. |
| `delete_accounts` | `never` | `never`, `new_only` or `orphaned`. See "Account deletion". An unknown value throws a `ValueError`. |
| `new_account_window` | `60` | For `new_only`: a Firebase account younger than this number of seconds counts as new. |
| `revalidate_every` | `15` | Minutes between background checks of the Firebase user of a session. `0` turns the check off. |
| `revalidate_queue` | `null` | Queue for the check job. `null` runs the job after the response in the same process. |
| `global_logout` | `false` | Shows "Log out everywhere" on the profile page. It revokes the Firebase refresh tokens, also for other apps on the same Firebase project. |
| `social_sign_in` | `js_sdk` | The social sign-in method. `js_sdk` is the only value. Another value throws an `InvalidArgumentException`. |
| `web.api_key`, `web.auth_domain`, `web.project_id`, `web.app_id` | `env('FIREBASE_WEB_*')` | The Firebase web config for the JS SDK. |

## Contracts

The service provider binds the defaults with `bindIf()`. Bind your own class in `AppServiceProvider::register()`. Your binding always wins.

### CreatesLocalUsers

`Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers`. Default: `Isapp\FilamentFirebaseAuth\Actions\LocalUsers\CreateLocalUser` (sets `name` from the Firebase display name, or from the part of the email before `@`, and sets `email`).

```php
public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable;
```

- Return a saved record. The caller creates the link after this call.
- `$panel` is null outside a panel request.
- Extra fields of the registration form do not reach this contract. It gets the Firebase user only.

### SyncsLocalUsers

`Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers`. Default: `Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser` (copies `name` and `email`, then refreshes the link columns). Runs on each sign-in and on each session check.

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Identity\RefreshFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

class SyncAdminRecord implements SyncsLocalUsers
{
    public function __construct(protected RefreshFirebaseIdentity $refreshFirebaseIdentity) {}

    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void
    {
        // Only the owner of the link may write to the record. The default `SyncLocalUser` has the same guard.
        if (! $user->exists) {
            throw new LogicException('Only a saved record can be synced.');
        }

        $identity = $user->getRelationValue('firebaseIdentity');

        if (! $identity instanceof FirebaseIdentity) {
            throw new LogicException('The record has no link to a Firebase user.');
        }

        if ($identity->firebase_uid !== $firebaseUser->uid) {
            throw new LogicException('The record is linked to another Firebase user.');
        }

        if ($firebaseUser->email !== null) {
            $user->forceFill(['email' => $firebaseUser->email])->save();
        }

        // Required: session invalidation reads these link columns.
        $this->refreshFirebaseIdentity->handle($identity, $firebaseUser);
    }
}
```

### DecidesAccountDeletion

`Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion`. Default: `Isapp\FilamentFirebaseAuth\Deletion\AccountDeletionPolicy` (reads `delete_accounts`).

```php
public function shouldDelete(UserRecord $firebaseUser, DeletionReason $reason): bool;
```

`Isapp\FilamentFirebaseAuth\Enums\DeletionReason`: `BlockedOnClosedPanel`, `UserDeletedAccount`, `RecordDeleted`.

In `never` mode the plugin does not call the policy at all. A custom policy runs only when `delete_accounts` is `new_only` or `orphaned`.

### SocialSignInMethod

`Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod`. Default: `Isapp\FilamentFirebaseAuth\SocialSignIn\JsSdkSignIn`. It renders the buttons (`renderButtons(Panel $panel, array $providers): Htmlable`) and proves the Firebase user (`verify(string $credential, array $providers): UserRecord`, throws `AuthenticationException` on any failure).

## Actions

Every action has one public `handle()` method. Inject the action in a constructor, or resolve it from the container, and call `handle()` on the instance. Never call Firebase through `Kreait\Firebase\Contract\Auth` for a flow that an action covers. Firebase exceptions go to the caller unless the table says otherwise.

### `Isapp\FilamentFirebaseAuth\Actions\Firebase`

| Action | `handle()` |
|---|---|
| `FindFirebaseUser` | `(string $uid): ?UserRecord`. Remembers answers for the request. |
| `FetchFirebaseUser` | `(string $uid): ?UserRecord`. Always asks Firebase. Use it after a change in the same request. |
| `FindFirebaseUserByEmail` | `(string $email): ?UserRecord`. Remembers found users for the request. |
| `FindOrCreateFirebaseUser` | `(string $email, string $password, ?string $name, bool $verified): array{UserRecord, bool}`. The bool is true when this call created the account. An existing account keeps its password, name and verification. |
| `CreateFirebaseUser` | `(string $email, string $password, ?string $name, bool $verified): UserRecord` |
| `CheckFirebasePassword` | `(string $email, string $password): ?UserRecord`. Null when Firebase rejects the credentials. A transport failure throws. |
| `DetectPasswordProvider` | `(UserRecord $firebaseUser): bool` |
| `ChangeFirebaseEmail` | `(string $uid, string $email, bool $verified): UserRecord` |
| `ChangeFirebasePassword` | `(string $uid, string $password): UserRecord` |
| `ResetFirebasePassword` | `(string $uid, string $password): UserRecord`. Also marks the email as verified. |
| `SetFirebaseDisplayName` | `(string $uid, string $name): UserRecord` |
| `SetFirebaseEmailVerified` | `(string $uid, bool $verified): void` |
| `RevokeFirebaseTokens` | `(string $uid): void`. Affects other apps on the same Firebase project. |
| `UnlinkFirebaseProviders` | `(string $uid, array $keep = ['password']): UserRecord` |
| `DeleteFirebaseUser` | `(string $uid): void`. A missing account counts as deleted. |

### `Isapp\FilamentFirebaseAuth\Actions\Identity`

| Action | `handle()` |
|---|---|
| `LinkFirebaseIdentity` | `(Model & Authenticatable $user, UserRecord $firebaseUser): FirebaseIdentity`. Creates or refreshes the link. Throws `FirebaseIdentityNotLinkable` for a disabled Firebase user, an unsaved record, a record linked to another UID, or a UID linked to another record of the same model. |
| `EnsureFirebaseIdentityLinkable` | `(Model & Authenticatable $user, UserRecord $firebaseUser): ?FirebaseIdentity`. The same checks without a write. |
| `RefreshFirebaseIdentity` | `(FirebaseIdentity $identity, UserRecord $firebaseUser): void`. Copies the Firebase state to the link columns. |
| `ResolveLocalUser` | `(UserRecord $firebaseUser, string $model, bool $createOnSignIn): ResolvedUser`. Never writes. |
| `PersistResolvedUser` | `(ResolvedUser $resolved, UserRecord $firebaseUser, ?Panel $panel): Model & Authenticatable`. Call it only after Firebase proved the credentials. |

`Isapp\FilamentFirebaseAuth\Data\ResolvedUser` has `resolution` (`Isapp\FilamentFirebaseAuth\Enums\Resolution`: `Linked`, `LinkedByEmail`, `New`, `Blocked`) and `user`.

### `Isapp\FilamentFirebaseAuth\Actions\LocalUsers`

`CreateLocalUser` and `SyncLocalUser`: the default classes of `CreatesLocalUsers` and `SyncsLocalUsers`. Resolve the contracts, not these classes, so a host binding applies.

### `Isapp\FilamentFirebaseAuth\Actions\Accounts`

| Action | `handle()` |
|---|---|
| `SignInWithFirebaseCredential` | `(string $credential, Panel $panel): Authenticatable`. The social sign-in flow. |
| `DeleteUserAccount` | `(Model & Authenticatable $user): void`. Deletes the link and the record, then runs the policy with `UserDeletedAccount`. |
| `DeleteFirebaseUserIfAllowed` | `(UserRecord $firebaseUser, DeletionReason $reason): bool`. Asks `DecidesAccountDeletion`, then deletes. |
| `DetectUserWithoutPassword` | `(?Authenticatable $user): bool`. True only for a linked user whose Firebase user has no `password` provider. False on any failure. |
| `RenewCurrentSession` | `(Authenticatable $user): void`. Signs the current session in again after the fingerprint changed. Fires Laravel's `Login` event. |

### `Isapp\FilamentFirebaseAuth\Actions\Panels`

`EnsurePanelUsesFirebasePages`: `(Panel $panel): void`. The first-request check of the panel pages and multi-factor classes.

## Events

The plugin fires no events of its own. Laravel and Filament events keep firing: Filament's `Registered` event on the registration page, Laravel's `Login` event on each sign-in and on each session renewal after a password change, an email change or "Log out other sessions", and `PasswordResetLinkSent` from the broker. Listen to these events in host code.

## Commands

| Command | What it does |
|---|---|
| `php artisan filament-firebase-auth:install` | Publishes the config file and the migration. |
| `php artisan make:filament-user` (aliases `filament:make-user`, `filament:user`) | Replaces Filament's command. Options: `--name`, `--email`, `--password`, `--panel`. Omit `--password`: the command then asks for it, and the shell history does not keep it. |

On a Firebase panel, `make:filament-user`:

1. Finds the Firebase account by email, or creates it with `emailVerified = true`.
2. Refuses an existing account that is disabled, has an unverified email, or is linked to another record of the panel model. Exit code 1.
3. Creates the local record through `CreatesLocalUsers` and the link, in one transaction.
4. Deletes the Firebase account when the local step fails, but only when the command created it.

An existing account keeps its password. On a panel without the `firebase` driver, the command runs Filament's own behaviour.

## Flows

### Email and password sign-in

Filament's own login page. The `firebase` provider asks Firebase for the password check. Then `ResolveLocalUser` applies these rules in order:

1. A link exists for this UID and model: use its record.
2. No link, but a record has the same email: link it only when Firebase verified the email.
3. No link, no record, and `createOnSignIn(true)`: create the record through `CreatesLocalUsers`.
4. Otherwise: block.

A disabled Firebase user is always blocked. Every failure (wrong password, unknown email, closed panel, unlinkable record, disabled account, no panel access, Firebase outage) shows Filament's failure message `filament-panels::auth/pages/login.messages.failed`. Filament's rate limit and timebox stay.

### Social sign-in

1. The host lists providers with `->providers([...])`.
2. The browser signs in with the Firebase JS SDK popup and posts the ID token to `firebase/sign-in`.
3. The server checks the token with `verifyIdToken($token, checkIfRevoked: true)`. The token must come from one of the panel providers.
4. The same resolve rules apply, then `canAccessPanel()`, then the panel guard logs in.

Social sign-in skips Filament's multi-factor challenge. The social provider owns the second factor.

### Registration

`Isapp\FilamentFirebaseAuth\Pages\Register`. Only the save step changes:

1. Creates the Firebase account with `emailVerified = false`.
2. Creates the local record through `CreatesLocalUsers` and the link.
3. When the local step fails or the page transaction rolls back, deletes the Firebase account it created. This happens in every `delete_accounts` mode.

The form, rate limits, `Registered` event, verification email and login stay Filament's. To change the form, extend `Register`.

### Email verification

Filament's own page and Laravel's `VerifyEmail` notification. `markEmailAsVerified()` writes the flag to Firebase and to the link.

### Password reset

Filament's own pages with the `firebase` broker. Laravel creates and checks the tokens. Filament sends the email. On reset, the broker sets the new password in Firebase, marks the email as verified and links the record. An unknown email shows the same "sent" message.

### Profile

`Isapp\FilamentFirebaseAuth\Pages\EditProfile`. The form is Filament's.

- Firebase checks the current password.
- A new email or password goes to Firebase. A new email is unverified.
- A user without the Firebase `password` provider (for example Google only) cannot change the email or the password here.
- Actions: "Log out other sessions" (rotates the session salt), "Log out everywhere" (only with `global_logout = true`), "Delete account" (`DeleteUserAccount`).

### Multi-factor authentication

Filament's app authentication works on top of the plugin. Use `Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication`: Firebase checks the password in its actions. For required MFA, use `Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled`: a user without the Firebase `password` provider passes. The model implements Filament's `HasAppAuthentication` (and `HasAppAuthenticationRecovery`) as Filament documents.

### Session check

Every `revalidate_every` minutes, the middleware dispatches the job `Isapp\FilamentFirebaseAuth\Jobs\SyncFirebaseIdentity`. The job copies the Firebase state to the link. A disabled, deleted or revoked Firebase user changes the session fingerprint. Laravel's `AuthenticateSession` then logs the session out on the next request.

### Account deletion

| `delete_accounts` | What the plugin deletes in Firebase |
|---|---|
| `never` (default) | Nothing. Safe when other apps share the Firebase project. |
| `new_only` | Only an account that Firebase created during this sign-in, when a closed panel blocks it. |
| `orphaned` | Any account with no link left in this app: after a block, a self-deletion or a record deletion. |

Warning: use `orphaned` only when this app is the only user of the Firebase project. The plugin sees only its own links. It deletes accounts that other apps still use.

- A record deletion (`$admin->delete()`) deletes the link, then runs the policy with `RecordDeleted`. A soft delete keeps the link. `forceDelete()` runs the policy.
- The Firebase part runs after the open transaction commits. Its failure never stops the local deletion. The plugin logs it.
- A query builder bulk delete (`Admin::query()->delete()`) fires no model events, so the link stays and the policy does not run.
- Deletion runs only after Firebase proved the credentials. A wrong password never deletes anything.

### Give a record a Firebase account in host code

The reference implementation is `Isapp\FilamentFirebaseAuth\Commands\MakeFirebaseUserCommand` (`createUser()`). Copy its order:

1. Find or create the Firebase account.
2. Refuse an existing account that is disabled, has an unverified email, or is linked to another record of the model. Do this before `CreatesLocalUsers` runs, because a host action can send mail or start jobs.
3. Create the record and the link in one transaction.
4. When the local step fails and this call created the account, delete the account, then rethrow.

```php
use App\Models\Admin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindOrCreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use RuntimeException;
use Throwable;

class InviteAdmin
{
    public function __construct(
        protected FindOrCreateFirebaseUser $findOrCreateFirebaseUser,
        protected CreatesLocalUsers $createsLocalUsers,
        protected LinkFirebaseIdentity $linkFirebaseIdentity,
        protected DeleteFirebaseUser $deleteFirebaseUser,
    ) {}

    public function handle(string $email, string $password, string $name): Admin
    {
        [$firebaseUser, $created] = $this->findOrCreateFirebaseUser->handle($email, $password, $name, verified: true);

        if (! $created) {
            $this->ensureExistingAccountLinkable($firebaseUser);
        }

        try {
            return DB::transaction(function () use ($firebaseUser): Admin {
                $admin = $this->createsLocalUsers->handle($firebaseUser, Admin::class, Filament::getPanel('admin'));

                $this->linkFirebaseIdentity->handle($admin, $firebaseUser);

                return $admin;
            });
        } catch (Throwable $exception) {
            // Delete only an account that this call created. An existing account belongs to someone.
            if ($created) {
                $this->deleteFirebaseUser->handle($firebaseUser->uid);
            }

            throw $exception;
        }
    }

    protected function ensureExistingAccountLinkable(UserRecord $firebaseUser): void
    {
        $error = match (true) {
            $firebaseUser->disabled => 'The existing Firebase account is disabled.',
            ! $firebaseUser->emailVerified => 'The existing Firebase account has an unverified email.',
            FirebaseIdentity::query()->forFirebaseUid($firebaseUser->uid, (new Admin)->getMorphClass())->exists() => 'Another admin is linked to the existing Firebase account.',
            default => null,
        };

        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }
}
```

The command also logs a failed cleanup deletion and names the UID, so that an admin can delete the account by hand. Add the same handling when the cleanup must never hide the first error.

## Limitations

- No passkeys, no Firebase multi-factor auth (SMS, TOTP), no phone or anonymous auth, no Firebase multi-tenancy.
- No custom `oidc.*` or `saml.*` providers. Only the `SocialProvider` cases.
- No API token auth. The plugin is for panel sessions.
- No import of local password hashes into Firebase.
- All emails go through Laravel, not Firebase templates.
- The email match is case-sensitive on SQLite and PostgreSQL, the same as Laravel's `EloquentUserProvider`.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `LogicException`: "... its registration page is [...]" or "... its profile page is [...]" | The Firebase panel uses Filament's own page. Use `->registration(Register::class)` or `->profile(EditProfile::class)` from `Isapp\FilamentFirebaseAuth\Pages`. |
| `LogicException` about app authentication or the required multi-factor middleware | Use `Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication::make()` and `Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled`. |
| `LogicException`: 'The "users" table has a NOT NULL "password" column' | Make the `password` column nullable. |
| PHP fatal error: "... has not been applied ... because of collision with Illuminate\Auth\MustVerifyEmail ..." | Add the `insteadof` block from "Model". |
| `InvalidArgumentException`: "Password resetter [...] needs a user provider with an Eloquent model." | The `firebase` broker's `provider` has no `model`. |
| Every sign-in shows the generic failure message | Check the logs. The plugin logs the exception class of a Firebase failure. Check the Firebase credentials, `canAccessPanel()`, `createOnSignIn()`, and that a linked-by-email account has a verified email. |
| No social buttons | The plugin has no `providers()`, or `filament:assets` did not run. |
| The popup fails or closes at once | The provider is not enabled in Firebase, or the app domain is not in "Authorized domains", or the `web.*` config is empty. |
| A user stays signed in after the account was disabled | The check waits `revalidate_every` minutes. With `revalidate_queue` set, a queue worker must run. |
| Firebase accounts of another app were deleted | `delete_accounts` is `orphaned` on a shared Firebase project. Set it to `never`. |
