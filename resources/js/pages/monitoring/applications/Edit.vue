<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhTrashSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ConfirmByNameDialog from '@/components/ConfirmByNameDialog.vue';
import DangerZoneCard from '@/components/DangerZoneCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ApplicationForm from '@/components/monitoring/applications/ApplicationForm.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    destroy,
    index as applicationsIndex,
    show,
    update,
} from '@/routes/applications';

defineOptions({
    layout: { title: 'Edit application' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationEditPageData;
}>();

const slug = useTeamSlug();

const application = computed(() => page.slug);
const name = computed(() => page.application.name);

const form = useForm<{
    application: App.Data.Applications.ApplicationFormData;
}>({
    application: {
        name: page.application.name,
    },
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const confirming = ref(false);

const submit = () => {
    form.transform((data) => data.application).patch(
        update({ current_team: slug.value, application: application.value })
            .url,
        { preserveScroll: true, onSuccess: () => form.defaults() },
    );
};
</script>

<template>
    <Head :title="$t('Edit application')" />

    <div
        class="nc-page nc-page-narrow flex flex-col"
        style="gap: var(--nc-space-6)"
    >
        <PageHeader
            :title="$t('Edit application')"
            :crumbs="[
                { label: $t('Applications'), href: applicationsIndex(slug) },
                {
                    label: name,
                    href: show({
                        current_team: slug,
                        application: application,
                    }),
                },
            ]"
        />

        <SectionCard :title="$t('Application')">
            <form
                class="flex flex-col"
                style="gap: var(--nc-space-4)"
                novalidate
                @submit.prevent="submit"
            >
                <ApplicationForm v-model="form.application" :errors="errors" />
                <div class="nc-actions">
                    <Link
                        class="nc-btn nc-btn-secondary"
                        :href="
                            show({
                                current_team: slug,
                                application: application,
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
            :title="$t('Delete application')"
            :description="
                $t(
                    'Deleting an application removes every environment configured under it. There is no undo and no archive.',
                )
            "
        >
            <button
                type="button"
                class="nc-btn nc-btn-danger nc-t-xs"
                @click="confirming = true"
            >
                <PhTrashSimple :size="13" />{{ $t('Delete application') }}
            </button>
        </DangerZoneCard>

        <ConfirmByNameDialog
            v-model:open="confirming"
            :resource-name="name"
            :title="$t('Delete application')"
            :body="
                $t(
                    'Everything below disappears as soon as you confirm, for everyone in the organization.',
                )
            "
            :items="[
                $t('The application and every environment under it.'),
                $t('The basic-auth credentials stored for those environments.'),
            ]"
            :confirm-label="$t('Delete application')"
            :url="destroy({ current_team: slug, application: application }).url"
        />
    </div>
</template>
