<script setup lang="ts">
import { computed } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import { envColor } from '@/lib/monitoring';

const { environments, problemCount } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
    problemCount: number;
}>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });
const environmentName = defineModel<string>('environmentName', {
    required: true,
});
const search = defineModel<string>('search', { required: true });

const ORDER = [
    'production',
    'preprod',
    'staging',
    'develop',
    'demo',
    'worker-batch',
    'testing',
];

// The mockup's names first, in its order; any other name the organization
// uses follows alphabetically, so no environment lacks a chip.
const chips = computed(() => {
    const names = [
        ...new Set(environments.map((environment) => environment.name)),
    ].sort((a, b) => {
        const rank = (name: string) =>
            ORDER.includes(name) ? ORDER.indexOf(name) : ORDER.length;

        return rank(a) - rank(b) || a.localeCompare(b);
    });

    return names.map((name) => {
        const sample = environments.find(
            (environment) => environment.name === name,
        )!;

        return {
            name,
            color: envColor(sample.color),
            count: environments.filter(
                (environment) => environment.name === name,
            ).length,
        };
    });
});
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-2)">
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-3)">
            <SegmentedControl
                v-model="filter"
                name="wall-filter"
                :options="[
                    {
                        value: 'all',
                        label: `${$t('All')} ${environments.length}`,
                    },
                    {
                        value: 'problems',
                        label: `${$t('Problems')} ${problemCount}`,
                    },
                ]"
            />
            <input
                v-model="search"
                class="nc-input"
                style="max-width: 250px"
                :placeholder="$t('Filter by application or environment')"
            />
            <span
                class="ml-auto"
                style="font-size: 11px; color: var(--nc-neutral-600)"
                >{{ $t('sorted by severity, then by pending jobs') }}</span
            >
        </div>
        <div class="flex flex-wrap items-center" style="gap: var(--nc-space-2)">
            <span class="nc-label" style="margin-right: var(--nc-space-1)">{{
                $t('Environment')
            }}</span>
            <button
                type="button"
                class="chip"
                :class="{ 'is-active': environmentName === '' }"
                @click="environmentName = ''"
            >
                <span
                    class="size-2 flex-none rounded-[2px]"
                    style="background: var(--nc-neutral-500)"
                />
                {{ $t('All') }}
                <span class="nc-num" style="color: var(--nc-neutral-600)">{{
                    environments.length
                }}</span>
            </button>
            <button
                v-for="chip in chips"
                :key="chip.name"
                type="button"
                class="chip"
                :class="{ 'is-active': environmentName === chip.name }"
                @click="environmentName = chip.name"
            >
                <span
                    class="size-2 flex-none rounded-[2px]"
                    :style="{ background: chip.color }"
                />
                {{ chip.name }}
                <span class="nc-num" style="color: var(--nc-neutral-600)">{{
                    chip.count
                }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    padding: 3px 9px;
    border-radius: var(--nc-radius-sm);
    background: transparent;
    color: inherit;
    cursor: pointer;
    border: 1px solid var(--nc-divider);
}

.chip:hover {
    border-color: color-mix(in srgb, var(--nc-text) 45%, transparent);
}

.chip.is-active {
    border-color: var(--nc-accent);
    color: var(--nc-accent-200);
    background: color-mix(in srgb, var(--nc-accent) 12%, transparent);
}
</style>
