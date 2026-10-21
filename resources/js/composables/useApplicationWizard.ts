import type { InertiaForm } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch, watchEffect } from 'vue';
import type { ConnectionOutcome } from '@/components/monitoring/applications/connection';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    domainOf,
    followSuggestion,
    leavesSuggestion,
    suggestHorizonUrl,
    typeForName,
} from '@/lib/environments';
import { store } from '@/routes/applications';

export type WizardRow = {
    key: number;
    auth: boolean;
    urlEdited: boolean;
    typeEdited: boolean;
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
    form: InertiaForm<WizardForm>;
    rows: Ref<WizardRow[]>;
    errors: ComputedRef<Record<string, string | undefined>>;
    unplacedErrors: ComputedRef<string[]>;
    applicationFilled: ComputedRef<boolean>;
    environmentsFilled: ComputedRef<boolean>;
    domain: ComputedRef<string | null>;
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
    advance: () => void;
};

const SUGGESTED_NAMES = [
    'production',
    'staging',
    'preprod',
    'develop',
    'demo',
    'testing',
];

export function useApplicationWizard(
    pollInterval: App.Data.Applications.PollIntervalData,
): ApplicationWizard {
    const slug = useTeamSlug();

    const step = ref(1);

    let nextKey = 0;

    const blankEnvironment = (
        taken: string[],
    ): App.Data.Applications.EnvironmentFormData => {
        const name =
            SUGGESTED_NAMES.find((candidate) => !taken.includes(candidate)) ??
            '';

        return {
            name,
            type: typeForName(name),
            horizonUrl: '',
            basicAuthUser: null,
            basicAuthPassword: null,
            pollIntervalSeconds: pollInterval.default,
            pollingEnabled: true,
        };
    };

    const blankRow = (): WizardRow => ({
        key: nextKey++,
        auth: false,
        urlEdited: false,
        typeEdited: false,
        outcome: { state: 'idle' },
        testedSignature: '',
        autoTest: true,
    });

    const form = useForm<WizardForm>({
        application: { name: '' },
        environments: [blankEnvironment([])],
    });

    const rows = ref<WizardRow[]>([blankRow()]);

    const errors = computed(
        () => form.errors as Record<string, string | undefined>,
    );

    const suggestedUrl = (index: number): string => {
        const base = form.environments[0];
        const environment = form.environments[index];

        return index === 0 || base === undefined || environment === undefined
            ? ''
            : suggestHorizonUrl(
                  base.horizonUrl,
                  base.name,
                  environment.name,
                  environment.type,
              );
    };

    watchEffect(() => {
        form.environments.forEach((environment, index) => {
            if (index > 0) {
                environment.horizonUrl = followSuggestion(
                    environment.horizonUrl,
                    suggestedUrl(index),
                    rows.value[index]?.urlEdited ?? false,
                );
            }
        });
    });

    const onUrlInput = (index: number): void => {
        if (index > 0) {
            rows.value[index].urlEdited = leavesSuggestion(
                form.environments[index].horizonUrl,
                suggestedUrl(index),
            );
        }
    };

    const applicationFilled = computed(
        () => form.application.name.trim() !== '',
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

    const domain = computed(() => domainOf(form.environments));

    const addEnvironment = (): void => {
        form.environments.push(
            blankEnvironment(
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

    const advance = (): void => {
        if (step.value === 1 && applicationFilled.value) {
            step.value = 2;
        } else if (step.value === 2 && environmentsFilled.value) {
            step.value = 3;
        } else if (step.value === 3 && !form.processing) {
            submit();
        }
    };

    return {
        step,
        form,
        rows,
        errors,
        unplacedErrors,
        applicationFilled,
        environmentsFilled,
        domain,
        testing,
        addEnvironment,
        removeEnvironment,
        onUrlInput,
        testPayload,
        rememberTest,
        testAgain,
        submit,
        advance,
    };
}
