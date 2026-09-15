<script setup lang="ts">
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { formatDuration } from '@/lib/monitoring';

const { jobs, thresholdSeconds } = defineProps<{
    jobs: App.Data.Monitoring.LongRunningJobData[];
    thresholdSeconds: number;
}>();

function color(seconds: number): string {
    if (seconds > 300) return 'var(--st-down)';

    return seconds > thresholdSeconds ? 'var(--st-warn)' : 'var(--st-ok)';
}
</script>

<template>
    <SectionCard :title="$t('Long-running jobs')">
        <template #actions>
            <span style="font-size: 11px; color: var(--nc-neutral-600)">{{ $t('threshold') }} {{ thresholdSeconds }}s</span>
        </template>
        <div class="flex flex-col" style="gap: var(--nc-space-3); font-size: 12px">
            <div v-for="job in jobs" :key="job.job">
                <div class="flex gap-2">
                    <span class="min-w-0 truncate" style="letter-spacing: 0.01em">{{ job.job }}</span>
                    <span class="nc-num ml-auto flex-none" :style="{ color: color(job.elapsedSeconds) }">{{ formatDuration(job.elapsedSeconds) }}</span>
                </div>
                <div class="mt-[6px] h-[3px] overflow-hidden rounded-[2px]" style="background: var(--nc-neutral-900)">
                    <div class="h-[3px]" :style="{ width: `${Math.min(100, Math.round((job.elapsedSeconds / thresholdSeconds) * 100))}%`, background: color(job.elapsedSeconds) }" />
                </div>
                <div class="mt-1" style="color: var(--nc-neutral-600)">queue {{ job.queue }} · {{ $t('started at :time', { time: job.startedAt }) }}</div>
            </div>
        </div>
    </SectionCard>
</template>
