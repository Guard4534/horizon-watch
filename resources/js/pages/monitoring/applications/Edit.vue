<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhPlus, PhTrashSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ApplicationForm from '@/components/monitoring/applications/ApplicationForm.vue';
import ConfirmByNameDialog from '@/components/monitoring/applications/ConfirmByNameDialog.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    destroy,
    index as applicationsIndex,
    show,
    update,
} from '@/routes/applications';
import { create as createEnvironment } from '@/routes/environments';

defineOptions({
    layout: { title: 'Edit application' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();

const slug = useTeamSlug();

const application = computed(() => page.slug ?? '');
const name = computed(() => page.application?.name ?? '');

const form = useForm<{
    application: App.Data.Applications.ApplicationFormData;
}>({
    application: {
        name: page.application?.name ?? '',
        host: page.application?.host ?? '',
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
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="$t('Edit application')" />

    <div
        class="mx-auto flex w-full flex-col"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            max-width: 940px;
        "
    >
        <div class="flex flex-wrap items-end" style="gap: var(--nc-space-4)">
            <div class="min-w-0">
                <div style="font-size: 11px; color: var(--nc-neutral-500)">
                    <Link :href="applicationsIndex(slug)">{{
                        $t('Applications')
                    }}</Link>
                </div>
                <div style="font-size: 26px; line-height: 1.15">
                    {{ name }}
                </div>
                <div style="font-size: 12px; color: var(--nc-neutral-500)">
                    {{ $t('Edit application') }}
                </div>
            </div>
            <div
                class="ml-auto flex flex-wrap items-center"
                style="gap: var(--nc-space-2)"
            >
                <Link
                    class="nc-btn nc-btn-secondary"
                    style="font-size: 12px"
                    :href="
                        createEnvironment({
                            current_team: slug,
                            application: application,
                        })
                    "
                    ><PhPlus :size="13" />{{ $t('Add environment') }}</Link
                >
                <Link
                    class="nc-btn nc-btn-ghost"
                    style="font-size: 12px"
                    :href="
                        show({ current_team: slug, application: application })
                    "
                    >{{ $t('Open') }}</Link
                >
            </div>
        </div>

        <SectionCard :title="$t('Application')">
            <ApplicationForm v-model="form.application" :errors="errors" />
            <div
                class="mt-[var(--nc-space-4)] flex items-center"
                style="gap: var(--nc-space-3)"
            >
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    :disabled="form.processing"
                    @click="submit"
                >
                    {{ $t('Save') }}
                </button>
                <Link
                    class="nc-btn nc-btn-secondary"
                    :href="applicationsIndex(slug)"
                    >{{ $t('Cancel') }}</Link
                >
            </div>
        </SectionCard>

        <SectionCard :title="$t('Delete application')">
            <div style="font-size: 12px; color: var(--nc-neutral-400)">
                {{
                    $t(
                        'Deleting an application removes every environment configured under it. There is no undo and no archive.',
                    )
                }}
            </div>
            <button
                type="button"
                class="nc-btn mt-[var(--nc-space-3)]"
                style="
                    font-size: 12px;
                    color: var(--st-down);
                    border-color: var(--st-down);
                "
                @click="confirming = true"
            >
                <PhTrashSimple :size="13" />{{ $t('Delete application') }}
            </button>
        </SectionCard>

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
