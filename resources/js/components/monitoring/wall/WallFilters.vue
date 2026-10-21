<script setup lang="ts">
import { computed, useId } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import { envTypeColor } from '@/lib/monitoring';

const { environments, attentionCount, types } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
    attentionCount: number;
    types: App.Data.Pages.WallPageData['types'];
}>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });
const environmentType = defineModel<App.Enums.EnvironmentType | ''>(
    'environmentType',
    { required: true },
);
const search = defineModel<string>('search', { required: true });

const id = useId();

const chips = computed(() =>
    types
        .map((option) => {
            const type = option.value as App.Enums.EnvironmentType;

            return {
                type,
                label: option.label,
                color: envTypeColor(type),
                count: environments.filter(
                    (environment) => environment.type === type,
                ).length,
            };
        })
        .filter((chip) => chip.count > 0),
);
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
                        label: `${$t('Needs attention')} ${attentionCount}`,
                    },
                ]"
            />
            <input
                v-model="search"
                type="search"
                class="nc-input"
                style="max-width: 250px"
                :placeholder="$t('Filter by application or environment')"
                :aria-label="$t('Filter by application or environment')"
            />
        </div>
        <div
            class="flex flex-wrap items-center"
            style="gap: var(--nc-space-2)"
            role="group"
            :aria-labelledby="`${id}-type`"
        >
            <span
                :id="`${id}-type`"
                class="nc-label"
                style="margin-right: var(--nc-space-1)"
                >{{ $t('Type') }}</span
            >
            <button
                type="button"
                class="chip"
                :class="{ 'is-active': environmentType === '' }"
                :aria-pressed="environmentType === ''"
                @click="environmentType = ''"
            >
                <span
                    class="size-2 flex-none rounded-[2px]"
                    style="background: var(--nc-neutral-500)"
                />
                {{ $t('All') }}
                <span class="nc-num nc-tone-faint">{{
                    environments.length
                }}</span>
            </button>
            <button
                v-for="chip in chips"
                :key="chip.type"
                type="button"
                class="chip"
                :class="{ 'is-active': environmentType === chip.type }"
                :aria-pressed="environmentType === chip.type"
                @click="environmentType = chip.type"
            >
                <span
                    class="size-2 flex-none rounded-[2px]"
                    :style="{ background: chip.color }"
                />
                {{ chip.label }}
                <span class="nc-num nc-tone-faint">{{ chip.count }}</span>
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
