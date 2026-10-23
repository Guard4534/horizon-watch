<?php

namespace App\Configuration;

final readonly class ImportSummary
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public int $passwordNeeded,
    ) {}

    public function message(): string
    {
        $message = __('Configuration imported: :created created, :updated updated, :skipped left as they were.', [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
        ]);

        if ($this->passwordNeeded > 0) {
            $message .= ' '.trans_choice(
                ':count environment waits for its basic-auth password.|:count environments wait for their basic-auth password.',
                $this->passwordNeeded,
                ['count' => $this->passwordNeeded],
            );
        }

        return $message;
    }
}
