<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useSuggestedHorizonUrl } from '@/composables/useSuggestedHorizonUrl';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useBlurConnectionTest } from '@/composables/useBlurConnectionTest';
import { typeForName } from '@/lib/environments';
import {
    index as applicationsIndex,
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
        type: typeForName(''),
        horizonUrl: '',
        basicAuthUser: null,
        basicAuthPassword: null,
        pollIntervalSeconds: page.pollInterval.default,
        pollingEnabled: true,
    },
});

const typeEdited = ref(false);

const { onUrlInput } = useSuggestedHorizonUrl(
    computed({
        get: () => form.environment.horizonUrl,
        set: (url: string) => {
            form.environment.horizonUrl = url;
        },
    }),
    () =>
        page.baseHorizonUrl === null
            ? null
            : {
                  horizonUrl: page.baseHorizonUrl,
                  name: page.baseEnvironmentName ?? '',
              },
    () => form.environment.name,
    () => form.environment.type,
);

const testPayload = computed<App.Data.Applications.TestConnectionData>(() => ({
    horizonUrl: form.environment.horizonUrl,
    basicAuthUser: form.environment.basicAuthUser,
    basicAuthPassword: form.environment.basicAuthPassword,
}));

const auth = ref(false);

const connection = useTemplateRef('connection');

const { onUrlBlur, noteTested } = useBlurConnectionTest(
    () => testPayload.value,
    () => connection.value?.run(),
);

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
        class="nc-page nc-page-narrow flex flex-col"
        style="gap: var(--nc-space-6)"
    >
        <PageHeader
            :title="$t('Add environment')"
            :subtitle="page.domain"
            :crumbs="[
                { label: $t('Applications'), href: applicationsIndex(slug) },
                {
                    label: page.application.name,
                    href: show({
                        current_team: slug,
                        application: page.applicationSlug,
                    }),
                },
            ]"
        />

        <SectionCard :title="$t('Environment')">
            <form
                class="flex flex-col"
                style="gap: var(--nc-space-4)"
                novalidate
                @submit.prevent="submit"
            >
                <EnvironmentForm
                    v-model="form.environment"
                    v-model:auth="auth"
                    v-model:type-edited="typeEdited"
                    :types="page.types"
                    :errors="errors"
                    :has-password="page.hasPassword"
                    :poll-interval="page.pollInterval"
                    type-follows-name
                    @url-input="onUrlInput"
                    @url-blur="onUrlBlur"
                />
                <ConnectionTest
                    ref="connection"
                    :url="testUnsavedConnection(slug)"
                    :payload="testPayload"
                    :disabled="form.environment.horizonUrl.trim() === ''"
                    @run="noteTested"
                />
                <div class="nc-actions">
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
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="form.processing"
                    >
                        {{ $t('Add environment') }}
                    </button>
                </div>
            </form>
        </SectionCard>
    </div>
</template>
