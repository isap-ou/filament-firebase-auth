<?php

namespace Isapp\FilamentFirebaseAuth\Passwords;

use Closure;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Timebox;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\ChangeFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DetectPasswordProvider;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\SetFirebaseEmailVerified;
use Isapp\FilamentFirebaseAuth\Actions\Identity\EnsureFirebaseIdentityLinkable;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * The `firebase` password broker. Laravel creates and checks the tokens, and Filament sends
 * the notification. Firebase keeps the password.
 *
 * The broker never calls `retrieveByCredentials()` of the user provider. The `firebase`
 * provider treats credentials with a password as a sign-in attempt and calls Firebase.
 *
 * Laravel keeps one broker instance per broker name, also across requests in a long-lived
 * worker. So the broker keeps no state and resolves every action from the container at call time.
 * Every Firebase call goes through an action in `Actions/Firebase`.
 */
class FirebasePasswordBroker extends PasswordBroker
{
    /**
     * @param  class-string<Model&Authenticatable>  $model  The model of the user provider.
     */
    public function __construct(
        #[SensitiveParameter]
        TokenRepositoryInterface $tokens,
        UserProvider $users,
        protected string $model,
        ?Dispatcher $dispatcher = null,
        ?Timebox $timebox = null,
        int $timeboxDuration = 200000,
    ) {
        parent::__construct($tokens, $users, $dispatcher, $timebox, $timeboxDuration);
    }

    /**
     * Filament's page shows an error for `INVALID_USER` and a "sent" message for `RESET_LINK_SENT`.
     * An unknown email gets the "sent" message too, so that nobody can find out which emails exist.
     *
     * @param  array<string, mixed>  $credentials
     * @return string
     */
    public function sendResetLink(#[SensitiveParameter] array $credentials, ?Closure $callback = null)
    {
        $status = parent::sendResetLink($credentials, $callback);

        return $status === static::INVALID_USER ? static::RESET_LINK_SENT : $status;
    }

    /**
     * Checks the token, then sets the new password in Firebase and marks the email as verified,
     * because the reset proves that the person owns the email. Then it links the record and copies
     * the Firebase state to the link. Then it calls the callback and deletes the token.
     *
     * The token is deleted last. When a Firebase call fails, the exception goes to the caller
     * and the same link works again.
     *
     * @param  array<string, mixed>  $credentials
     * @return string
     */
    public function reset(#[SensitiveParameter] array $credentials, Closure $callback)
    {
        return $this->timebox->call(function (Timebox $timebox) use ($credentials, $callback): string {
            $found = $this->find($credentials);

            if ($found === null) {
                return static::INVALID_USER;
            }

            [$firebaseUser, $user] = $found;

            $token = $credentials['token'] ?? null;
            $password = $credentials['password'] ?? null;

            if (! is_string($token) || ! $this->tokens->exists($user, $token)) {
                return static::INVALID_TOKEN;
            }

            if (! is_string($password) || $password === '') {
                return static::INVALID_USER;
            }

            $this->resetInFirebase($user, $firebaseUser, $password);

            $callback($user, $password);

            $this->tokens->delete($user);

            $timebox->returnEarly();

            return static::PASSWORD_RESET;
        }, $this->timeboxDuration);
    }

    /**
     * Returns the local record of the Firebase user with this email, or null.
     *
     * Null when Firebase has no user with this email, the Firebase user is disabled or has no
     * `password` provider (for example a Google only account), or there is no record for it.
     * It never creates a record and ignores the `createOnSignIn` setting.
     *
     * @param  array<string, mixed>  $credentials
     * @return (Model&Authenticatable&CanResetPasswordContract)|null
     *
     * @throws UnexpectedValueException
     */
    public function getUser(#[SensitiveParameter] array $credentials)
    {
        return $this->find($credentials)[1] ?? null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{0: UserRecord, 1: Model&Authenticatable&CanResetPasswordContract}|null
     *
     * @throws UnexpectedValueException
     */
    protected function find(#[SensitiveParameter] array $credentials): ?array
    {
        $email = $credentials['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $firebaseUser = app(FindFirebaseUserByEmail::class)->handle($email);

        if (! $firebaseUser instanceof UserRecord
            || $firebaseUser->disabled
            || ! app(DetectPasswordProvider::class)->handle($firebaseUser)) {
            return null;
        }

        $user = $this->findLocalRecord($firebaseUser);

        if ($user === null) {
            return null;
        }

        if (! $user instanceof CanResetPasswordContract) {
            throw new UnexpectedValueException('User must implement CanResetPassword interface.');
        }

        return [$firebaseUser, $user];
    }

    /**
     * Returns the record linked to the Firebase user, or else the record with the same email
     * that the link rules allow. A reset link proves that the person owns the email,
     * so an unverified Firebase email is enough here. Only saved records come back.
     */
    protected function findLocalRecord(UserRecord $firebaseUser): (Model & Authenticatable) | null
    {
        $instance = new $this->model;

        $identity = FirebaseIdentity::query()
            ->forFirebaseUid($firebaseUser->uid, $instance->getMorphClass())
            ->first();

        if ($identity !== null) {
            return $this->linkedRecord($instance, $identity);
        }

        if ($firebaseUser->email === null) {
            return null;
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $instance->newQuery()->where('email', $firebaseUser->email)->first();

        if ($user === null) {
            return null;
        }

        try {
            app(EnsureFirebaseIdentityLinkable::class)->handle($user, $firebaseUser);
        } catch (FirebaseIdentityNotLinkable) {
            return null;
        }

        return $user;
    }

    /**
     * A link without a visible record (for example a soft deleted record) gives no record.
     */
    protected function linkedRecord(Model & Authenticatable $instance, FirebaseIdentity $identity): (Model & Authenticatable) | null
    {
        /** @var (Model&Authenticatable)|null $user */
        $user = $instance->newQuery()->whereKey($identity->authenticatable_id)->first();

        $user?->setRelation('firebaseIdentity', $identity);

        return $user;
    }

    /**
     * The email is marked as verified first. The password change then returns the Firebase user
     * with both changes, and with the new `tokensValidAfterTime`. The link copies that state,
     * so `email_verified_at` is set and other sessions of the record log out.
     */
    protected function resetInFirebase(Model & Authenticatable $user, UserRecord $firebaseUser, #[SensitiveParameter] string $password): void
    {
        app(SetFirebaseEmailVerified::class)->handle($firebaseUser->uid, true);

        $changed = app(ChangeFirebasePassword::class)->handle($firebaseUser->uid, $password);

        app(LinkFirebaseIdentity::class)->handle($user, $changed);
    }
}
