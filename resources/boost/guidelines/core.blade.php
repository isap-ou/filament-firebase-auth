{{-- Filament Firebase Auth Guidelines for AI Code Assistants --}}
{{-- Source: https://github.com/isap-ou/filament-firebase-auth --}}
{{-- License: MIT | (c) ISAPP and ISAP OÜ --}}

## Filament Firebase Auth

- `isapp/filament-firebase-auth` signs people in to Filament panels with Firebase Authentication. Firebase keeps the password. The local record never stores a password or a password hash.
- The plugin keeps Filament's own login, password reset and email verification pages. It plugs in through the `firebase` user provider driver and the `firebase` password broker driver. The host selects the plugin pages `Pages\Register` and `Pages\EditProfile` explicitly.
- Always activate the `filament-firebase-auth-development` skill when working with Filament panel auth, the `firebase` auth driver, `HasFirebaseIdentity`, or `firebase_identities`.

@verbatim
<code-snippet name="A panel that signs in with Firebase" lang="php">
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;

$panel
    ->authGuard('admin') // the guard's provider uses the `firebase` driver
    ->authPasswordBroker('admins') // the broker uses the `firebase` driver
    ->login()
    ->registration(Register::class)
    ->profile(EditProfile::class)
    ->passwordReset()
    ->plugin(FilamentFirebaseAuthPlugin::make());
</code-snippet>
@endverbatim
