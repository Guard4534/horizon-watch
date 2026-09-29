<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    show,
    testConnection as testUnsavedConnection,
} from '@/routes/applications';
import { store } from '@/routes/environments';

defineOptions({
    layout: { title: 'Add environment' },
});

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentFormPageData;
}>();

const slug = useTeamSlug();

const form = useForm<{
    environment: App.Data.Applications.EnvironmentFormData;
}>({
    environment: {
        name: '',
        color: page.colors[0].value as App.Enums.EnvironmentColor,
        horizonUrl: '',
        basicAuthUser: null,
        basicAuthPassword: null,
        pollIntervalSeconds: 15,
        pollingEnabled: true,
    },
});

const testPayload = computed<App.Data.Applications.TestConnectionData>(() => ({
    horizonUrl: form.environment.horizonUrl,
    basicAuthUser: form.environment.basicAuthUser,
    basicAuthPassword: form.environment.basicAuthPassword,
}));

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const submit = () => {
    form.transform((data) => data.environment).post(
        store({
            current_team: slug.value,
            application: page.applicationSlug,
        }).url,
    );
};
</script>

<template>
    <Head :title="$t('Add environment')" />

    <div
        class="mx-auto flex w-full flex-col"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            max-width: 940px;
        "
    >
        <div>
            <div class="nc-t-2xs nc-tone-muted">
                <Link
                    :href="
                        show({
                            current_team: slug,
                            application: page.applicationSlug,
                        })
                    "
                    >{{ page.application.name }}</Link
                >
            </div>
            <div style="font-size: 26px; line-height: 1.15">
                {{ $t('Add environment') }}
            </div>
            <div class="nc-t-xs nc-tone-muted">
                {{ page.application.host }}
            </div>
        </div>

        <SectionCard :title="$t('Environment')">
            <EnvironmentForm
                v-model="form.environment"
                :colors="page.colors"
                :errors="errors"
                :has-password="page.hasPassword"
            />
            <div
                class="mt-[var(--nc-space-4)] flex flex-wrap items-center"
                style="gap: var(--nc-space-3)"
            >
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    :disabled="form.processing"
                    @click="submit"
                >
                    {{ $t('Add environment') }}
                </button>
                <Link
                    class="nc-btn nc-btn-secondary"
                    :href="
                        show({
                            current_team: slug,
                            application: page.applicationSlug,
                        })
                    "
                    >{{ $t('Cancel') }}</Link
                >
                <ConnectionTest
                    class="ml-auto"
                    :url="testUnsavedConnection(slug)"
                    :payload="testPayload"
                    :disabled="form.environment.horizonUrl.trim() === ''"
                />
            </div>
        </SectionCard>
    </div>
</template>
