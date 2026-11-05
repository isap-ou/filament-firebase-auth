---
name: filament-firebase-auth-development
description: Set up and extend isapp/filament-firebase-auth, which signs people in to Filament panels with Firebase Authentication; activate it when you work on panel auth, the firebase user provider or password broker driver, the HasFirebaseIdentity trait, the firebase_identities table, social sign-in buttons or make:filament-user on a Firebase panel.
license: MIT
metadata:
  author: ISAPP
---

# Filament Firebase Auth Development

## Overview

`isapp/filament-firebase-auth` connects Filament 5 panels to Firebase Authentication. Firebase keeps the password, the email verification flag and the sign-in providers. The local record never stores a password or a password hash. The table `firebase_identities` links each local record to one Firebase user.

The plugin keeps Filament's own pages first. Login, password reset and email verification are Filament's pages. Firebase plugs in through Laravel contracts: the `firebase` user provider driver and the `firebase` password broker driver. Registration and profile have no contract, so the plugin ships two thin subclasses. The host selects them explicitly.

## When to Activate

- Activate when you configure a Filament panel that signs in with Firebase.
- Activate when code references `FilamentFirebaseAuthPlugin`, `HasFirebaseIdentity`, `FirebaseIdentity`, `CreatesLocalUsers`, `SyncsLocalUsers`, `DecidesAccountDeletion` or a class in `Isapp\FilamentFirebaseAuth\Actions`.
- Activate when you edit `config/auth.php` guards, providers or password brokers with the `firebase` driver, or `config/filament-firebase-auth.php`.
- Activate when the user asks to create a panel user, add social sign-in buttons, change account deletion, or add multi-factor authentication to a Firebase panel.

## Scope

- In scope: panel auth setup, guards, user providers and password brokers, the model trait, plugin options, extension contracts, account deletion modes, `make:filament-user`, Filament's app authentication on a Firebase panel.
- Out of scope: API token auth (Sanctum, bearer ID tokens), the Firebase client SDK on non-Filament pages, Firestore, Realtime Database, Firebase multi-factor auth, passkeys, phone and anonymous auth, custom `oidc.*` and `saml.*` providers.

## Workflow

1. Identify the task: installation, auth config, model, panel options, a flow (sign-in, registration, profile, password reset, deletion), an extension, or troubleshooting.
2. Read `references/filament-firebase-auth-guide.md` and focus on the matching section.
3. Apply the pattern from the reference. Keep Filament's own pages and Laravel's auth contracts. Change only what Firebase needs.

## Core Concepts

### Auth config

Each Firebase panel has its own guard. The guard's user provider and the panel's password broker use the `firebase` driver. The broker needs Laravel's token table.

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

### Panel plugin

Select the plugin pages explicitly. A Firebase panel with Filament's own registration or profile page throws a `LogicException` on its first request.

```php
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;

$panel
    ->id('admin')
    ->authGuard('admin')
    ->authPasswordBroker('admins')
    ->login()
    ->registration(Register::class)
    ->profile(EditProfile::class)
    ->emailVerification()
    ->passwordReset()
    ->plugin(
        FilamentFirebaseAuthPlugin::make()
            ->createOnSignIn(false) // closed panel: only linked or linkable records sign in
            ->providers([SocialProvider::Google, SocialProvider::Microsoft])
            ->providerParameters(SocialProvider::Microsoft, ['tenant' => 'your-tenant-id']),
    );
```

### Model trait and interfaces

The model implements `MustVerifyEmail` and `CanResetPassword`, and uses `HasFirebaseIdentity` and `Notifiable`. The `password` column must be nullable.

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
        return true;
    }
}
```

A model that uses Laravel's `Authenticatable` or `MustVerifyEmail` traits directly (not through `Illuminate\Foundation\Auth\User`) needs `insteadof`. See "Model" in the reference.

### Binding a custom CreatesLocalUsers

The plugin binds its defaults with `bindIf()`. A host binding always wins.

```php
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;

class CreateAdminRecord implements CreatesLocalUsers
{
    public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable
    {
        $user = new $model([
            'name' => $firebaseUser->displayName ?? (string) $firebaseUser->email,
            'email' => (string) $firebaseUser->email,
        ]);

        $user->save();

        return $user; // Return a saved record. The plugin creates the link after this call.
    }
}

// AppServiceProvider::register()
$this->app->bind(CreatesLocalUsers::class, CreateAdminRecord::class);
```

### Creating a user with Artisan

The plugin replaces Filament's `make:filament-user`. On a Firebase panel, it finds or creates the Firebase account, then creates the local record through `CreatesLocalUsers` and the link.

```bash
php artisan make:filament-user --panel=admin --name="Ada Admin" --email=ada@example.com
# The command asks for the password. Do not pass --password on the command line: the shell history keeps it.
```

## Do and Don't

Do:
- Use the `firebase` driver for the user provider of the panel guard and for the panel password broker.
- Make the `password` column of the users table nullable, or remove it.
- Select `Pages\Register`, `Pages\EditProfile`, `MultiFactor\AppAuthentication` and `Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled` explicitly in the panel configuration.
- Use the plugin actions in `Isapp\FilamentFirebaseAuth\Actions` for Firebase calls in app code. Inject them in the constructor and call `handle()`.
- Bind your own class to `CreatesLocalUsers`, `SyncsLocalUsers` or `DecidesAccountDeletion` to change those steps.
- In a custom `SyncsLocalUsers`, inject `Isapp\FilamentFirebaseAuth\Actions\Identity\RefreshFirebaseIdentity` and call its `handle()` with the link of the record. Without it, session invalidation breaks.
- Keep `delete_accounts` at `never` when other apps share the Firebase project.

Don't:
- Don't call `Hash::make()` on a password, and don't store a password or a hash locally. Firebase keeps the password.
- Don't call `Kreait\Firebase\Contract\Auth` directly for flows that the plugin covers (sign-in, registration, password change, email change, reset, deletion). Use the plugin actions.
- Don't use `delete_accounts = orphaned` when other apps share the Firebase project. The plugin cannot see the links of other apps, so it deletes accounts that they still use.
- Don't create Filament users with `Model::create()`. Use `make:filament-user`, or copy the "Give a record a Firebase account in host code" sample in the reference. It mirrors `MakeFirebaseUserCommand`: `FindOrCreateFirebaseUser`, the checks of an existing account, `CreatesLocalUsers` and `LinkFirebaseIdentity` in one transaction, and `DeleteFirebaseUser` for an account that the call created when the local step fails.
- Don't use Filament's own `Register`, `EditProfile` or app authentication classes, or Filament's own required multi-factor middleware, on a Firebase panel.
- Don't add custom static methods, static properties or class constants in extensions. Use instance methods, Eloquent scopes and classes from the container.
- Don't show different error messages for a wrong password, an unknown email or a closed panel. The plugin shows Filament's own failure message for all of them.

## References

- `references/filament-firebase-auth-guide.md`
