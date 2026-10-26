<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PhFileArrowUp, PhUploadSimple } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import FormErrors from '@/components/FormErrors.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PasswordNeededTag from '@/components/monitoring/PasswordNeededTag.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useLocale } from '@/composables/useLocale';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    importItemLabel,
    importOutcome,
    importStatusClass,
    importStatusLabel,
    importTotals,
} from '@/lib/configuration';
import { formatInstant } from '@/lib/datetime';
import { invalidField } from '@/lib/fieldErrors';
import { index as applicationsIndex } from '@/routes/applications';
import { apply, discard, upload } from '@/routes/configuration';

defineOptions({
    layout: { title: 'Import configuration' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ConfigurationImportPageData;
}>();

const slug = useTeamSlug();
const { locale } = useLocale();

const fileForm = useForm<{ file: File | null }>({ file: null });
const applyForm = useForm<{ update: boolean }>({ update: false });

const applyErrors = computed(
    () => applyForm.errors as Record<string, string | undefined>,
);
const fileError = computed(
    () =>
        fileForm.errors.file ??
        (page.preview ? undefined : applyErrors.value.file),
);

const items = computed(() => page.preview?.items ?? []);
const totals = computed(() => importTotals(items.value, applyForm.update));
const hasChanged = computed(() =>
    items.value.some((item) => item.status === 'changed'),
);
const nothingToDo = computed(
    () => totals.value.create + totals.value.update === 0,
);
const waitsForPassword = computed(() =>
    items.value.some((item) => item.passwordNeeded),
);

const origin = computed(() => {
    const preview = page.preview;

    if (!preview?.organization || !preview.exportedAt) {
        return null;
    }

    return trans('Exported from :organization · :time', {
        organization: preview.organization,
        time: formatInstant(preview.exportedAt, locale.value),
    });
});

const pick = (event: Event): void => {
    fileForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const sendFile = (): void => {
    fileForm.post(upload(slug.value).url, {
        forceFormData: true,
        preserveScroll: true,
    });
};

const chooseAnother = (): void => {
    applyForm.reset();
    applyForm.clearErrors();
    router.delete(discard(slug.value).url, {
        data: { uploadId: page.uploadId },
        preserveScroll: true,
    });
};

const runImport = (): void => {
    applyForm
        .transform((data) => ({ ...data, uploadId: page.uploadId }))
        .post(apply(slug.value).url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('Import configuration')" />

    <div
        class="nc-page nc-page-narrow flex flex-col"
        style="gap: var(--nc-space-5)"
    >
        <PageHeader
            :title="$t('Import configuration')"
            :crumbs="[
                { label: $t('Applications'), href: applicationsIndex(slug) },
            ]"
        />

        <SectionCard :title="$t('Configuration file')">
            <p class="nc-t-xs nc-tone-soft">
                {{
                    $t(
                        'Upload a file exported from another Horizon Watch panel. Nothing changes until you confirm the import, and nothing is ever deleted.',
                    )
                }}
            </p>
            <p class="nc-t-2xs nc-tone-muted mt-1">
                {{
                    $t(
                        'Basic-auth passwords and the webhook signing secret are never in the file.',
                    )
                }}
            </p>

            <form
                v-if="!page.preview"
                class="mt-[var(--nc-space-4)] flex flex-wrap items-end"
                style="gap: var(--nc-space-3)"
                novalidate
                @submit.prevent="sendFile"
            >
                <div class="nc-field min-w-0 flex-1">
                    <label for="configuration-file">{{
                        $t('Configuration file')
                    }}</label>
                    <input
                        id="configuration-file"
                        class="nc-input"
                        type="file"
                        accept="application/json,.json"
                        v-bind="invalidField('configuration-file', fileError)"
                        @change="pick"
                    />
                    <p class="nc-t-2xs nc-tone-muted">
                        {{ $t('A JSON file of at most 1 MB.') }}
                    </p>
                    <InputError
                        size="sm"
                        field="configuration-file"
                        :message="fileError"
                    />
                </div>
                <div class="nc-actions">
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="
                            fileForm.processing || fileForm.file === null
                        "
                    >
                        <PhFileArrowUp :size="14" />{{ $t('Show preview') }}
                    </button>
                </div>
            </form>

            <div
                v-else
                class="mt-[var(--nc-space-4)] flex flex-wrap items-center"
                style="gap: var(--nc-space-3)"
            >
                <div class="min-w-0">
                    <div class="nc-t-sm break-all">
                        {{ page.preview.fileName }}
                    </div>
                    <div v-if="origin" class="nc-t-2xs nc-tone-muted">
                        {{ origin }}
                    </div>
                </div>
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary nc-t-xs ml-auto"
                    @click="chooseAnother"
                >
                    {{ $t('Choose another file') }}
                </button>
            </div>
        </SectionCard>

        <template v-if="page.preview">
            <SectionCard :title="$t('Preview')">
                <label class="nc-switch nc-t-xs">
                    <input
                        v-model="applyForm.update"
                        type="checkbox"
                        role="switch"
                        :aria-checked="applyForm.update"
                        aria-describedby="configuration-update-hint"
                    />
                    <span class="nc-track" />
                    {{ $t('Update existing items') }}
                </label>
                <p
                    id="configuration-update-hint"
                    class="nc-t-2xs nc-tone-muted mt-1"
                >
                    {{
                        $t(
                            'Off: items that differ from the file are left as they are. On: they take the values of the file.',
                        )
                    }}
                </p>

                <p class="nc-t-xs nc-tone-soft mt-[var(--nc-space-3)]">
                    {{
                        $t(
                            ':create to create · :update to update · :leave left as they are',
                            {
                                create: String(totals.create),
                                update: String(totals.update),
                                leave: String(totals.leave),
                            },
                        )
                    }}
                </p>

                <ul class="items mt-[var(--nc-space-3)]">
                    <li
                        v-for="(item, index) in page.preview.items"
                        :key="index"
                        class="item"
                        :class="{ nested: item.kind === 'environment' }"
                    >
                        <span class="name min-w-0">
                            <template
                                v-if="item.kind === 'environment' && item.type"
                            >
                                <span class="nc-tone-muted"
                                    >{{ item.application }} /</span
                                >
                                <EnvPill
                                    :name="item.label"
                                    :type="item.type"
                                    :size="12"
                                />
                            </template>
                            <template v-else>{{
                                importItemLabel(item)
                            }}</template>
                        </span>
                        <span
                            class="nc-tag nc-tag-sm flex-none"
                            :class="importStatusClass(item.status)"
                            >{{ importStatusLabel(item.status) }}</span
                        >
                        <span
                            v-if="importOutcome(item, applyForm.update)"
                            class="nc-t-2xs nc-tone-muted"
                            >{{ importOutcome(item, applyForm.update) }}</span
                        >
                        <PasswordNeededTag v-if="item.passwordNeeded" />
                        <span
                            v-if="item.changes.length"
                            class="detail nc-t-2xs nc-tone-muted"
                        >
                            <code
                                v-for="field in item.changes"
                                :key="field"
                                class="nc-code"
                                >{{ field }}</code
                            >
                        </span>
                        <ul
                            v-if="item.errors.length"
                            class="detail nc-t-2xs nc-tone-down"
                        >
                            <li v-for="error in item.errors" :key="error">
                                {{ error }}
                            </li>
                        </ul>
                    </li>
                </ul>

                <p
                    v-if="waitsForPassword"
                    class="nc-t-2xs nc-tone-muted mt-[var(--nc-space-3)]"
                >
                    {{
                        $t(
                            'Environments marked Password needed arrive with collection paused: enter their basic-auth password on their edit page to start it.',
                        )
                    }}
                </p>
            </SectionCard>

            <form
                class="flex flex-col"
                style="gap: var(--nc-space-3)"
                novalidate
                @submit.prevent="runImport"
            >
                <FormErrors
                    :errors="[
                        applyErrors.file,
                        page.preview.blocked
                            ? $t(
                                  'Fix the invalid items in the file and upload it again.',
                              )
                            : null,
                    ]"
                />
                <p
                    v-if="!page.preview.blocked && nothingToDo"
                    class="nc-t-xs nc-tone-muted"
                >
                    {{
                        hasChanged && !applyForm.update
                            ? $t(
                                  'Nothing is new: turn on Update existing items to apply the differences.',
                              )
                            : $t(
                                  'The file changes nothing in this organization.',
                              )
                    }}
                </p>
                <div class="nc-actions">
                    <Link
                        class="nc-btn nc-btn-secondary"
                        :href="applicationsIndex(slug)"
                        >{{ $t('Cancel') }}</Link
                    >
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="
                            applyForm.processing ||
                            page.preview.blocked ||
                            nothingToDo
                        "
                    >
                        <PhUploadSimple :size="14" />{{ $t('Import') }}
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>

<style scoped>
.items {
    display: flex;
    flex-direction: column;
}

.item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--nc-space-1) var(--nc-space-2);
    padding: var(--nc-space-2) 0;
    border-top: 1px solid var(--nc-divider);
}

.item.nested {
    padding-left: var(--nc-space-4);
}

.name {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}

.detail {
    flex-basis: 100%;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.is-invalid {
    background: color-mix(in srgb, var(--st-down) 16%, transparent);
}
</style>
