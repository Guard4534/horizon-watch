<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhPlugsConnected, PhTrashSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ConfirmByNameDialog from '@/components/monitoring/applications/ConfirmByNameDialog.vue';
import EnvironmentForm from '@/components/monitoring/applications/EnvironmentForm.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { show as showApplication } from '@/routes/applications';
import { destroy, show, update } from '@/routes/environments';

defineOptions({
    layout: { title: 'Edit environment' },
});

const { page } = defineProps<{
    page: App.Data.Pages.EnvironmentFormPageData;
}>();

const slug = useTeamSlug();

// Always filled here: EnvironmentFormPageData only makes them nullable
// because the create page reuses it with nothing to edit yet.
const environment = computed(() => page.slug ?? '');
const name = computed(() => page.environment?.name ?? '');

// One level down so the form component can take it as a writable model
// (v-model needs an assignable expression); transform() flattens it back
// into EnvironmentFormData for the request.
//
// basicAuthPassword starts empty on purpose, and no prop ever carries the
// stored one: an empty field means "keep the password already on file"
// (EnvironmentFormData::hasNewPassword()).
const form = useForm<{
    environment: App.Data.Applications.EnvironmentFormData;
}>({
    environment: {
        name: page.environment?.name ?? '',
        color: (page.environment?.color ??
            page.colors[0].value) as App.Enums.EnvironmentColor,
        horizonUrl: page.environment?.horizonUrl ?? '',
        basicAuthUser: page.environment?.basicAuthUser ?? null,
        basicAuthPassword: null,
        pollIntervalSeconds: page.environment?.pollIntervalSeconds ?? 15,
    },
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const confirming = ref(false);

const submit = () => {
    form.transform((data) => data.environment).patch(
        update({ current_team: slug.value, environment: environment.value })
            .url,
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="$t('Edit environment')" />

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
                    <Link
                        :href="
                            showApplication({
                                current_team: slug,
                                application: page.applicationSlug,
                            })
                        "
                        >{{ page.application.name }}</Link
                    >
                </div>
                <div style="font-size: 26px; line-height: 1.15">
                    {{ name }}
                </div>
                <div style="font-size: 12px; color: var(--nc-neutral-500)">
                    {{ $t('Edit environment') }}
                </div>
            </div>
            <div
                class="ml-auto flex flex-wrap items-center"
                style="gap: var(--nc-space-2)"
            >
                <Link
                    class="nc-btn nc-btn-ghost"
                    style="font-size: 12px"
                    :href="
                        show({ current_team: slug, environment: environment })
                    "
                    >{{ $t('Open') }}</Link
                >
            </div>
        </div>

        <SectionCard :title="$t('Credentials & collection')">
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
                    {{ $t('Save') }}
                </button>
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
                    type="button"
                    class="nc-btn nc-btn-secondary ml-auto"
                    style="font-size: 12px"
                    disabled
                    :title="$t('Available soon')"
                >
                    <PhPlugsConnected :size="13" />{{ $t('Test connection') }}
                </button>
            </div>
        </SectionCard>

        <SectionCard :title="$t('Delete environment')">
            <div style="font-size: 12px; color: var(--nc-neutral-400)">
                {{
                    $t(
                        'Deleting an environment removes it from the wall and from every list. There is no undo and no archive.',
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
                <PhTrashSimple :size="13" />{{ $t('Delete environment') }}
            </button>
        </SectionCard>

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
