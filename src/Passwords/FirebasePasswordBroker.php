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
use Isapp\FilamentFirebaseAuth\Actions\Firebase\RevokeFirebaseTokens;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\SetFirebaseEmailVerified;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\UnlinkFirebaseProviders;
use Isapp\FilamentFirebaseAuth\Actions\Identity\EnsureFirebaseIdentityLinkable;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use Psr\Log\LoggerInterface;
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
     * Filament's page shows an error for `INVALID_USER` and `RESET_THROTTLED`, and a "sent" message
     * for `RESET_LINK_SENT`. Laravel returns `RESET_THROTTLED` only for an email that has a record.
     * So both statuses become `RESET_LINK_SENT`, and nobody can find out which emails exist.
     * A throttled request still sends no second email.
     *
     * When Firebase fails, the broker logs the exception class and the page shows the same message.
     *
     * @param  array<string, mixed>  $credentials
     * @return string
     */
    public function sendResetLink(#[SensitiveParameter] array $credentials, ?Closure $callback = null)
    {
        try {
            $status = parent::sendResetLink($credentials, $callback);
        } catch (FirebaseException $exception) {
            $this->logFirebaseFailure($exception);

            return static::RESET_LINK_SENT;
        }

        return in_array($status, [static::INVALID_USER, static::RESET_THROTTLED], true) ? static::RESET_LINK_SENT : $status;
    }

    /**
     * Checks the token, then sets the new password in Firebase and marks the email as verified,
     * because the reset proves that the person owns the email. Then it links the record and copies
     * the Firebase state to the link. Then it calls the callback and deletes the token.
     *
     * The token is deleted last. When a Firebase call fails, the broker logs the exception class,
     * returns `INVALID_USER` (Filament's normal failure message), and the same link works again.
     *
     * @param  array<string, mixed>  $credentials
     * @return string
     */
    public function reset(#[SensitiveParameter] array $credentials, Closure $callback)
    {
        try {
            return $this->resetInTimebox($credentials, $callback);
        } catch (FirebaseException $exception) {
            $this->logFirebaseFailure($exception);

            return static::INVALID_USER;
        }
    }

    /**
     * The flow of Laravel's `reset()` in the same timebox, with the Firebase steps before the callback.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function resetInTimebox(#[SensitiveParameter] array $credentials, Closure $callback): string
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
     * An unverified Firebase email may belong to an account that another person created with
     * this email before the owner did (account pre-hijacking). That person can know its password
     * or can have linked a social provider to it. So, before the reset verifies the email and links
     * the record, the broker removes every provider except `password` and revokes the refresh tokens.
     * A verified account keeps its providers and tokens.
     *
     * The email is marked as verified next. The password change then returns the Firebase user
     * with all changes, and with the new `tokensValidAfterTime`. The link copies that state,
     * so `email_verified_at` is set and other sessions of the record log out.
     */
    protected function resetInFirebase(Model & Authenticatable $user, UserRecord $firebaseUser, #[SensitiveParameter] string $password): void
    {
        if (! $firebaseUser->emailVerified) {
            app(UnlinkFirebaseProviders::class)->handle($firebaseUser->uid, ['password']);
            app(RevokeFirebaseTokens::class)->handle($firebaseUser->uid);
        }

        app(SetFirebaseEmailVerified::class)->handle($firebaseUser->uid, true);

        $changed = app(ChangeFirebasePassword::class)->handle($firebaseUser->uid, $password);

        app(LinkFirebaseIdentity::class)->handle($user, $changed);
    }

    /**
     * Logs the exception class only. A Firebase message can contain the email or the request URL
     * with the API key.
     */
    protected function logFirebaseFailure(FirebaseException $exception): void
    {
        app(LoggerInterface::class)->error('Firebase failed during a password reset.', [
            'exception' => $exception::class,
        ]);
    }
}
