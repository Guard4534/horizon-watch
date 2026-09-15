<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';

const { throughput, maxWait, range } = defineProps<{
    throughput: number[];
    maxWait: number[];
    range: App.Enums.SeriesRange;
}>();

// router.reload's ReloadOptions drops preserveScroll/preserveState, so a
// query-string change goes through router.get instead.
const selected = computed({
    get: () => range,
    set: (next: App.Enums.SeriesRange) =>
        router.get(
            window.location.pathname,
            { range: next },
            { only: ['page'], preserveState: true, preserveScroll: true, replace: true },
        ),
});
</script>

<template>
    <SectionCard :title="$t('Throughput & max wait')">
        <template #actions>
            <span class="inline-flex items-center gap-[5px]" style="font-size: 11px; color: var(--nc-neutral-500)">
                <span class="h-[2px] w-[14px]" style="background: var(--nc-accent)" />jobs/min
            </span>
            <span class="inline-flex items-center gap-[5px]" style="font-size: 11px; color: var(--nc-neutral-500)">
                <span class="h-[2px] w-[14px]" style="background: var(--st-warn)" />max wait
            </span>
            <SegmentedControl
                v-model="selected"
                name="range"
                :options="[
                    { value: '3h', label: '3h' },
                    { value: '24h', label: '24h' },
                    { value: '7d', label: '7d' },
                ]"
            />
        </template>
        <div class="relative">
            <TrendLine :values="throughput" :width="520" :height="108" fill />
            <div class="absolute inset-0">
                <TrendLine :values="maxWait" :width="520" :height="108" color="var(--st-warn)" />
            </div>
        </div>
    </SectionCard>
</template>
