const SUGGESTIONS = new Map<string, App.Enums.EnvironmentType>([
    ['production', 'production'],
    ['prod', 'production'],
    ['prd', 'production'],
    ['live', 'production'],
    ['preprod', 'preprod'],
    ['uat', 'preprod'],
    ['staging', 'staging'],
    ['stage', 'staging'],
    ['develop', 'development'],
    ['development', 'development'],
    ['dev', 'development'],
    ['test', 'testing'],
    ['testing', 'testing'],
    ['qa', 'testing'],
    ['demo', 'demo'],
]);

export function suggestType(name: string): App.Enums.EnvironmentType | null {
    const normalized = name.trim().toLowerCase();

    if (/^pre-production(?:[-._\s]|$)/.test(normalized)) {
        return 'preprod';
    }

    return SUGGESTIONS.get(normalized.split(/[-._\s]/, 1)[0]) ?? null;
}

export function typeForName(name: string): App.Enums.EnvironmentType {
    return suggestType(name) ?? 'staging';
}

export function isProductionType(
    type: App.Enums.EnvironmentType | null | undefined,
): boolean {
    return type === 'production';
}

function parsed(url: string): URL | null {
    try {
        const candidate = new URL(url.trim());

        return /^https?:$/.test(candidate.protocol) && candidate.hostname !== ''
            ? candidate
            : null;
    } catch {
        return null;
    }
}

export function hostOf(url: string): string | null {
    return parsed(url)?.hostname ?? null;
}

export function domainOf(
    environments: ReadonlyArray<{
        type: App.Enums.EnvironmentType;
        horizonUrl: string;
    }>,
): string | null {
    const primary =
        environments.find((environment) =>
            isProductionType(environment.type),
        ) ?? environments[0];

    return primary === undefined ? null : hostOf(primary.horizonUrl);
}

export function suggestHorizonUrl(
    baseUrl: string,
    baseName: string,
    name: string,
    type: App.Enums.EnvironmentType,
): string {
    const base = parsed(baseUrl);
    const environment = name.trim().toLowerCase();

    if (base === null || environment === '') {
        return '';
    }

    const label = baseName.trim().toLowerCase();
    const baseHost =
        label !== '' && base.hostname.startsWith(`${label}.`)
            ? base.hostname.slice(label.length + 1)
            : base.hostname;
    const host = isProductionType(type)
        ? baseHost
        : `${environment}.${baseHost}`;
    const port = base.port === '' ? '' : `:${base.port}`;

    return `${base.protocol}//${host}${port}${dashboardPath(base.pathname)}`;
}

/**
 * The path HorizonUrl::normalize() would store: no trailing slash, and no trailing `/api` or
 * `/api/<Horizon endpoint>…` part when at least one segment comes before it (a Horizon served at
 * `/api`, or under a path that only passes through an `api` segment, keeps it).
 */
function dashboardPath(pathname: string): string {
    return pathname
        .replace(/\/+$/, '')
        .replace(
            /^(\/.*?[^/])\/api(?:\/(?:stats|workload|masters|monitoring|metrics|jobs|batches)(?:\/.*)?)?$/i,
            '$1',
        );
}

/**
 * What a field prefilled with a suggestion holds: the suggestion, until the user edits the field
 * by hand. The wizard's further URL rows, the Add environment URL and the type that follows the
 * name all go through this one rule.
 */
export function followSuggestion<T>(
    current: T,
    suggestion: T,
    edited: boolean,
): T {
    return edited ? current : suggestion;
}

/**
 * Whether what the user just typed leaves the suggestion. Typing the suggestion back makes the
 * field follow it again.
 */
export function leavesSuggestion(typed: string, suggestion: string): boolean {
    return typed !== suggestion;
}

export type BasicAuthCredentials = {
    basicAuthUser: string | null;
    basicAuthPassword: string | null;
};

/**
 * The credentials an environment form holds after its basic-auth switch moves. Off sends no
 * credentials, so saving removes the stored ones; on again brings back the username typed before,
 * never the password.
 */
export function switchBasicAuth(
    on: boolean,
    current: BasicAuthCredentials,
    stashedUser: string | null,
): BasicAuthCredentials & { stashedUser: string | null } {
    return on
        ? { basicAuthUser: stashedUser, basicAuthPassword: null, stashedUser }
        : {
              basicAuthUser: null,
              basicAuthPassword: null,
              stashedUser: current.basicAuthUser,
          };
}
