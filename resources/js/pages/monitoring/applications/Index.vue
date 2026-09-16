<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhPlus, PhStackSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import ApplicationSection from '@/components/monitoring/applications/ApplicationSection.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { create as createApplication } from '@/routes/applications';

defineOptions({
    layout: { title: 'Applications' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationListPageData;
}>();

const search = ref('');
const slug = useTeamSlug();
const shared = usePage();

// page.groups, not the filtered list: a search that matches nothing is not
// an unconfigured organization.
const nothingVisible = computed(() => page.groups.length === 0);

// Restricted only means something is being kept from this member if the
// organization holds anything at all: a viewer limited to non-production
// in an empty organization has nothing hidden from them.
const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
);

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

    <div
        class="flex flex-col"
        style="padding: var(--nc-space-6); gap: var(--nc-space-4)"
    >
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
            <div style="font-size: 13px; color: var(--nc-neutral-400)">
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
                class="nc-input ml-auto"
                style="max-width: 230px"
                :placeholder="$t('Search application')"
            />
            <Link
                v-if="shared.props.canManageApplications"
                class="nc-btn nc-btn-primary"
                :href="createApplication(slug)"
            >
                <PhPlus :size="14" />{{ $t('Add application') }}
            </Link>
        </div>
        <EmptyState
            v-if="nothingVisible"
            :icon="PhStackSimple"
            :kicker="$t('Nothing connected')"
            :title="$t('No applications yet')"
            :body="
                somethingIsHidden
                    ? $t(
                          'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                      )
                    : shared.props.canManageApplications
                      ? $t(
                            'Add an application and its environments, and every one of them shows up here.',
                        )
                      : $t(
                            'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                        )
            "
        />
        <ApplicationSection
            v-for="group in groups"
            :key="group.application.id"
            :group="group"
        />
    </div>
</template>
