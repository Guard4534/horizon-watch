import type { InertiaForm } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch, watchEffect } from 'vue';
import type { ConnectionOutcome } from '@/components/monitoring/applications/ConnectionTest.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { store } from '@/routes/applications';

export type WizardRow = {
    key: number;
    auth: boolean;
    urlEdited: boolean;
    outcome: ConnectionOutcome;
    testedSignature: string;
    autoTest: boolean;
};

type ConnectionTester = { run: () => Promise<void> };

type WizardForm = {
    application: App.Data.Applications.ApplicationFormData;
    environments: App.Data.Applications.EnvironmentFormData[];
};

export type ApplicationWizard = {
    step: Ref<number>;
    horizonPath: Ref<string>;
    form: InertiaForm<WizardForm>;
    rows: Ref<WizardRow[]>;
    errors: ComputedRef<Record<string, string | undefined>>;
    unplacedErrors: ComputedRef<string[]>;
    applicationFilled: ComputedRef<boolean>;
    environmentsFilled: ComputedRef<boolean>;
    testing: ComputedRef<boolean>;
    addEnvironment: () => void;
    removeEnvironment: (index: number) => void;
    onUrlInput: (index: number) => void;
    testPayload: (
        environment: App.Data.Applications.EnvironmentFormData,
    ) => App.Data.Applications.TestConnectionData;
    rememberTest: (key: number, instance: unknown) => void;
    testAgain: () => Promise<void>;
    submit: () => void;
};

const SUGGESTED_NAMES: Array<[string, string]> = [
    ['production', 'prod'],
    ['staging', 'staging'],
    ['preprod', 'preprod'],
    ['develop', 'develop'],
    ['demo', 'demo'],
    ['testing', 'testing'],
];

export function useApplicationWizard(
    colors: App.Data.Pages.ApplicationFormPageData['colors'],
): ApplicationWizard {
    const slug = useTeamSlug();

    const step = ref(1);
    const horizonPath = ref('horizon');

    let nextKey = 0;

    const blankEnvironment = (
        index: number,
        taken: string[],
    ): App.Data.Applications.EnvironmentFormData => {
        const available = colors.map((color) => color.value);
        const suggestion = SUGGESTED_NAMES.find(
            ([name, color]) =>
                !taken.includes(name) && available.includes(color),
        );

        return {
            name: suggestion?.[0] ?? '',
            color: (suggestion?.[1] ??
                colors[index % colors.length]
                    .value) as App.Enums.EnvironmentColor,
            horizonUrl: '',
            basicAuthUser: null,
            basicAuthPassword: null,
            pollIntervalSeconds: 15,
            pollingEnabled: true,
        };
    };

    const blankRow = (): WizardRow => ({
        key: nextKey++,
        auth: false,
        urlEdited: false,
        outcome: { state: 'idle' },
        testedSignature: '',
        autoTest: true,
    });

    const form = useForm<WizardForm>({
        application: { name: '', host: '' },
        environments: [blankEnvironment(0, [])],
    });

    const rows = ref<WizardRow[]>([blankRow()]);

    const errors = computed(
        () => form.errors as Record<string, string | undefined>,
    );

    const suggestedUrl = (name: string): string => {
        const domain = form.application.host
            .trim()
            .replace(/^https?:\/\//i, '')
            .replace(/\/+$/, '');
        const path = horizonPath.value.trim().replace(/^\/+|\/+$/g, '');
        const environment = name.trim();

        if (domain === '' || environment === '') {
            return '';
        }

        const host =
            environment === 'production' ? domain : `${environment}.${domain}`;

        return `https://${host}${path === '' ? '' : `/${path}`}`;
    };

    watchEffect(() => {
        form.environments.forEach((environment, index) => {
            if (!rows.value[index]?.urlEdited) {
                environment.horizonUrl = suggestedUrl(environment.name);
            }
        });
    });

    const onUrlInput = (index: number): void => {
        const environment = form.environments[index];

        rows.value[index].urlEdited =
            environment.horizonUrl !== suggestedUrl(environment.name);
    };

    const applicationFilled = computed(
        () =>
            form.application.name.trim() !== '' &&
            form.application.host.trim() !== '',
    );

    const environmentsFilled = computed(
        () =>
            form.environments.length > 0 &&
            form.environments.every(
                (environment) =>
                    environment.name.trim() !== '' &&
                    environment.horizonUrl.trim() !== '',
            ),
    );

    const addEnvironment = (): void => {
        form.environments.push(
            blankEnvironment(
                form.environments.length,
                form.environments.map((environment) => environment.name.trim()),
            ),
        );
        rows.value.push(blankRow());
    };

    const removeEnvironment = (index: number): void => {
        form.environments.splice(index, 1);
        rows.value.splice(index, 1);
        form.clearErrors();
    };

    const testPayload = (
        environment: App.Data.Applications.EnvironmentFormData,
    ): App.Data.Applications.TestConnectionData => ({
        horizonUrl: environment.horizonUrl,
        basicAuthUser: environment.basicAuthUser,
        basicAuthPassword: environment.basicAuthPassword,
    });

    const signatureOf = (
        environment: App.Data.Applications.EnvironmentFormData,
    ) => JSON.stringify(testPayload(environment));

    watch(step, (current) => {
        if (current !== 3) {
            return;
        }

        form.environments.forEach((environment, index) => {
            const row = rows.value[index];
            const signature = signatureOf(environment);

            if (row.testedSignature !== signature) {
                row.outcome = { state: 'idle' };
                row.testedSignature = signature;
                row.autoTest = true;
            } else if (row.outcome.state === 'testing') {
                row.outcome = { state: 'idle' };
                row.autoTest = false;
            }
        });
    });

    const tests = new Map<number, ConnectionTester>();

    const rememberTest = (key: number, instance: unknown): void => {
        if (instance) {
            tests.set(key, instance as ConnectionTester);
        } else {
            tests.delete(key);
        }
    };

    const reachable = (outcome: ConnectionOutcome): boolean =>
        outcome.state === 'done' && outcome.result.reachable;

    const testAgain = async (): Promise<void> => {
        for (const row of rows.value.slice()) {
            if (!reachable(row.outcome)) {
                await tests.get(row.key)?.run();
            }
        }
    };

    const testing = computed(() =>
        rows.value.some((row) => row.outcome.state === 'testing'),
    );

    const stepOf = (keys: string[]): number => {
        if (keys.some((key) => key.startsWith('application'))) {
            return 1;
        }

        return keys.some((key) => key.startsWith('environments')) ? 2 : 3;
    };

    const unplacedErrors = computed(() =>
        Object.entries(errors.value)
            .filter(
                ([key]) =>
                    !key.startsWith('application') &&
                    !key.startsWith('environments'),
            )
            .map(([, message]) => message)
            .filter((message): message is string => Boolean(message)),
    );

    const submit = (): void => {
        form.post(store(slug.value).url, {
            onError: (bag) => {
                const keys = Object.keys(bag);

                rows.value.forEach((row, index) => {
                    if (
                        keys.some((key) =>
                            key.startsWith(`environments.${index}.basicAuth`),
                        )
                    ) {
                        row.auth = true;
                    }
                });

                step.value = stepOf(keys);
            },
        });
    };

    return {
        step,
        horizonPath,
        form,
        rows,
        errors,
        unplacedErrors,
        applicationFilled,
        environmentsFilled,
        testing,
        addEnvironment,
        removeEnvironment,
        onUrlInput,
        testPayload,
        rememberTest,
        testAgain,
        submit,
    };
}
