<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\SignIn\FailedToSignIn;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Checks an email and a password with Firebase.
 *
 * The container keeps one instance per request. Filament checks the same credentials twice,
 * and this instance asks Firebase only once.
 */
class CheckFirebasePassword
{
    /**
     * Results by sha256(email|password). Null means that Firebase rejected the credentials.
     *
     * @var array<string, UserRecord|null>
     */
    protected array $results = [];

    public function __construct(
        protected Auth $auth,
        protected FindFirebaseUser $findFirebaseUser,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Returns the Firebase user, or null when Firebase rejects the credentials.
     *
     * Firebase also rejects a disabled account. A failure without a Firebase answer
     * (a timeout or a Firebase server error) goes to the caller, so that the caller can log it.
     * Such a failure is not remembered.
     */
    public function handle(string $email, #[SensitiveParameter] string $password): ?UserRecord
    {
        $key = hash('sha256', $email . '|' . $password);

        if (array_key_exists($key, $this->results)) {
            return $this->results[$key];
        }

        try {
            $uid = $this->auth->signInWithEmailAndPassword($email, $password)->firebaseUserId();
        } catch (FailedToSignIn $exception) {
            $status = $exception->response()?->getStatusCode();

            // Firebase rejects credentials with a 4xx status.
            if ($status === null || $status >= 500) {
                throw $exception;
            }

            $this->logRefusal($exception);

            $uid = null;
        }

        return $this->results[$key] = $uid === null ? null : $this->findFirebaseUser->handle($uid);
    }

    /**
     * Firebase also answers with a 4xx status for reasons other than wrong credentials, for example
     * `OPERATION_NOT_ALLOWED` (a project setting) or `TOO_MANY_ATTEMPTS_TRY_LATER` (an account lockout).
     * The user still sees the generic message. The log names the Firebase error code only,
     * never the email or the password.
     */
    protected function logRefusal(FailedToSignIn $exception): void
    {
        // Firebase writes the message as "CODE" or "CODE : description".
        $code = trim(explode(' : ', $exception->getMessage(), 2)[0]);

        $credentialCodes = ['EMAIL_NOT_FOUND', 'INVALID_PASSWORD', 'INVALID_LOGIN_CREDENTIALS', 'USER_DISABLED', 'INVALID_EMAIL', 'MISSING_PASSWORD'];

        if (in_array($code, $credentialCodes, true)) {
            return;
        }

        $this->logger->warning('Firebase refused the password check.', ['code' => $code]);
    }
}
