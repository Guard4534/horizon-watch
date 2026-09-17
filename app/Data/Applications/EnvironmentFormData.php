<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use App\Rules\UrlWithoutCredentials;
use App\Rules\UrlWithoutQueryOrFragment;
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
        // Off means the scheduler leaves the environment alone: its last
        // reading stays on the pages, nothing contacts it. A configuration
        // change like any other, so it needs only "manage applications".
        public bool $pollingEnabled = true,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', ...self::uniqueNameRules()],
            'color' => ['required', Rule::enum(EnvironmentColor::class)],
            'horizonUrl' => self::horizonUrlRules(),
            'basicAuthUser' => self::basicAuthUserRules(self::key($context, 'basicAuthPassword')),
            'basicAuthPassword' => ['nullable', 'string', ...self::passwordRequiredWithUsernameRules($context)],
            'pollIntervalSeconds' => ['integer', 'between:5,300'],
            'pollingEnabled' => ['boolean'],
        ];
    }

    /**
     * Shared with TestConnectionData, so a URL the form would refuse is
     * never probed either. A URL carrying a user or a password is refused
     * (UrlWithoutCredentials): the URL is shown to every watcher, the
     * basic-auth fields are not. A query string or a fragment is refused
     * too (UrlWithoutQueryOrFragment): the API path is appended to the URL.
     *
     * @return array<int, string|UrlWithoutCredentials|UrlWithoutQueryOrFragment>
     */
    public static function horizonUrlRules(): array
    {
        return ['required', 'url:http,https', 'max:255', new UrlWithoutCredentials, new UrlWithoutQueryOrFragment];
    }

    /**
     * Basic auth needs both halves: a password on its own cannot
     * authenticate anything, and since it is never read back out it could
     * not be paired with a username later either. Rejecting it here is what
     * lets AddEnvironment and UpdateEnvironment treat "no username" as "no
     * credential at all".
     *
     * required_with names an absolute key, so nested use has to qualify it:
     * the wizard validates this Data at "environments.N", where a bare
     * "basicAuthPassword" would look for a top-level field that never exists
     * and the rule would quietly never fire. Callers pass the absolute key.
     *
     * The shape rule is what keeps a username of three spaces out of the
     * column: TrimStrings plus ConvertEmptyStringsToNull already turn that
     * into null over HTTP, but this does not want to depend on two global
     * middlewares staying in the stack. A colon is out for a harder reason —
     * basic auth transmits "user:password", so a username containing one
     * cannot be encoded at all (RFC 7617).
     *
     * Shared with TestConnectionData.
     *
     * @return array<int, string>
     */
    public static function basicAuthUserRules(string $passwordKey): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            'regex:/^[^\s:]+$/',
            'required_with:'.$passwordKey,
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
     * Requiring the password whenever a username is given, but only where
     * "no password" can mean nothing else. On the edit page an empty
     * password field means "keep the one on file" (hasNewPassword()), so
     * the rule would make every save of an existing credential impossible;
     * on the two create paths there is nothing to keep, and a username
     * saved alone would be a credential that cannot authenticate.
     *
     * @return array<int, string>
     */
    private static function passwordRequiredWithUsernameRules(ValidationContext $context): array
    {
        return self::environmentBeingUpdated() === null
            ? ['required_with:'.self::key($context, 'basicAuthUser')]
            : [];
    }

    /**
     * The per-application uniqueness of the name, which the database also
     * enforces (environments' unique index on application_id + name):
     * without it a duplicate reaches the insert and the browser gets a 500
     * instead of a message on the field.
     *
     * The parent application is never in the payload, it comes from the
     * route (see parentApplication()), and the wizard has no application to
     * be unique against yet: there, ApplicationWizardData's "distinct" is
     * what catches two identical rows.
     *
     * @return array<int, Unique>
     */
    private static function uniqueNameRules(): array
    {
        $application = self::parentApplication();

        if ($application === null) {
            return [];
        }

        $environment = self::environmentBeingUpdated();
        $rule = Rule::unique('environments', 'name')->where('application_id', $application->id);

        return [$environment === null ? $rule : $rule->ignore($environment)];
    }

    /**
     * The environment this request is updating, or null on either create
     * path. Also null outside an HTTP request — see parentApplication() for
     * what that means.
     */
    private static function environmentBeingUpdated(): ?Environment
    {
        $environment = request()->route()?->parameter('environment');

        return $environment instanceof Environment ? $environment : null;
    }

    /**
     * The application these rules validate against. It is never in the
     * payload: it is the "{application}" route segment when adding an
     * environment and the edited environment's own application when
     * updating, both already resolved to models by scoped route model
     * binding (see routes/monitoring.php) — the same way InviteMemberData
     * reads "{current_team}".
     *
     * There are two ways to get null. The wizard posts to
     * applications.store, where the application does not exist yet. And
     * outside an HTTP request — a console command or a queued job calling
     * EnvironmentFormData::validate() — there is no route at all, so the
     * uniqueness rule disappears and a duplicate name would reach the
     * insert and raise a QueryException on the unique index instead of a
     * validation error. Nothing in the panel writes environments that way
     * today (every write goes through the two controllers); a writer that
     * does will have to check uniqueness itself, or be given the
     * application explicitly.
     */
    private static function parentApplication(): ?Application
    {
        $environment = self::environmentBeingUpdated();

        if ($environment !== null) {
            return $environment->application;
        }

        $application = request()->route()?->parameter('application');

        return $application instanceof Application ? $application : null;
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
     * Whether this submission changes the basic-auth credentials of the
     * given environment, as opposed to only its name, color, URL or poll
     * interval. Gates the separate "manage credentials" permission
     * (EnvironmentPolicy::manageCredentials()), distinct from "manage
     * applications" even though today's role matrix grants both to the same
     * roles.
     *
     * The comparison needs the stored row, and cannot be made on the
     * payload alone, in both directions. The edit form prefills the
     * username, so an untouched save resends it: a payload-only check would
     * demand the credentials permission for every edit of every environment
     * that has a username, and a role holding "manage applications" without
     * it could not even change a poll interval. And a *removal* does not
     * show up in the payload at all, because ConvertEmptyStringsToNull
     * turns the cleared username field into null, which is byte-for-byte
     * what "this form never had a username" sends.
     *
     * Pass a transient Environment when creating one: with no attributes
     * set, any username or password in the payload reads as a change, which
     * is what it is.
     */
    public function changesCredentialsOf(Environment $environment): bool
    {
        return $this->basicAuthUser !== $environment->basic_auth_user
            || $this->hasNewPassword();
    }
}
