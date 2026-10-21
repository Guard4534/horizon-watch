<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowClockwise, PhPlus, PhTrashSimple } from '@phosphor-icons/vue';
import FormErrors from '@/components/FormErrors.vue';
import ApplicationForm from '@/components/monitoring/applications/ApplicationForm.vue';
import ConnectionCheckRow from '@/components/monitoring/applications/ConnectionCheckRow.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import WizardSteps from '@/components/monitoring/applications/WizardSteps.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useApplicationWizard } from '@/composables/useApplicationWizard';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    index as applicationsIndex,
    testConnection,
} from '@/routes/applications';

defineOptions({
    layout: { title: 'Add application' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();

const slug = useTeamSlug();

const {
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
    advance,
} = useApplicationWizard(page.pollInterval);
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
            <div class="nc-t-2xs nc-tone-muted">
                <Link :href="applicationsIndex(slug)">{{
                    $t('Applications')
                }}</Link>
            </div>
            <div style="font-size: 26px; line-height: 1.15">
                {{ $t('Add application') }}
            </div>
            <div class="nc-t-xs nc-tone-muted">
                {{
                    $t(
                        'An application and its environments are created together, in one step.',
                    )
                }}
            </div>
        </div>

        <form
            class="flex flex-col"
            style="gap: var(--nc-space-6)"
            novalidate
            @submit.prevent="advance"
        >
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
                />
            </SectionCard>

            <div
                v-else-if="step === 2"
                class="flex flex-col"
                style="gap: var(--nc-space-3)"
            >
                <FormErrors :errors="[errors.environments]" />

                <div
                    v-for="(environment, index) in form.environments"
                    :key="rows[index].key"
                    class="nc-card row"
                >
                    <EnvironmentForm
                        v-model="form.environments[index]"
                        v-model:auth="rows[index].auth"
                        v-model:type-edited="rows[index].typeEdited"
                        :types="page.types"
                        :errors="errors"
                        :prefix="`environments.${index}.`"
                        :poll-interval="page.pollInterval"
                        compact
                        type-follows-name
                        @url-input="onUrlInput(index)"
                    >
                        <template #actions>
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost nc-t-xs nc-tone-down"
                                :disabled="form.environments.length === 1"
                                :title="$t('Remove environment')"
                                :aria-label="$t('Remove environment')"
                                @click="removeEnvironment(index)"
                            >
                                <PhTrashSimple :size="13" />
                            </button>
                        </template>
                    </EnvironmentForm>
                </div>

                <div>
                    <button
                        type="button"
                        class="nc-btn nc-btn-secondary nc-t-xs"
                        :disabled="
                            page.maxEnvironments !== null &&
                            form.environments.length >= page.maxEnvironments
                        "
                        @click="addEnvironment"
                    >
                        <PhPlus :size="13" />{{ $t('Add environment') }}
                    </button>
                </div>
            </div>

            <div v-else class="flex flex-col" style="gap: var(--nc-space-3)">
                <FormErrors :errors="unplacedErrors" />

                <div
                    class="flex flex-wrap items-baseline"
                    style="gap: var(--nc-space-2)"
                >
                    <span style="font-size: 15px">{{
                        form.application.name
                    }}</span>
                    <span
                        v-if="domain"
                        class="nc-t-xs nc-tone-muted"
                        style="letter-spacing: 0.01em"
                        >{{ domain }}</span
                    >
                    <button
                        type="button"
                        class="nc-btn nc-btn-ghost nc-t-xs ml-auto"
                        :disabled="testing"
                        @click="void testAgain()"
                    >
                        <PhArrowClockwise :size="13" />{{ $t('Test again') }}
                    </button>
                </div>

                <ConnectionCheckRow
                    v-for="(environment, index) in form.environments"
                    :ref="(instance) => rememberTest(rows[index].key, instance)"
                    :key="rows[index].key"
                    v-model:outcome="rows[index].outcome"
                    :environment="environment"
                    :url="testConnection(slug)"
                    :payload="testPayload(environment)"
                    :auto="rows[index].autoTest"
                />

                <div class="nc-field mt-[var(--nc-space-2)]">
                    <span class="block">{{ $t('Alert rules to apply') }}</span>
                    <p class="nc-t-sm">{{ $t('Organization default') }}</p>
                    <div class="nc-t-2xs nc-tone-faint mt-1">
                        {{
                            $t(
                                'The organization defaults apply from the first reading; thresholds per environment name are set on the Alert settings page.',
                            )
                        }}
                    </div>
                </div>

                <div class="nc-t-xs nc-tone-soft">
                    {{
                        $t(
                            'A failed test does not stop you: the environment reads unreachable until Horizon answers.',
                        )
                    }}
                </div>
            </div>

            <div class="nc-actions">
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
                    type="submit"
                    class="nc-btn nc-btn-primary"
                    :disabled="
                        step === 1 ? !applicationFilled : !environmentsFilled
                    "
                >
                    {{ $t('Continue') }}
                </button>
                <button
                    v-else
                    type="submit"
                    class="nc-btn nc-btn-primary"
                    :disabled="form.processing"
                >
                    {{ $t('Add application') }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.row {
    position: relative;
    overflow: hidden;
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3)
        var(--nc-space-4);
}
</style>
