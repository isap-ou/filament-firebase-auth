<?php

namespace Isapp\FilamentFirebaseAuth\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The Firebase sign-in providers that can get a button on the login page.
 *
 * The value is the Firebase provider ID. Custom `oidc.*` and `saml.*` providers are not in v1.
 */
enum SocialProvider: string implements HasLabel
{
    case Google = 'google.com';
    case Apple = 'apple.com';
    case Microsoft = 'microsoft.com';
    case GitHub = 'github.com';
    case Facebook = 'facebook.com';
    case Twitter = 'twitter.com';
    case Yahoo = 'yahoo.com';

    public function getLabel(): string
    {
        return (string) __('filament-firebase-auth::social.providers.' . $this->key());
    }

    /**
     * The path of the brand SVG in `resources/svg/`.
     */
    public function getIconPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/svg/' . $this->key() . '.svg';
    }

    /**
     * The provider class of the Firebase JS SDK. The JS creates `OAuthProvider` with the provider ID.
     */
    public function getSdkClass(): string
    {
        return match ($this) {
            self::Google => 'GoogleAuthProvider',
            self::Facebook => 'FacebookAuthProvider',
            self::GitHub => 'GithubAuthProvider',
            self::Twitter => 'TwitterAuthProvider',
            self::Apple, self::Microsoft, self::Yahoo => 'OAuthProvider',
        };
    }

    private function key(): string
    {
        return strtolower($this->name);
    }
}
