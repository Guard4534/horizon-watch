<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    PhArrowClockwise,
    PhCheckCircle,
    PhCircleNotch,
    PhMinusCircle,
    PhPlus,
    PhTrashSimple,
    PhWarning,
} from '@phosphor-icons/vue';
import type { Component } from 'vue';
import { computed, ref, watch, watchEffect } from 'vue';
import ApplicationForm from '@/components/monitoring/applications/ApplicationForm.vue';
import ColorPicker from '@/components/monitoring/applications/ColorPicker.vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import type { ConnectionOutcome } from '@/components/monitoring/applications/ConnectionTest.vue';
import WizardSteps from '@/components/monitoring/applications/WizardSteps.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envColor } from '@/lib/monitoring';
import {
    index as applicationsIndex,
    store,
    testConnection,
} from '@/routes/applications';

defineOptions({
    layout: { title: 'Add application' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();

const slug = useTeamSlug();

const step = ref(1);

const horizonPath = ref('horizon');

const SUGGESTED_NAMES: Array<[string, string]> = [
    ['production', 'prod'],
    ['staging', 'staging'],
    ['preprod', 'preprod'],
    ['develop', 'develop'],
    ['demo', 'demo'],
    ['testing', 'testing'],
];

type Row = {
    key: number;
    auth: boolean;
    urlEdited: boolean;
    outcome: ConnectionOutcome;
    testedSignature: string;
    autoTest: boolean;
};

let nextKey = 0;

const blankEnvironment = (
    index: number,
    taken: string[],
): App.Data.Applications.EnvironmentFormData => {
    const available = page.colors.map((color) => color.value);
    const suggestion = SUGGESTED_NAMES.find(
        ([name, color]) => !taken.includes(name) && available.includes(color),
    );

    return {
        name: suggestion?.[0] ?? '',
        color: (suggestion?.[1] ??
            page.colors[index % page.colors.length]
                .value) as App.Enums.EnvironmentColor,
        horizonUrl: '',
        basicAuthUser: null,
        basicAuthPassword: null,
        pollIntervalSeconds: 15,
        pollingEnabled: true,
    };
};

const blankRow = (): Row => ({
    key: nextKey++,
    auth: false,
    urlEdited: false,
    outcome: { state: 'idle' },
    testedSignature: '',
    autoTest: true,
});

const form = useForm<{
    application: App.Data.Applications.ApplicationFormData;
    environments: App.Data.Applications.EnvironmentFormData[];
}>({
    application: { name: '', host: '' },
    environments: [blankEnvironment(0, [])],
});

const rows = ref<Row[]>([blankRow()]);

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

const onUrlInput = (index: number) => {
    const environment = form.environments[index];

    rows.value[index].urlEdited =
        environment.horizonUrl !== suggestedUrl(environment.name);
};

const toggleAuth = (index: number) => {
    const row = rows.value[index];
    row.auth = !row.auth;

    if (!row.auth) {
        form.environments[index].basicAuthUser = null;
        form.environments[index].basicAuthPassword = null;
    }
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

const addEnvironment = () => {
    form.environments.push(
        blankEnvironment(
            form.environments.length,
            form.environments.map((environment) => environment.name.trim()),
        ),
    );
    rows.value.push(blankRow());
};

const removeEnvironment = (index: number) => {
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

const signatureOf = (environment: App.Data.Applications.EnvironmentFormData) =>
    JSON.stringify(testPayload(environment));

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

const tests = new Map<number, InstanceType<typeof ConnectionTest>>();

const rememberTest = (key: number, instance: unknown) => {
    if (instance) {
        tests.set(key, instance as InstanceType<typeof ConnectionTest>);
    } else {
        tests.delete(key);
    }
};

const reachable = (outcome: ConnectionOutcome): boolean =>
    outcome.state === 'done' && outcome.result.reachable;

const testAgain = async () => {
    for (const row of rows.value.slice()) {
        if (!reachable(row.outcome)) {
            await tests.get(row.key)?.run();
        }
    }
};

const testing = computed(() =>
    rows.value.some((row) => row.outcome.state === 'testing'),
);

const checkIcon = (outcome: ConnectionOutcome): Component => {
    if (outcome.state === 'testing') {
        return PhCircleNotch;
    }

    if (outcome.state !== 'done') {
        return PhMinusCircle;
    }

    return outcome.result.reachable ? PhCheckCircle : PhWarning;
};

const checkColor = (outcome: ConnectionOutcome): string => {
    if (outcome.state !== 'done') {
        return 'var(--nc-neutral-500)';
    }

    return outcome.result.reachable ? 'var(--st-ok)' : 'var(--st-warn)';
};

const refusedCredentials = (outcome: ConnectionOutcome): boolean =>
    outcome.state === 'done' && outcome.result.error === 'unauthorized';

const pillStyle = (color: App.Enums.EnvironmentColor) => ({
    color: envColor(color),
    background: `color-mix(in srgb, ${envColor(color)} 20%, transparent)`,
});

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

const submit = () => {
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
</script>

<template>
    <Head :title="$t('Add application')" />

    <div
        class="mx-auto flex w-full flex-col"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            max-width: 720px;
        "
    >
        <div>
            <div style="font-size: 11px; color: var(--nc-neutral-500)">
                <Link :href="applicationsIndex(slug)">{{
                    $t('Applications')
                }}</Link>
            </div>
            <div style="font-size: 26px; line-height: 1.15">
                {{ $t('Add application') }}
            </div>
            <div style="font-size: 12px; color: var(--nc-neutral-500)">
                {{
                    $t(
                        'An application and its environments are created together, in one step.',
                    )
                }}
            </div>
        </div>

        <WizardSteps
            :labels="[$t('Application'), $t('Environments'), $t('Verify')]"
            :current="step"
            @select="step = $event"
        />

        <SectionCard v-if="step === 1">
            <ApplicationForm
                v-model="form.application"
                :errors="errors"
                prefix="application."
                wizard
            >
                <div class="nc-field">
                    <label for="wizard-horizon-path">{{
                        $t('Horizon path')
                    }}</label>
                    <input
                        id="wizard-horizon-path"
                        v-model="horizonPath"
                        class="nc-input"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="horizon"
                    />
                    <div
                        class="mt-1"
                        style="font-size: 11px; color: var(--nc-neutral-600)"
                    >
                        {{ $t('If you changed HORIZON_PATH, set it here.') }}
                    </div>
                </div>
            </ApplicationForm>
        </SectionCard>

        <div
            v-else-if="step === 2"
            class="flex flex-col"
            style="gap: var(--nc-space-3)"
        >
            <div
                v-if="errors.environments"
                style="font-size: 12px; color: var(--st-down)"
            >
                {{ errors.environments }}
            </div>

            <div
                v-for="(environment, index) in form.environments"
                :key="rows[index].key"
                class="row"
            >
                <EnvSwatch :color="environment.color" shape="edge" :size="4" />

                <div
                    class="grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_auto]"
                    style="gap: var(--nc-space-3)"
                >
                    <div class="nc-field">
                        <label :for="`environments-${index}-name`">{{
                            $t('Environment name')
                        }}</label>
                        <input
                            :id="`environments-${index}-name`"
                            v-model="environment.name"
                            class="nc-input"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="production"
                        />
                        <div
                            v-if="errors[`environments.${index}.name`]"
                            class="mt-1"
                            style="font-size: 11px; color: var(--st-down)"
                        >
                            {{ errors[`environments.${index}.name`] }}
                        </div>
                    </div>

                    <div class="nc-field">
                        <label :for="`environments-${index}-horizonUrl`">{{
                            $t('Horizon URL')
                        }}</label>
                        <input
                            :id="`environments-${index}-horizonUrl`"
                            v-model="environment.horizonUrl"
                            class="nc-input"
                            style="letter-spacing: 0.01em"
                            type="url"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="https://invoicer.example.com/horizon"
                            @input="onUrlInput(index)"
                        />
                        <div
                            v-if="errors[`environments.${index}.horizonUrl`]"
                            class="mt-1"
                            style="font-size: 11px; color: var(--st-down)"
                        >
                            {{ errors[`environments.${index}.horizonUrl`] }}
                        </div>
                    </div>

                    <div class="flex items-start sm:pt-[22px]">
                        <button
                            type="button"
                            class="nc-btn nc-btn-ghost"
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                            :disabled="form.environments.length === 1"
                            :title="$t('Remove environment')"
                            :aria-label="$t('Remove environment')"
                            @click="removeEnvironment(index)"
                        >
                            <PhTrashSimple :size="13" />
                        </button>
                    </div>
                </div>

                <div class="nc-field mt-[var(--nc-space-3)]">
                    <label>{{ $t('Color') }}</label>
                    <ColorPicker
                        v-model="environment.color"
                        :colors="page.colors"
                        :name="`environments-${index}-color`"
                    />
                    <div
                        v-if="errors[`environments.${index}.color`]"
                        class="mt-1"
                        style="font-size: 11px; color: var(--st-down)"
                    >
                        {{ errors[`environments.${index}.color`] }}
                    </div>
                </div>

                <div
                    class="mt-[var(--nc-space-3)] flex flex-wrap items-center"
                    style="gap: var(--nc-space-3)"
                >
                    <label class="nc-radio" style="font-size: 12px">
                        <input
                            type="checkbox"
                            :checked="rows[index].auth"
                            @change="toggleAuth(index)"
                        />
                        <span class="nc-dot" />
                        {{ $t('Basic auth') }}
                    </label>
                    <div
                        v-if="rows[index].auth"
                        class="flex flex-1 flex-wrap"
                        style="gap: var(--nc-space-2); min-width: 220px"
                    >
                        <input
                            v-model="environment.basicAuthUser"
                            class="nc-input min-w-0 flex-1"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('Basic-auth username')"
                            :aria-label="$t('Basic-auth username')"
                        />
                        <input
                            v-model="environment.basicAuthPassword"
                            class="nc-input min-w-0 flex-1"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="$t('Basic-auth password')"
                            :aria-label="$t('Basic-auth password')"
                        />
                    </div>
                </div>
                <div
                    v-for="field in ['basicAuthUser', 'basicAuthPassword']"
                    :key="field"
                >
                    <div
                        v-if="errors[`environments.${index}.${field}`]"
                        class="mt-1"
                        style="font-size: 11px; color: var(--st-down)"
                    >
                        {{ errors[`environments.${index}.${field}`] }}
                    </div>
                </div>

                <div
                    class="mt-[var(--nc-space-3)] flex flex-wrap items-center"
                    style="gap: var(--nc-space-4)"
                >
                    <label
                        class="flex items-center"
                        style="
                            gap: var(--nc-space-2);
                            font-size: 12px;
                            color: var(--nc-neutral-400);
                        "
                    >
                        {{ $t('Poll interval') }}
                        <input
                            v-model.number="environment.pollIntervalSeconds"
                            class="nc-input"
                            style="max-width: 80px; min-height: 30px"
                            type="number"
                            min="15"
                            max="300"
                            step="1"
                        />
                        {{ $t('seconds') }}
                    </label>
                    <label class="nc-radio" style="font-size: 12px">
                        <input
                            v-model="environment.pollingEnabled"
                            type="checkbox"
                            role="switch"
                            :aria-checked="environment.pollingEnabled"
                        />
                        <span class="nc-dot" />
                        {{ $t('Collect readings') }}
                    </label>
                </div>
                <div
                    v-for="field in ['pollIntervalSeconds', 'pollingEnabled']"
                    :key="field"
                >
                    <div
                        v-if="errors[`environments.${index}.${field}`]"
                        class="mt-1"
                        style="font-size: 11px; color: var(--st-down)"
                    >
                        {{ errors[`environments.${index}.${field}`] }}
                    </div>
                </div>
            </div>

            <div>
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    style="font-size: 12px"
                    @click="addEnvironment"
                >
                    <PhPlus :size="13" />{{ $t('Add environment') }}
                </button>
            </div>
        </div>

        <div v-else class="flex flex-col" style="gap: var(--nc-space-3)">
            <div
                v-for="(message, index) in unplacedErrors"
                :key="index"
                style="font-size: 12px; color: var(--st-down)"
            >
                {{ message }}
            </div>

            <div
                class="flex flex-wrap items-baseline"
                style="gap: var(--nc-space-2)"
            >
                <span style="font-size: 15px">{{ form.application.name }}</span>
                <span
                    style="
                        font-size: 12px;
                        color: var(--nc-neutral-500);
                        letter-spacing: 0.01em;
                    "
                    >{{ form.application.host }}</span
                >
                <button
                    type="button"
                    class="nc-btn nc-btn-ghost ml-auto"
                    style="font-size: 12px"
                    :disabled="testing"
                    @click="void testAgain()"
                >
                    <PhArrowClockwise :size="13" />{{ $t('Test again') }}
                </button>
            </div>

            <div
                v-for="(environment, index) in form.environments"
                :key="rows[index].key"
                class="check"
            >
                <component
                    :is="checkIcon(rows[index].outcome)"
                    :size="16"
                    class="mt-[2px] flex-none"
                    :class="{
                        'animate-spin': rows[index].outcome.state === 'testing',
                    }"
                    :style="{ color: checkColor(rows[index].outcome) }"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex items-center" style="gap: 8px">
                        <span
                            class="pill"
                            :style="pillStyle(environment.color)"
                            >{{ environment.name }}</span
                        >
                        <span
                            class="ml-auto flex-none"
                            :style="{
                                fontSize: '11px',
                                color: checkColor(rows[index].outcome),
                            }"
                        >
                            <template
                                v-if="rows[index].outcome.state === 'testing'"
                                >{{ $t('Testing…') }}</template
                            >
                            <template
                                v-else-if="
                                    rows[index].outcome.state === 'done' &&
                                    rows[index].outcome.result.reachable
                                "
                                >{{ $t('Connected') }}</template
                            >
                            <template
                                v-else-if="rows[index].outcome.state === 'done'"
                                >{{ $t('Not connected') }}</template
                            >
                            <template v-else>{{ $t('Not tested') }}</template>
                        </span>
                    </div>
                    <div class="url">{{ environment.horizonUrl }}</div>
                    <div
                        class="nc-num"
                        style="
                            margin-top: 2px;
                            font-size: 11px;
                            color: var(--nc-neutral-600);
                        "
                    >
                        {{
                            environment.basicAuthUser
                                ? `${$t('basic auth')} · ${environment.basicAuthUser}`
                                : $t('no auth')
                        }}
                        · {{ environment.pollIntervalSeconds }}
                        {{ $t('seconds') }}
                        <template v-if="!environment.pollingEnabled">
                            · {{ $t('Collection paused') }}</template
                        >
                    </div>
                    <ConnectionTest
                        :ref="
                            (instance) =>
                                rememberTest(rows[index].key, instance)
                        "
                        v-model:outcome="rows[index].outcome"
                        class="mt-[6px]"
                        :url="testConnection(slug)"
                        :payload="testPayload(environment)"
                        :show-button="false"
                        :auto="rows[index].autoTest"
                    />
                    <div
                        v-if="refusedCredentials(rows[index].outcome)"
                        style="
                            margin-top: 2px;
                            font-size: 11px;
                            color: var(--nc-neutral-500);
                        "
                    >
                        {{
                            $t(
                                'The endpoint asks for credentials: go back and fill them in.',
                            )
                        }}
                    </div>
                </div>
            </div>

            <div class="nc-field mt-[var(--nc-space-2)]">
                <label for="wizard-rules">{{
                    $t('Alert rules to apply')
                }}</label>
                <select
                    id="wizard-rules"
                    class="nc-input"
                    disabled
                    aria-describedby="wizard-rules-hint"
                >
                    <option>{{ $t('Organization default') }}</option>
                </select>
                <div
                    id="wizard-rules-hint"
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{
                        $t(
                            'The organization defaults apply from the first reading; thresholds per environment name are set on the Alert settings page.',
                        )
                    }}
                </div>
            </div>

            <div style="font-size: 12px; color: var(--nc-neutral-400)">
                {{
                    $t(
                        'A failed test does not stop you: the environment reads unreachable until Horizon answers.',
                    )
                }}
            </div>
        </div>

        <div class="flex items-center" style="gap: var(--nc-space-3)">
            <Link
                v-if="step === 1"
                class="nc-btn nc-btn-ghost"
                :href="applicationsIndex(slug)"
                >{{ $t('Cancel') }}</Link
            >
            <button
                v-else
                type="button"
                class="nc-btn nc-btn-secondary"
                @click="step -= 1"
            >
                {{ $t('Back') }}
            </button>

            <button
                v-if="step < 3"
                type="button"
                class="nc-btn nc-btn-primary ml-auto"
                :disabled="
                    step === 1 ? !applicationFilled : !environmentsFilled
                "
                @click="step += 1"
            >
                {{ $t('Continue') }}
            </button>
            <button
                v-else
                type="button"
                class="nc-btn nc-btn-primary ml-auto"
                :disabled="form.processing"
                @click="submit"
            >
                {{ $t('Add application') }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.row {
    position: relative;
    overflow: hidden;
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3)
        var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}

.check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}

.pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--nc-radius-sm);
    font-size: 12px;
}

.url {
    margin-top: 4px;
    overflow: hidden;
    font-size: 11px;
    letter-spacing: 0.01em;
    color: var(--nc-neutral-500);
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
