<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhPlus, PhStackSimple } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import ApplicationSection from '@/components/monitoring/applications/ApplicationSection.vue';

defineOptions({
    layout: { title: 'Applications' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationListPageData;
}>();

const search = ref('');
const shared = usePage();

// page.groups, not the filtered list: a search that matches nothing is not
// an unconfigured organization.
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
            <button
                type="button"
                class="nc-btn nc-btn-primary"
                disabled
                :title="$t('Available soon')"
            >
                <PhPlus :size="14" />{{ $t('Add application') }}
            </button>
        </div>
        <EmptyState
            v-if="nothingVisible"
            :icon="PhStackSimple"
            :kicker="$t('Nothing connected')"
            :title="$t('No applications yet')"
            :body="
                shared.props.canManageApplications
                    ? $t(
                          'Add an application and its environments, and every one of them shows up here.',
                      )
                    : $t(
                          'No environment is visible to you yet. An administrator of this organization can widen your visibility or configure an application.',
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
