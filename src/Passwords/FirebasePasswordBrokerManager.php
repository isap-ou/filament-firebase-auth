<?php

namespace Isapp\FilamentFirebaseAuth\Passwords;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds the `firebase` password broker. Every other broker comes from Laravel's manager.
 *
 * Laravel's manager has no hook for a custom broker driver, so the plugin replaces the
 * `auth.password` binding with this subclass.
 */
class FirebasePasswordBrokerManager extends PasswordBrokerManager
{
    /**
     * @param  string  $name
     * @return PasswordBrokerContract
     *
     * @throws InvalidArgumentException
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        if (! is_array($config) || ($config['driver'] ?? null) !== 'firebase') {
            return parent::resolve($name);
        }

        // The same arguments as Laravel's broker (`PasswordBrokerManager::resolve()`), plus the model.
        return new FirebasePasswordBroker(
            $this->createTokenRepository($config),
            $this->app['auth']->createUserProvider($config['provider'] ?? null),
            $this->model($name, $config['provider'] ?? null),
            $this->app['events'] ?? null,
            timeboxDuration: $this->app['config']->get('auth.timebox_duration', 200000),
        );
    }

    /**
     * Reads the model from the config of the user provider, the way Laravel's `eloquent` driver does.
     * Without a provider name, Laravel uses the default user provider.
     *
     * @return class-string<Model&Authenticatable>
     *
     * @throws InvalidArgumentException
     */
    protected function model(string $name, ?string $provider): string
    {
        $provider ??= $this->app['config']['auth.defaults.provider'];
        $model = $this->app['config']["auth.providers.{$provider}.model"];

        if (! is_string($model) || ! is_subclass_of($model, Model::class) || ! is_subclass_of($model, Authenticatable::class)) {
            throw new InvalidArgumentException("Password resetter [{$name}] needs a user provider with an Eloquent model.");
        }

        return $model;
    }
}
