<?php

namespace App\Configuration;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * The file an admin uploaded and is looking at, kept between the preview and the import. Its id
 * ties the confirmation to the preview the admin saw: a later upload replaces it.
 */
final readonly class PendingImport
{
    private const int MINUTES = 30;

    public function __construct(private Repository $cache) {}

    public function put(Team $team, User $user, ConfigurationDocument $document, string $fileName): void
    {
        $this->cache->put(
            $this->key($team, $user),
            ['id' => (string) Str::uuid(), 'document' => $document->toArray(), 'fileName' => $fileName],
            now()->addMinutes(self::MINUTES),
        );
    }

    /**
     * @return array{id: string, document: ConfigurationDocument, fileName: string}|null
     */
    public function get(Team $team, User $user): ?array
    {
        $stored = $this->cache->get($this->key($team, $user));

        if (! is_array($stored)
            || ! is_string($stored['id'] ?? null)
            || ! is_array($stored['document'] ?? null)
            || ! is_string($stored['fileName'] ?? null)) {
            return null;
        }

        $document = ConfigurationDocument::fromArray($stored['document']);

        return $document === null ? null : ['id' => $stored['id'], 'document' => $document, 'fileName' => $stored['fileName']];
    }

    public function forget(Team $team, User $user): void
    {
        $this->cache->forget($this->key($team, $user));
    }

    private function key(Team $team, User $user): string
    {
        return "configuration-import:{$team->id}:{$user->id}";
    }
}
