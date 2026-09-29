<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhPlus, PhStackSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ApplicationList from '@/components/mobile/applications/ApplicationList.vue';
import VisibilityEmptyState from '@/components/monitoring/VisibilityEmptyState.vue';
import ApplicationSection from '@/components/monitoring/applications/ApplicationSection.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useVisibility } from '@/composables/useVisibility';
import { create as createApplication } from '@/routes/applications';

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

const groups = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? page.groups.filter((group) =>
              `${group.application.name} ${group.application.host}`
                  .toLowerCase()
                  .includes(needle),
          )
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
                class="nc-input search ml-auto"
                :placeholder="$t('Search application')"
            />
            <Link
                v-if="canManageApplications"
                class="nc-btn nc-btn-primary"
                :href="createApplication(slug)"
            >
                <PhPlus :size="14" />{{ $t('Add application') }}
            </Link>
        </div>
        <VisibilityEmptyState
            v-if="nothingVisible"
            :icon="PhStackSimple"
            :kicker="$t('Nothing connected')"
            :title="$t('No applications yet')"
            :body="
                canManageApplications
                    ? $t(
                          'Add an application and its environments, and every one of them shows up here.',
                      )
                    : $t(
                          'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                      )
            "
        />
        <ApplicationList v-if="isMobile" :groups="groups" />
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
