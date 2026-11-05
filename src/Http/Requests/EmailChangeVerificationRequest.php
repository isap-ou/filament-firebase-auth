<?php

namespace Isapp\FilamentFirebaseAuth\Http\Requests;

use Filament\Auth\Http\Requests\EmailChangeVerificationRequest as FilamentEmailChangeVerificationRequest;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\RenewCurrentSession;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\ChangeFirebaseEmail;
use Isapp\FilamentFirebaseAuth\Concerns\HasFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Exception\FirebaseException;

/**
 * Changes the Firebase email when the person opens the link of Filament's email-change verification.
 *
 * The service provider binds Filament's request class to this class, so Filament's
 * `EmailChangeVerificationController` receives it. A record without `HasFirebaseIdentity`
 * gets Filament's behaviour.
 */
class EmailChangeVerificationRequest extends FilamentEmailChangeVerificationRequest
{
    /**
     * Firebase changes first, then Filament changes the local record.
     *
     * Filament's `fulfill()` marks the email as verified, and the trait sends that flag to Firebase.
     * If Firebase then refused the new email, Firebase would keep the old email with a verified flag
     * that nobody proved. So Firebase gets the new email and the flag in one call before Filament runs.
     * When Firebase refuses, nothing changes and the method returns false.
     *
     * Two clicks on the same link at the same moment can both change Firebase while only one
     * changes the record. Both clicks prove the new email. The next sign-in or session check copies
     * the Firebase email to the record.
     */
    public function fulfill(): bool
    {
        $user = $this->user();

        if (! $user instanceof Model || ! in_array(HasFirebaseIdentity::class, class_uses_recursive($user), true)) {
            return parent::fulfill();
        }

        $identity = $user->getRelationValue('firebaseIdentity');

        if (! $identity instanceof FirebaseIdentity) {
            return false;
        }

        $newEmail = (string) decrypt($this->route('email'));

        // Filament refuses an email that another local record uses. Firebase must not change then.
        $isEmailTaken = $user::query()
            ->whereKeyNot($user->getKey())
            ->where('email', $newEmail)
            ->useWritePdo()
            ->exists();

        if ($isEmailTaken) {
            return false;
        }

        try {
            $firebaseUser = app(ChangeFirebaseEmail::class)->handle($identity->firebase_uid, $newEmail, verified: true);

            $fulfilled = $user->getConnection()->transaction(function () use ($user, $firebaseUser): bool {
                if (! parent::fulfill()) {
                    return false;
                }

                // Firebase revokes the tokens of a user whose email changes. The link stores the new time.
                app(SyncsLocalUsers::class)->handle($user, $firebaseUser);

                return true;
            });
        } catch (FirebaseException $exception) {
            logger()->warning('Firebase refused the email change.', [
                'uid' => $identity->firebase_uid,
                'exception' => $exception::class,
            ]);

            return false;
        }

        if ($fulfilled) {
            app(RenewCurrentSession::class)->handle($user);
        }

        return $fulfilled;
    }
}
