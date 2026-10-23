<?php

namespace App\Configuration;

final readonly class ConfigurationDocument
{
    /**
     * @param  array<array-key, mixed>  $data  the document, upgraded to the current format version
     */
    public function __construct(
        public array $data,
        public int $sourceVersion,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function applications(): array
    {
        return $this->list('applications');
    }

    /**
     * @return array<int, mixed>
     */
    public function alertRules(): array
    {
        return $this->list('alert_rules');
    }

    public function organization(): ?string
    {
        return $this->text('organization');
    }

    public function exportedAt(): ?string
    {
        return $this->text('exported_at');
    }

    /**
     * @return array{data: array<array-key, mixed>, sourceVersion: int}
     */
    public function toArray(): array
    {
        return ['data' => $this->data, 'sourceVersion' => $this->sourceVersion];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $data = $stored['data'] ?? null;
        $version = $stored['sourceVersion'] ?? null;

        return is_array($data) && is_int($version) ? new self($data, $version) : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function list(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function text(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
