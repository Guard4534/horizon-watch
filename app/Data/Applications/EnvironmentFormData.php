<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class EnvironmentFormData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public ?string $basicAuthUser = null,
        // Write-only: read by AddEnvironment/UpdateEnvironment to fill the
        // encrypted column, never read back out of the database into a Data
        // instance. EnvironmentSummaryData is what the edit page renders,
        // and it has no such property at all — see its docblock.
        public ?string $basicAuthPassword = null,
        public int $pollIntervalSeconds = 15,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', ...self::uniqueNameRules()],
            'color' => ['required', Rule::enum(EnvironmentColor::class)],
            'horizonUrl' => ['required', 'url:http,https', 'max:255'],
            // Basic auth needs both halves: a password on its own cannot
            // authenticate anything, and since it is never read back out it
            // could not be paired with a username later either. Rejecting it
            // here is what lets AddEnvironment and UpdateEnvironment treat
            // "no username" as "no credential at all".
            //
            // required_with names an absolute key, so nested use has to
            // qualify it: the wizard validates this Data at
            // "environments.N", where a bare "basicAuthPassword" would look
            // for a top-level field that never exists and the rule would
            // quietly never fire.
            'basicAuthUser' => ['nullable', 'string', 'max:255', 'required_with:'.self::key($context, 'basicAuthPassword')],
            'basicAuthPassword' => ['nullable', 'string'],
            'pollIntervalSeconds' => ['integer', 'between:5,300'],
        ];
    }

    /**
     * The absolute payload key of one of this Data's own fields: the field
     * name at the root (the two environment pages), prefixed with the
     * concrete path when nested (the wizard's "environments.0").
     */
    private static function key(ValidationContext $context, string $field): string
    {
        return $context->path->isRoot()
            ? $field
            : $context->path->property($field)->get();
    }

    /**
     * The per-application uniqueness of the name, which the database also
     * enforces (environments' unique index on application_id + name):
     * without it a duplicate reaches the insert and the browser gets a 500
     * instead of a message on the field.
     *
     * The parent application is never in the payload. It is the
     * "{application}" route segment when adding an environment, and the
     * edited environment's own application when updating — both already
     * resolved to models by scoped route model binding (see
     * routes/monitoring.php), the same way InviteMemberData reads
     * "{current_team}". The wizard is the third case: it posts to
     * applications.store, where the application does not exist yet, so
     * there is nothing to be unique against and ApplicationWizardData's
     * "distinct" is what catches two identical rows.
     *
     * @return array<int, Unique>
     */
    private static function uniqueNameRules(): array
    {
        $route = request()->route();
        $environment = $route?->parameter('environment');
        $application = $environment instanceof Environment
            ? $environment->application
            : $route?->parameter('application');

        if (! $application instanceof Application) {
            return [];
        }

        $rule = Rule::unique('environments', 'name')->where('application_id', $application->id);

        return [$environment instanceof Environment ? $rule->ignore($environment) : $rule];
    }

    /**
     * Whether the submission actually included a new password. A blank or
     * whitespace-only string means "no change", the same as a missing one:
     * an untouched password input commonly submits "" rather than omitting
     * the field, and that must not wipe the credential already on file.
     */
    public function hasNewPassword(): bool
    {
        return filled($this->basicAuthPassword);
    }

    /**
     * Whether this submission is trying to set or replace the basic-auth
     * credentials, as opposed to only the name, color, URL or poll
     * interval. Gates the separate "manage credentials" permission
     * (EnvironmentPolicy::manageCredentials()), distinct from "manage
     * applications" even though today's role matrix grants both to the
     * same roles.
     *
     * This sees the payload only, and *removing* a credential does not show
     * up in it: ConvertEmptyStringsToNull turns a cleared username field
     * into null, which is byte-for-byte what "no username was sent" looks
     * like. Telling those apart needs the stored row, so the controller
     * checks for it separately (EnvironmentController::clearsCredentials()).
     */
    public function touchesCredentials(): bool
    {
        return $this->basicAuthUser !== null || $this->hasNewPassword();
    }
}
