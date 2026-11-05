<?php

namespace Isapp\FilamentFirebaseAuth\Actions\LocalUsers;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

/**
 * Creates the local record with the Firebase display name and email.
 */
class CreateLocalUser implements CreatesLocalUsers
{
    public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable
    {
        $email = (string) $firebaseUser->email;

        $user = new $model([
            'name' => $firebaseUser->displayName ?? Str::before($email, '@'),
            'email' => $email,
        ]);

        try {
            $user->save();
        } catch (QueryException $exception) {
            throw $this->explainPasswordColumn($user, $exception);
        }

        return $user;
    }

    /**
     * Turns a NOT NULL error on the password column into a message that names the README section.
     * Other database errors stay as they are.
     */
    protected function explainPasswordColumn(Model & Authenticatable $user, QueryException $exception): QueryException | LogicException
    {
        $column = $user->getAuthPasswordName();

        // The driver message has no SQL, so it names only the failed column.
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
        $namesColumn = preg_match('/(?<!\w)' . preg_quote($column, '/') . '(?!\w)/i', $message) === 1;
        $isNullError = Str::contains($message, ['null', 'default value'], ignoreCase: true);

        if (! $namesColumn || ! $isNullError) {
            return $exception;
        }

        return new LogicException(sprintf(
            'The "%s" table has a NOT NULL "%s" column. The plugin never stores a password. '
            . 'Make the column nullable or remove it. See "Prepare your users table" in the README.',
            $user->getTable(),
            $column,
        ), previous: $exception);
    }
}
