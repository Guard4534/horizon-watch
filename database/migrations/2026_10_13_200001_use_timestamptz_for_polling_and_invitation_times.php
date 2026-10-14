<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $columns = [
        'environments' => ['last_polled_at', 'next_poll_at'],
        'team_invitations' => ['expires_at', 'accepted_at', 'revoked_at'],
    ];

    public function up(): void
    {
        $this->convert('timestamptz');
    }

    public function down(): void
    {
        $this->convert('timestamp');
    }

    private function convert(string $type): void
    {
        foreach ($this->columns as $table => $columns) {
            DB::statement(sprintf(
                'alter table %s %s',
                $table,
                implode(', ', array_map(
                    fn (string $column) => sprintf("alter column %s type %s(0) using %s at time zone 'UTC'", $column, $type, $column),
                    $columns,
                )),
            ));
        }
    }
};
