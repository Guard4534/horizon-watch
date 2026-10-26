<?php

namespace App\Rules;

use App\Alerts\QuietHours;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class QuietHoursLeaveTheDayOpen implements ValidationRule
{
    public function __construct(private readonly mixed $from) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->isTime($this->from) || ! $this->isTime($value)) {
            return;
        }

        if ($this->from === $value || QuietHours::coversEveryTick($this->from, $value)) {
            $fail(__('The quiet hours cannot cover the whole day: leave at least a quarter of an hour outside the window.'));
        }
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isTime(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $time = DateTimeImmutable::createFromFormat('!H:i', $value);

        return $time !== false && $time->format('H:i') === $value;
    }
}
