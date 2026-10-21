<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import TimeSeriesChart from '@/components/nocturne/TimeSeriesChart.vue';
import { useCurrentPath } from '@/composables/useCurrentPath';
import { formatCount, formatWait } from '@/lib/monitoring';
import { rangeLabel } from '@/lib/timeSeries';
import { replaceVisit } from '@/lib/visits';

const { throughput, maxWait, range, grid } = defineProps<{
    throughput: number[];
    maxWait: number[];
    range: App.Enums.SeriesRange;
    grid: App.Data.Monitoring.SeriesGridData;
}>();

const { path } = useCurrentPath();

const throughputLabel = computed(() =>
    trans(':range, peak :value', {
        range: rangeLabel(range),
        value: `${formatCount(Math.max(0, ...throughput))}/min`,
    }),
);

const waitLabel = computed(() =>
    trans(':range, peak max wait :wait', {
        range: rangeLabel(range),
        wait: formatWait(Math.max(0, ...maxWait)),
    }),
);

const describe = (value: number) => `${value}/min`;
const describeWait = (value: number) =>
    trans('max wait :wait', { wait: formatWait(value) });

const selected = computed({
    get: () => range,
    set: (next: App.Enums.SeriesRange) =>
        replaceVisit(path.value, ['series'], { range: next }),
});
</script>

<template>
    <SectionCard :title="$t('Throughput & max wait')">
        <template #actions>
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
        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <figure class="m-0">
                <figcaption class="caption">{{ $t('Throughput') }}</figcaption>
                <TimeSeriesChart
                    :values="throughput"
                    :starts-at="grid.startsAt"
                    :step-seconds="grid.stepSeconds"
                    :height="110"
                    :label="throughputLabel"
                    :describe="describe"
                />
            </figure>
            <figure class="m-0">
                <figcaption class="caption">{{ $t('Max wait') }}</figcaption>
                <TimeSeriesChart
                    :values="maxWait"
                    :starts-at="grid.startsAt"
                    :step-seconds="grid.stepSeconds"
                    :height="90"
                    :label="waitLabel"
                    :describe="describeWait"
                    unit="seconds"
                    color="var(--nc-neutral-300)"
                />
            </figure>
        </div>
    </SectionCard>
</template>

<style scoped>
.caption {
    margin-bottom: var(--nc-space-1);
    font-size: 12px;
    color: var(--nc-text-faint);
}
</style>
