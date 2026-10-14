import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: App.Enums.Locale;
            currentTeam: App.Data.Teams.UserTeamData | null;
            teams: App.Data.Teams.UserTeamData[];
            canCreateOrganizations: boolean;
            openAlertCount: number | null;
            canManageApplications: boolean;
            visibilityRestricted: boolean;
            organizationHasEnvironments: boolean;
            version: string | null;
            mailConfigured: boolean | null;
            panelHealth: App.Data.Monitoring.PanelHealthData | null;
        };
    }
}
