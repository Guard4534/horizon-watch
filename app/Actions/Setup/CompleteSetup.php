<?php

namespace App\Actions\Setup;

use App\Actions\Teams\CreateTeam;
use App\Data\Auth\SetupData;
use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompleteSetup
{
    private const LOCK_KEY = 4815162342;

    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Create the first administrator together with their organization.
     *
     * @throws SetupAlreadyCompleted
     */
    public function handle(SetupData $data): User
    {
        return DB::transaction(function () use ($data) {
            // An empty users table has no row SELECT ... FOR UPDATE could lock,
            // so two concurrent setups are serialised on an advisory lock instead.
            DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            if (User::query()->exists()) {
                throw new SetupAlreadyCompleted;
            }

            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'email_verified_at' => now(),
            ]);

            $this->createTeam->handle($user, $data->organization);

            return $user;
        });
    }
}
