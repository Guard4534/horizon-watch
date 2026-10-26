<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    PhDownloadSimple,
    PhPlus,
    PhStackSimple,
    PhUploadSimple,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ApplicationList from '@/components/mobile/applications/ApplicationList.vue';
import VisibilityEmptyState from '@/components/monitoring/VisibilityEmptyState.vue';
import WarningNotice from '@/components/monitoring/WarningNotice.vue';
import ApplicationSection from '@/components/monitoring/applications/ApplicationSection.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useVisibility } from '@/composables/useVisibility';
import { hostOf } from '@/lib/environments';
import { create as createApplication } from '@/routes/applications';
import {
    download as downloadConfiguration,
    show as showConfiguration,
} from '@/routes/configuration';

defineOptions({
    layout: { title: 'Applications' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationListPageData;
}>();

const search = ref('');
const slug = useTeamSlug();
const isMobile = useIsMobile();
const { canManageApplications } = useVisibility();

const nothingVisible = computed(() => page.groups.length === 0);

const haystack = (group: App.Data.Pages.ApplicationGroupData): string =>
    [
        group.application.name,
        ...group.environments.map(
            (environment) => hostOf(environment.horizonUrl) ?? '',
        ),
    ]
        .join(' ')
        .toLowerCase();

const groups = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? page.groups.filter((group) => haystack(group).includes(needle))
        : page.groups;
});
</script>

<template>
    <Head :title="$t('Applications')" />

    <div class="nc-page stack flex flex-col">
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
            <div class="nc-t-sm nc-tone-soft">
                {{
                    $t(
                        ':applications applications · :environments connected environments',
                        {
                            applications: String(page.groups.length),
                            environments: String(page.environmentCount),
                        },
                    )
                }}
            </div>
            <input
                v-model="search"
                type="search"
                class="nc-input search ml-auto"
                :placeholder="$t('Search application')"
                :aria-label="$t('Search application')"
            />
            <template v-if="page.canTransferConfiguration">
                <a
                    class="nc-btn nc-btn-secondary"
                    :href="downloadConfiguration(slug).url"
                    download
                    :title="
                        $t(
                            'Download applications, environments and alert rules as a file',
                        )
                    "
                >
                    <PhDownloadSimple :size="14" />{{ $t('Export') }}
                </a>
                <Link
                    class="nc-btn nc-btn-secondary"
                    :href="showConfiguration(slug)"
                    :title="
                        $t(
                            'Bring applications, environments and alert rules from a file',
                        )
                    "
                >
                    <PhUploadSimple :size="14" />{{ $t('Import') }}
                </Link>
            </template>
            <Link
                v-if="canManageApplications"
                class="nc-btn nc-btn-primary"
                :href="createApplication(slug)"
            >
                <PhPlus :size="14" />{{ $t('Add application') }}
            </Link>
        </div>
        <WarningNotice
            v-if="page.sharedApplicationNames.length > 0"
            :title="$t('More than one application has the same name')"
        >
            {{
                $t(
                    'Rename them apart, or a file exported from here cannot be imported: :names.',
                    { names: page.sharedApplicationNames.join(', ') },
                )
            }}
        </WarningNotice>
        <VisibilityEmptyState
            v-if="nothingVisible"
            :icon="PhStackSimple"
            :title="$t('No applications yet')"
            :body="
                canManageApplications
                    ? $t(
                          'Add an application and its environments, and every one of them shows up here.',
                      )
                    : $t(
                          'Nothing is configured yet. An admin of this organization has to add an application before anything shows up here.',
                      )
            "
        />
        <p v-else-if="groups.length === 0" class="nc-t-sm nc-tone-muted">
            {{ $t('No application matches this search.') }}
        </p>
        <ApplicationList v-else-if="isMobile" :groups="groups" />
        <template v-else>
            <ApplicationSection
                v-for="group in groups"
                :key="group.application.id"
                :group="group"
            />
        </template>
    </div>
</template>

<style scoped>
.stack {
    gap: var(--nc-space-4);
}

.search {
    max-width: 230px;
}

@media (max-width: 639px) {
    .stack {
        gap: var(--nc-space-3);
    }

    .search {
        max-width: 100%;
    }
}
</style>
