<?php

namespace App\Data\Alerts;

use App\Rules\WebhookUrl;
use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class NotificationSettingsInputData extends Data
{
    public const int MAX_RECIPIENTS = 20;

    public const array REPEAT_MINUTES = [15, 30, 60];

    public function __construct(
        /** @var array<int, string> */
        public array $recipients,
        public ?string $webhookUrl,
        public ?string $quietFrom,
        public ?string $quietTo,
        public string $timezone,
        public ?int $repeatMinutes,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'recipients' => ['present', 'array', 'list', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'max:255', 'email:rfc'],
            'webhookUrl' => ['present', 'nullable', 'string', 'max:2048', 'url:http,https', new WebhookUrl],
            'quietFrom' => ['present', 'nullable', 'date_format:H:i', 'required_with:'.self::key($context, 'quietTo')],
            'quietTo' => ['present', 'nullable', 'date_format:H:i', 'required_with:'.self::key($context, 'quietFrom')],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'repeatMinutes' => ['present', 'nullable', 'integer', Rule::in(self::REPEAT_MINUTES)],
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueRecipients(): array
    {
        return array_values(array_unique(array_map(
            fn (string $recipient): string => Str::lower(trim($recipient)),
            $this->recipients,
        )));
    }

    private static function key(ValidationContext $context, string $field): string
    {
        return $context->path->isRoot()
            ? $field
            : $context->path->property($field)->get();
    }
}
