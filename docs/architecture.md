# Architecture

This guide explains how `isapp/filament-firebase-auth` connects Filament panels to Firebase Authentication.
It is for developers who install the plugin and want to know what happens inside.
Read the [README](../README.md) first for the setup.

## The idea in one paragraph

Filament does not check passwords itself. Its pages call Laravel's auth contracts: a user provider, a password broker, a guard and the methods of the user model.
The plugin implements these contracts with Firebase behind them.
So Filament's own pages, guards, policies, rate limits, events and translations keep working.
Firebase is the source of truth for credentials. The local database keeps the records of your panel models, but never a password.

## Laravel contracts the plugin implements

| Contract | Plugin class | What it does |
|---|---|---|
| `Illuminate\Contracts\Auth\UserProvider` | `Auth\FirebaseUserProvider` (driver `firebase`) | `retrieveByCredentials()` finds the Firebase user by email and resolves the local record without saving it. `validateCredentials()` checks the password with Firebase, then saves the record and its link. `rehashPasswordIfRequired()` does nothing. |
| `Illuminate\Contracts\Auth\PasswordBroker` | `Passwords\FirebasePasswordBroker` (driver `firebase`) | Laravel creates and checks the reset tokens, and Filament sends the email. On `reset()`, the broker sets the new password in Firebase and marks the email as verified. |
| `Illuminate\Contracts\Auth\PasswordBrokerFactory` | `Passwords\FirebasePasswordBrokerManager` | Laravel's broker manager has no `extend()` hook. The plugin replaces the `auth.password` binding with a subclass that builds the `firebase` broker. Every other broker comes from Laravel's code. |
| `Illuminate\Contracts\Auth\MustVerifyEmail` | trait `Concerns\HasFirebaseIdentity` | `hasVerifiedEmail()` reads the local copy of the Firebase flag. `markEmailAsVerified()` writes the flag to Firebase and to the local copy. |
| `Illuminate\Contracts\Auth\CanResetPassword` | trait `Concerns\HasFirebaseIdentity` | Gives the email to the broker. |
| `Authenticatable::getAuthPassword()` | trait `Concerns\HasFirebaseIdentity` | Returns a session fingerprint, not a password. See [Session check](#session-check). |

Why contracts and not new pages:

- Filament's login, password reset and email verification pages need no change. They already call these contracts.
- Filament's rate limits, its timebox and its failure messages stay in place.
- An update of Filament changes the pages, not the contracts. The contracts are stable.

Registration and profile have no contract. Filament's pages hash the password and save it in the model.
So the plugin ships thin subclasses, `Pages\Register` and `Pages\EditProfile`. Only the save steps change. The form and the UI stay Filament's.
For the same reason, the plugin ships `MultiFactor\AppAuthentication` and `Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled`.
The host selects all of them in the panel configuration.

On the first request of a panel, `Actions\Panels\EnsurePanelUsesFirebasePages` checks the panel.
When the panel guard uses the `firebase` driver and the panel uses one of Filament's own classes above, it throws a `LogicException` that names the fix.
The plugin never swaps pages behind your back. Filament registers the Livewire components of the pages when it builds the panel, and `filament:cache-components` caches them, so a later swap would be fragile.

Social sign-in has no Laravel contract either. The plugin adds the buttons with panel render hooks and posts the ID token to a route of the panel.

## The link table

The plugin does not change your tables. It adds one polymorphic link table, `firebase_identities` by default (config key `table`):

| Column | Meaning |
|---|---|
| `firebase_uid` | The Firebase UID. |
| `authenticatable_type`, `authenticatable_id` | The local record (a morph relation). |
| `email_verified_at` | The local copy of the Firebase `emailVerified` flag. |
| `synced_at` | The last time the plugin read the Firebase user. |
| `tokens_valid_after` | The local copy of the Firebase "tokens valid after" time. Firebase revokes tokens before this time. |
| `invalidated_at` | Set when the Firebase account is missing or disabled. |
| `session_salt` | A random value. A new value logs out the other sessions of the record. The plugin writes a new value when the email becomes verified or when the Firebase user loses the `password` provider. |
| `has_password_provider` | The last known state of the Firebase `password` provider. Null until the plugin first reads the providers. |

Two unique indexes apply: `(authenticatable_type, firebase_uid)` and `(authenticatable_type, authenticatable_id)`. The result:

- One Firebase user has at most one record in each model. The same person can be an `Admin` and a `Customer`.
- One record has at most one Firebase user.
- Two panels that share a model share the record.

Why a separate table and not a `firebase_uid` column on `users`:

- Each panel can use its own model. The auth model is not always `User`.
- Your own migrations stay as they are.

Why a local copy of `emailVerified`: Filament checks email verification on every request. A Firebase call on every request would be slow.
The plugin refreshes the copy on each sign-in and on each session check.

The morph columns follow Laravel's default morph key type. For UUID or ULID keys, read the README section "Installation".

## How a local record is resolved

Every sign-in method (email and password, social) uses the same rule, `Actions\Identity\ResolveLocalUser`.
The input is a Firebase user and the model of the panel. The rules apply in this order:

1. **A link exists** for this Firebase UID and model. The plugin uses its record.
2. **No link, but a record of the model has the same email.**
   - Firebase has verified the email: the plugin links this record.
   - Firebase has not verified the email: the sign-in fails.
   - The record is already linked to another Firebase UID: the sign-in fails.
3. **No link, no record, and the panel is open** (`createOnSignIn(true)`): the plugin offers a new record. It is not saved yet.
4. **Otherwise**, the sign-in fails.

A disabled Firebase account always fails.

### Why an unverified email never links a record

Anyone can create a Firebase account with any email address, also with the email of your administrator.
Firebase does not check the address until the person clicks a verification link.
If an unverified account could link an existing record, a stranger could create an account with the admin's email and get the admin's record.

So rule 2 needs a verified email. An existing user whose Firebase email is not verified resets the password.
The reset email proves that the person owns the address, so a successful reset marks the email as verified in Firebase.
The next sign-in then links the record. For an unverified account, the reset also revokes the old sessions and removes other sign-in providers, because they may belong to the stranger.

### When the plugin saves

The resolution itself never writes. `Actions\Identity\PersistResolvedUser` saves the answer only after Firebase proved the credentials, in one database transaction:

- A new record: the `CreatesLocalUsers` contract creates it, then the plugin links it.
- A record found by email: the plugin links it.
- Every record: the `SyncsLocalUsers` contract copies the Firebase name and email and refreshes the link columns.

Filament checks the credentials twice in one login request. The plugin asks Firebase only once and creates only one record.

### Same message for every failure

A wrong password, an unknown email, a closed panel, an unlinkable record, a disabled account and no panel access all show Filament's own failure message.
The password check also runs when the sign-in fails anyway. So every attempt makes the same number of Firebase calls, and the timing does not show which emails exist.
Password reset requests always show the "sent" message for the same reason.

## Social sign-in

1. The panel lists its providers with `providers([SocialProvider::Google, ...])`.
2. Filament's login and registration pages show one button per provider through a render hook. The JavaScript bundle loads only on these pages.
3. The browser signs in with `signInWithPopup()` of the Firebase JS SDK. It keeps the Firebase user in memory only, and signs out of the SDK after the request. The Laravel session is the only session.
4. The browser posts the ID token in the JSON body to the `firebase/sign-in` route of the panel. The route has the panel middleware, the CSRF check and a rate limit.
5. The server checks the token with `verifyIdToken()`, also for revocation. The `firebase.sign_in_provider` claim must name a provider of the panel, and the `auth_time` claim must be at most 5 minutes old.
6. The plugin resolves and saves the record with the rule above, checks `canAccessPanel()`, logs in with the panel guard and regenerates the session.

The `SocialSignInMethod` contract renders the buttons and proves the Firebase user. The config key `social_sign_in` selects it. `js_sdk` is the only value today.
The rest of the flow does not depend on the method.

Social sign-in trusts the provider and its own second factor. It does not run Filament's multi-factor challenge.

## Account deletion

The plugin can see only its own links. It can never know whether another app on the same Firebase project still uses an account.
This fact shapes the deletion design.

The plugin always deletes the local link when a record is deleted. The `delete_accounts` config key decides about the Firebase account:

| Mode | What the plugin deletes in Firebase |
|---|---|
| `never` (default) | Nothing. |
| `new_only` | Only an account that Firebase created during this sign-in, when a closed panel blocks it. |
| `orphaned` | Any account with no link left in this app. |

The plugin asks the deletion policy in three cases (the `DeletionReason` enum):

- `BlockedOnClosedPanel`: a panel with `createOnSignIn(false)` blocked a Firebase user without a record. For a password sign-in, this happens only after Firebase accepted the password.
- `UserDeletedAccount`: the person deleted the account on the profile page.
- `RecordDeleted`: code or an admin deleted the record. The trait listens to the model's `deleted` event. A soft delete keeps the link.

"Created during this sign-in" for `new_only` is a heuristic: the creation time and the last sign-in time of the Firebase account are within `new_account_window` seconds of each other and of now.

> [!WARNING]
> `orphaned` deletes the Firebase account as soon as this app has no link to it.
> If a mobile app or another web app shares the Firebase project, the person then loses access to those apps too.
> A blocked sign-in on a closed panel deletes too: when a person with no link in this app signs in with the correct password or with a social provider such as Google (for example a user of your mobile app), `orphaned` deletes that person's Firebase account for all apps.
> Use `orphaned` only when this app is the only user of the Firebase project.

Other rules:

- In `never` mode, the plugin does not call Firebase for deletion and does not ask the policy.
- The Firebase part runs after the database transaction commits, so a rollback never loses a Firebase account.
- A Firebase failure never stops the local deletion. The plugin logs the UID and the exception class.
- A failed registration deletes the Firebase account that the same request created, in every mode.

## Session check

Another app or the Firebase console can delete or disable an account, or revoke its tokens. Firebase revokes tokens on every password change.
Firebase does not notify your app. Without a check, a session stays alive until it expires, and a "remember me" cookie keeps it alive for months.

The plugin uses Laravel's `AuthenticateSession` middleware for the logout and writes no logout code of its own:

1. `HasFirebaseIdentity::getAuthPassword()` returns a fingerprint: a hash of the Firebase UID, `tokens_valid_after`, `invalidated_at` and `session_salt`. It is never empty, because `AuthenticateSession` skips users with an empty value.
2. `AuthenticateSession` stores this value in the session and in the "remember me" cookie when the user signs in. On each request, it compares the stored value with the current one, and logs the session out when they differ.
3. `Http\Middleware\ScheduleFirebaseSessionCheck` runs on authenticated panel requests, also on Livewire updates. When the last check of the session is older than `revalidate_every` minutes, it dispatches `Jobs\SyncFirebaseIdentity`: after the response, or on the queue in `revalidate_queue`. The request never waits for Firebase.
4. The job reads the Firebase user. A missing or disabled account sets `invalidated_at`. Otherwise, the job copies the "tokens valid after" time and syncs the record.
5. A changed column changes the fingerprint. The next request of every old session logs out.

A Firebase connection error changes nothing. The next interval tries again.

The plugin adds Filament's `AuthenticateSession` to the panel middleware when the panel has none.

Profile actions use the same mechanism:

- "Log out other sessions" writes a new `session_salt`.
- "Log out everywhere" (config `global_logout`) revokes the Firebase refresh tokens, so `tokens_valid_after` changes. This also signs the person out of other apps on the same Firebase project.
- A password or email change in Firebase revokes the tokens too.

After each of these changes, the plugin signs the current session in again, so that only the other sessions log out.

## Extension points

The plugin binds a default class to each contract only when the container has no binding yet. Bind your own class in the `register()` method of a service provider.

### `Contracts\CreatesLocalUsers`

Creates the local record for a Firebase user. It must return a saved record. The plugin creates the link after the call.
The `$panel` argument is the current panel, or `null` outside a panel.

```php
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
            'role' => $panel?->getId() === 'admin' ? 'admin' : 'viewer',
        ]);
    }
}

// `create()` fills only the attributes in `$fillable`, so `role` must be fillable. Or use `forceCreate()`.

// In a service provider:
$this->app->bind(CreatesLocalUsers::class, CreateAdmin::class);
```

### `Contracts\SyncsLocalUsers`

Copies the Firebase data to a linked record on each sign-in and on each session check.
Your class must also refresh the link columns (`email_verified_at`, `synced_at`, `tokens_valid_after`, `invalidated_at`), or the session check breaks.
Extend `Actions\LocalUsers\SyncLocalUser` and call `parent::handle()`, or inject `Actions\Identity\RefreshFirebaseIdentity` and call its `handle()` with the link.

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Kreait\Firebase\Auth\UserRecord;

class SyncAdmin extends SyncLocalUser
{
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void
    {
        parent::handle($user, $firebaseUser);

        $user->forceFill(['avatar_url' => $firebaseUser->photoUrl])->save();
    }
}

// In a service provider:
$this->app->bind(SyncsLocalUsers::class, SyncAdmin::class);
```

### `Contracts\DecidesAccountDeletion`

Decides whether the plugin deletes a Firebase account. The default, `Deletion\AccountDeletionPolicy`, reads `delete_accounts`.
Your class runs only when `delete_accounts` is not `never`.

```php
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Kreait\Firebase\Auth\UserRecord;

class DeleteOnlyPanelAccounts implements DecidesAccountDeletion
{
    public function shouldDelete(UserRecord $firebaseUser, DeletionReason $reason): bool
    {
        // Delete only accounts that carry a custom claim set by this app.
        return ($firebaseUser->customClaims['panel_only'] ?? false) === true;
    }
}

// In a service provider:
$this->app->bind(DecidesAccountDeletion::class, DeleteOnlyPanelAccounts::class);
```

### `Contracts\SocialSignInMethod`

Renders the social buttons and proves the Firebase user of a credential. The default is `SocialSignIn\JsSdkSignIn`.
A custom method must accept only credentials from the given providers, and must throw `Illuminate\Auth\AuthenticationException` without detail on any failure.

### Actions

All behaviour lives in single-purpose action classes with one `handle()` method. They are grouped by domain:

- `Actions\Firebase\`: calls to Firebase, for example `FindFirebaseUserByEmail`, `CreateFirebaseUser`, `FindOrCreateFirebaseUser`, `RevokeFirebaseTokens`.
- `Actions\Identity\`: the link table and the resolution, for example `ResolveLocalUser`, `LinkFirebaseIdentity`, `RefreshFirebaseIdentity`.
- `Actions\LocalUsers\`: the host model, `CreateLocalUser` and `SyncLocalUser`.
- `Actions\Accounts\`: the account lifecycle, for example `DeleteUserAccount` and `DeleteFirebaseUserIfAllowed`.

Resolve them from the container and call `handle()`. For example, to create a panel user from your own Filament resource:

```php
use App\Models\Admin;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindOrCreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;

[$firebaseUser, $created] = app(FindOrCreateFirebaseUser::class)
    ->handle($email, $password, $name, verified: true);

$admin = app(CreatesLocalUsers::class)->handle($firebaseUser, Admin::class, filament()->getCurrentPanel());

app(LinkFirebaseIdentity::class)->handle($admin, $firebaseUser);
```

`make:filament-user` uses the same actions. It also refuses an existing Firebase account that is disabled, unverified or already linked, and deletes an account that it created when the local step fails.
Your own code must handle these cases too.
