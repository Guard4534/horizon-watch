<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhTrashSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ConfirmByNameDialog from '@/components/ConfirmByNameDialog.vue';
import DangerZoneCard from '@/components/DangerZoneCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    index as applicationsIndex,
    show as showApplication,
} from '@/routes/applications';
import { destroy, show, testConnection, update } from '@/routes/environments';

defineOptions({
    layout: { title: 'Edit environment' },
});

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentFormPageData;
}>();

const slug = useTeamSlug();

const environment = computed(() => page.slug ?? '');
const name = computed(() => page.environment?.name ?? '');

const storedCredentials = computed(
    () =>
        (page.environment?.basicAuthUser ?? null) !== null || page.hasPassword,
);

const auth = ref(storedCredentials.value);

const form = useForm<{
    environment: App.Data.Applications.EnvironmentFormData;
}>({
    environment: {
        name: page.environment?.name ?? '',
        type: page.environment?.type ?? 'staging',
        horizonUrl: page.environment?.horizonUrl ?? '',
        basicAuthUser: page.environment?.basicAuthUser ?? null,
        basicAuthPassword: null,
        pollIntervalSeconds:
            page.environment?.pollIntervalSeconds ?? page.pollInterval.default,
        pollingEnabled: page.environment?.pollingEnabled ?? true,
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

const confirming = ref(false);

const submit = () => {
    form.transform((data) => data.environment).patch(
        update({ current_team: slug.value, environment: environment.value })
            .url,
        {
            preserveScroll: true,
            onSuccess: () => {
                form.environment.basicAuthPassword = null;
                form.defaults();
            },
        },
    );
};
</script>

<template>
    <Head :title="$t('Edit environment')" />

    <div
        class="nc-page nc-page-narrow flex flex-col"
        style="gap: var(--nc-space-6)"
    >
        <PageHeader
            :title="$t('Edit environment')"
            :crumbs="[
                { label: $t('Applications'), href: applicationsIndex(slug) },
                {
                    label: page.application.name,
                    href: showApplication({
                        current_team: slug,
                        application: page.applicationSlug,
                    }),
                },
                {
                    label: name,
                    href: show({
                        current_team: slug,
                        environment: environment,
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
                    :types="page.types"
                    :errors="errors"
                    :has-password="page.hasPassword"
                    :poll-interval="page.pollInterval"
                    :stored-credentials="storedCredentials"
                    editing
                />
                <ConnectionTest
                    :url="
                        testConnection({
                            current_team: slug,
                            environment: environment,
                        })
                    "
                    :payload="testPayload"
                    :disabled="form.environment.horizonUrl.trim() === ''"
                />
                <div class="nc-actions">
                    <Link
                        class="nc-btn nc-btn-secondary"
                        :href="
                            showApplication({
                                current_team: slug,
                                application: page.applicationSlug,
                            })
                        "
                        >{{ $t('Cancel') }}</Link
                    >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="!form.isDirty || form.processing"
                    >
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </SectionCard>

        <DangerZoneCard
            :title="$t('Delete environment')"
            :description="
                $t(
                    'Deleting an environment removes it from the wall and from every list. There is no undo and no archive.',
                )
            "
        >
            <button
                type="button"
                class="nc-btn nc-btn-danger nc-t-xs"
                @click="confirming = true"
            >
                <PhTrashSimple :size="13" />{{ $t('Delete environment') }}
            </button>
        </DangerZoneCard>

        <ConfirmByNameDialog
            v-model:open="confirming"
            :resource-name="name"
            :title="$t('Delete environment')"
            :body="
                $t(
                    'Everything below disappears as soon as you confirm, for everyone in the organization.',
                )
            "
            :items="[
                $t('The environment and its place on the wall.'),
                $t('The basic-auth credentials stored for it.'),
            ]"
            :confirm-label="$t('Delete environment')"
            :url="destroy({ current_team: slug, environment: environment }).url"
        />
    </div>
</template>
