import type { Auth } from '@/types/auth';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: App.Enums.Locale;
            currentTeam: App.Data.Teams.UserTeamData | null;
            teams: App.Data.Teams.UserTeamData[];
            openAlertCount: number | null;
            canManageApplications: boolean;
            visibilityRestricted: boolean;
            organizationHasEnvironments: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
