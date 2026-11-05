<?php

namespace Isapp\FilamentFirebaseAuth\MultiFactor;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\App\AppAuthentication as FilamentAppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DetectUserWithoutPassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Kreait\Firebase\Exception\FirebaseException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Filament's app authentication (an authenticator app code) for a Firebase panel.
 * The host selects it with `->multiFactorAuthentication([AppAuthentication::make()])`.
 *
 * The UI is Filament's own. Two things change:
 * - Firebase checks the password of the set-up, disable and recovery code actions.
 *   Filament's `current_password` rule compares a local hash, and the plugin stores none.
 * - A user without the Firebase `password` provider gets no management actions. That user signs
 *   in through a social provider, and the social provider owns the second factor.
 */
class AppAuthentication extends FilamentAppAuthentication
{
    /**
     * @return array<Component | Action | ActionGroup>
     */
    public function getManagementSchemaComponents(): array
    {
        if (app(DetectUserWithoutPassword::class)->handle(Filament::auth()->user())) {
            return [];
        }

        return parent::getManagementSchemaComponents();
    }

    /**
     * Filament's actions, with a Firebase password check in each password field.
     *
     * The actions create their password fields in `make()` and use the same instances in their
     * hooks. So the plugin collects these instances while Filament creates them, and changes
     * their rules after that. Everything else (the UI, the rate limits) stays Filament's.
     *
     * @return array<Action>
     */
    public function getActions(): array
    {
        $inputs = [];

        $actions = TextInput::configureUsing(
            function (TextInput $input) use (&$inputs): void {
                $inputs[] = $input;
            },
            during: fn (): array => parent::getActions(),
        );

        foreach ($inputs as $input) {
            if ($input->getName() === 'password') {
                $this->useFirebasePasswordCheck($input);
            }
        }

        return $actions;
    }

    /**
     * Replaces the `current_password` rule of the field with the Firebase check.
     */
    protected function useFirebasePasswordCheck(TextInput $input): void
    {
        // Filament has no public method that removes a rule. The closure runs in the scope of the field.
        Closure::bind(static function (TextInput $input): void {
            $input->rules = array_values(array_filter(
                $input->rules,
                static fn (array $rule): bool => ! is_string($rule[0]) || ! str_starts_with($rule[0], 'current_password'),
            ));
        }, null, TextInput::class)($input);

        // Filament evaluates the outer closure. Laravel's validator calls the inner closure.
        $input->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
            $message = $this->checkFirebasePassword($value);

            if ($message !== null) {
                $fail($message);
            }
        });
    }

    /**
     * Returns null when Firebase accepts the password of the signed-in user, or else the error message.
     *
     * The password belongs to the Firebase email of the linked user. The local email can differ.
     * Only the password of the linked Firebase user counts.
     */
    protected function checkFirebasePassword(#[SensitiveParameter] mixed $password): ?string
    {
        $user = Filament::auth()->user();
        $uid = $user instanceof Authenticatable && method_exists($user, 'getFirebaseUid') ? $user->getFirebaseUid() : null;

        if (! is_string($uid) || ! is_string($password) || $password === '') {
            return __('validation.current_password');
        }

        try {
            $email = app(FindFirebaseUser::class)->handle($uid)?->email;
            $firebaseUser = $email === null ? null : app(CheckFirebasePassword::class)->handle($email, $password);
        } catch (FirebaseException $exception) {
            // A transport message can contain the request URL with the API key, so the log names the class only.
            app(LoggerInterface::class)->warning('Firebase could not check the password of a multi-factor action.', [
                'uid' => $uid,
                'exception' => $exception::class,
            ]);

            return __('filament-firebase-auth::multi-factor.messages.firebase_unavailable');
        }

        return $firebaseUser?->uid === $uid ? null : __('validation.current_password');
    }
}
