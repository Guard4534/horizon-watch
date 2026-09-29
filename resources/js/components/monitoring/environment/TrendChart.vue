<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import TimeSeriesChart from '@/components/nocturne/TimeSeriesChart.vue';
import { useCurrentPath } from '@/composables/useCurrentPath';
import { formatCount, formatWait } from '@/lib/monitoring';
import { rangeLabel } from '@/lib/timeSeries';

const { throughput, maxWait, range, grid } = defineProps<{
    throughput: number[];
    maxWait: number[];
    range: App.Enums.SeriesRange;
    grid: App.Data.Monitoring.SeriesGridData;
}>();

const { path } = useCurrentPath();

const label = computed(() =>
    trans(':range, peak :count jobs/min, peak max wait :wait', {
        range: rangeLabel(range),
        count: formatCount(Math.max(0, ...throughput)),
        wait: formatWait(Math.max(0, ...maxWait)),
    }),
);

const describe = (value: number) =>
    trans(':count jobs/min', { count: String(value) });
const describeWait = (value: number) =>
    trans('max wait :wait', { wait: formatWait(value) });

const selected = computed({
    get: () => range,
    set: (next: App.Enums.SeriesRange) =>
        router.get(
            path.value,
            { range: next },
            {
                only: ['page'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        ),
});
</script>

<template>
    <SectionCard :title="$t('Throughput & max wait')">
        <template #actions>
            <span
                class="nc-t-2xs nc-tone-muted inline-flex items-center gap-[5px]"
            >
                <span
                    class="h-[2px] w-[14px]"
                    style="background: var(--nc-accent)"
                />{{ $t('jobs/min') }}
            </span>
            <span
                class="nc-t-2xs nc-tone-muted inline-flex items-center gap-[5px]"
            >
                <span
                    class="h-[2px] w-[14px]"
                    style="background: var(--st-warn)"
                />{{ $t('max wait') }}
            </span>
            <SegmentedControl
                v-model="selected"
                name="range"
                :options="[
                    { value: '3h', label: '3h' },
                    { value: '24h', label: '24h' },
                    { value: '7d', label: $t(':days d', { days: '7' }) },
                ]"
            />
        </template>
        <TimeSeriesChart
            :values="throughput"
            :secondary="maxWait"
            :starts-at="grid.startsAt"
            :step-seconds="grid.stepSeconds"
            :height="150"
            :label="label"
            :describe="describe"
            :describe-secondary="describeWait"
        />
    </SectionCard>
</template>
