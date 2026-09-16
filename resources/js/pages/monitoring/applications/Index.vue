<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PhPlus } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ApplicationSection from '@/components/monitoring/applications/ApplicationSection.vue';

defineOptions({
    layout: { title: 'Applications' },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationListPageData;
}>();

const search = ref('');

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
        <ApplicationSection
            v-for="group in groups"
            :key="group.application.id"
            :group="group"
        />
    </div>
</template>
