<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhPlus, PhTrashSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ApplicationForm from '@/components/monitoring/applications/ApplicationForm.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import WizardSteps from '@/components/monitoring/applications/WizardSteps.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { index as applicationsIndex, store } from '@/routes/applications';

defineOptions({
    layout: { title: 'Add application' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();

const slug = useTeamSlug();

// One page, three client-side steps, one POST at the end: the application
// and its first environments are created in a single transaction
// (AddApplication), so there is nothing to persist in between.
const step = ref(1);

const blankEnvironment = (
    index: number,
): App.Data.Applications.EnvironmentFormData => ({
    name: '',
    // Walk the palette so two rows never start out the same color.
    color: page.colors[index % page.colors.length]
        .value as App.Enums.EnvironmentColor,
    horizonUrl: '',
    basicAuthUser: null,
    basicAuthPassword: null,
    pollIntervalSeconds: 15,
});

const form = useForm<{
    application: App.Data.Applications.ApplicationFormData;
    environments: App.Data.Applications.EnvironmentFormData[];
}>({
    application: { name: '', host: '' },
    environments: [blankEnvironment(0)],
});

// Inertia's error bag is flat and dotted ("application.host",
// "environments.1.horizonUrl"); the field components look keys up by name.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

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
    form.environments.push(blankEnvironment(form.environments.length));
};

const removeEnvironment = (index: number) => {
    form.environments.splice(index, 1);
    // Every error key after the removed row now points at the wrong row.
    form.clearErrors();
};

// Server-side validation has to land the user back on the step that owns the
// offending field, or the message would be invisible.
const stepOf = (keys: string[]): number => {
    if (keys.some((key) => key.startsWith('application'))) {
        return 1;
    }

    return keys.some((key) => key.startsWith('environments')) ? 2 : 3;
};

const submit = () => {
    form.post(store(slug.value).url, {
        onError: (bag) => {
            step.value = stepOf(Object.keys(bag));
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
            max-width: 940px;
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
            :labels="[
                $t('Application'),
                $t('Environments'),
                $t('Confirmation'),
            ]"
            :current="step"
            @select="step = $event"
        />

        <SectionCard v-if="step === 1" :title="$t('Application')">
            <ApplicationForm
                v-model="form.application"
                :errors="errors"
                prefix="application."
            />
        </SectionCard>

        <template v-else-if="step === 2">
            <div
                v-if="errors.environments"
                style="font-size: 12px; color: var(--st-down)"
            >
                {{ errors.environments }}
            </div>
            <SectionCard
                v-for="(environment, index) in form.environments"
                :key="index"
                :title="
                    environment.name.trim() === ''
                        ? $t('Environment')
                        : environment.name
                "
            >
                <template #actions>
                    <button
                        type="button"
                        class="nc-btn nc-btn-ghost"
                        style="font-size: 12px; color: var(--nc-neutral-400)"
                        :disabled="form.environments.length === 1"
                        :title="$t('Remove environment')"
                        @click="removeEnvironment(index)"
                    >
                        <PhTrashSimple :size="13" />
                    </button>
                </template>
                <EnvironmentForm
                    v-model="form.environments[index]"
                    :colors="page.colors"
                    :errors="errors"
                    :prefix="`environments.${index}.`"
                />
            </SectionCard>
            <div>
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="addEnvironment"
                >
                    <PhPlus :size="13" />{{ $t('Add environment') }}
                </button>
            </div>
        </template>

        <SectionCard v-else :title="$t('Confirmation')">
            <div
                class="grid"
                style="
                    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                    gap: var(--nc-space-4);
                "
            >
                <div>
                    <div class="nc-label">{{ $t('Name') }}</div>
                    <div style="font-size: 15px">
                        {{ form.application.name }}
                    </div>
                </div>
                <div>
                    <div class="nc-label">{{ $t('Host') }}</div>
                    <div
                        style="
                            font-size: 13px;
                            color: var(--nc-neutral-300);
                            letter-spacing: 0.01em;
                        "
                    >
                        {{ form.application.host }}
                    </div>
                </div>
            </div>

            <div class="nc-hr" />

            <div class="overflow-x-auto">
                <table class="nc-table">
                    <thead>
                        <tr>
                            <th>{{ $t('Environment') }}</th>
                            <th>{{ $t('Horizon URL') }}</th>
                            <th>{{ $t('Collection') }}</th>
                            <th>{{ $t('Poll interval') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(environment, index) in form.environments"
                            :key="index"
                        >
                            <td>
                                <span class="inline-flex items-center gap-2"
                                    ><EnvSwatch :color="environment.color" />{{
                                        environment.name
                                    }}</span
                                >
                            </td>
                            <td
                                style="
                                    font-size: 12px;
                                    color: var(--nc-neutral-400);
                                    letter-spacing: 0.01em;
                                "
                            >
                                {{ environment.horizonUrl }}
                            </td>
                            <td
                                style="
                                    font-size: 12px;
                                    color: var(--nc-neutral-400);
                                "
                            >
                                {{
                                    environment.basicAuthUser
                                        ? `${$t('basic auth')} · ${environment.basicAuthUser}`
                                        : $t('no auth')
                                }}
                            </td>
                            <td
                                class="nc-num"
                                style="
                                    font-size: 12px;
                                    color: var(--nc-neutral-400);
                                "
                            >
                                {{ environment.pollIntervalSeconds }}
                                {{ $t('seconds') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="mt-[var(--nc-space-3)]"
                style="font-size: 12px; color: var(--nc-neutral-400)"
            >
                {{
                    $t(
                        'Nothing is contacted yet: checking that Horizon answers at these URLs arrives with the next release.',
                    )
                }}
            </div>
        </SectionCard>

        <div class="flex items-center" style="gap: var(--nc-space-3)">
            <Link
                v-if="step === 1"
                class="nc-btn nc-btn-secondary"
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
                {{ $t('Create application') }}
            </button>
        </div>
    </div>
</template>
