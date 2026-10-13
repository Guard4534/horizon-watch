<?php

namespace App\Console\Commands;

use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Signature('secrets:reencrypt')]
#[Description('Encrypt every stored secret again with the current application key, so the keys in APP_PREVIOUS_KEYS can be removed')]
class ReencryptSecrets extends Command
{
    /**
     * @var array<class-string<Model>, list<string>>
     */
    private const array SECRETS = [
        Environment::class => ['basic_auth_password'],
        NotificationSetting::class => ['webhook_secret'],
        User::class => ['two_factor_secret', 'two_factor_recovery_codes'],
    ];

    public function handle(StringEncrypter $encrypter): int
    {
        $reencrypted = 0;
        $unreadable = 0;

        foreach (self::SECRETS as $class => $columns) {
            $model = new $class;

            foreach ($columns as $column) {
                $rows = $model->newQuery()->toBase()
                    ->whereNotNull($column)
                    ->select([$model->getKeyName(), $column])
                    ->lazyById(100, $model->getKeyName());

                foreach ($rows as $row) {
                    $key = $row->{$model->getKeyName()};
                    $stored = (string) $row->{$column};

                    try {
                        $plain = $encrypter->decryptString($stored);
                    } catch (DecryptException) {
                        $this->warn("{$model->getTable()}.{$column} of {$key} cannot be decrypted with any configured key.");
                        $unreadable++;

                        continue;
                    }

                    $reencrypted += $model->newQuery()->toBase()
                        ->where($model->getKeyName(), $key)
                        ->where($column, $stored)
                        ->update([$column => $encrypter->encryptString($plain)]);
                }
            }
        }

        $this->info("Encrypted {$reencrypted} ".Str::plural('secret', $reencrypted).' again with the current key.');

        if ($unreadable > 0) {
            $this->error("{$unreadable} ".Str::plural('secret', $unreadable).' could not be decrypted: set '.($unreadable === 1 ? 'it' : 'them').' again before removing a previous key.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
