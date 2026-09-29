import { useTeamSlug } from '@/composables/useTeamSlug';
import { show as showEnvironment } from '@/routes/environments';

export function useEnvironmentHref(): (environmentId: string) => string {
    const slug = useTeamSlug();

    return (environmentId: string) =>
        showEnvironment({
            current_team: slug.value,
            environment: environmentId,
        }).url;
}
