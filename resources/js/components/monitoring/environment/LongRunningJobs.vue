<script setup lang="ts">
import { thresholdTone } from '@/components/monitoring/environment/readings';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useLocale } from '@/composables/useLocale';
import { formatDuration } from '@/lib/monitoring';
import { formatClock } from '@/lib/timeSeries';

const {
    jobs,
    thresholdSeconds,
    thresholds,
    pollingEnabled,
    unknown = false,
} = defineProps<{
    jobs: App.Data.Monitoring.LongRunningJobData[];
    thresholdSeconds: number | null;
    thresholds: App.Data.Monitoring.EnvironmentData['thresholds'];
    pollingEnabled: boolean;
    unknown?: boolean;
}>();

const { locale } = useLocale();

function color(seconds: number): string {
    return (
        thresholdTone({ thresholds, pollingEnabled }, 'job.runtime', seconds) ??
        'var(--nc-neutral-400)'
    );
}

function width(seconds: number): string {
    if (thresholdSeconds === null || thresholdSeconds <= 0) return '100%';

    return `${Math.min(100, Math.round((seconds / thresholdSeconds) * 100))}%`;
}
</script>

<template>
    <SectionCard :title="$t('Long-running jobs')">
        <template #actions>
            <span class="nc-t-2xs nc-tone-faint">{{
                thresholdSeconds === null
                    ? $t('rule disabled')
                    : $t('threshold :value', {
                          value: `${thresholdSeconds}s`,
                      })
            }}</span>
        </template>
        <div v-if="!jobs.length" class="nc-t-xs nc-tone-muted">
            {{
                unknown
                    ? $t('Unknown until the next successful reading.')
                    : $t('No job is running past the threshold.')
            }}
        </div>
        <div
            v-else
            class="nc-t-xs flex flex-col"
            style="gap: var(--nc-space-3)"
        >
            <div v-for="(job, index) in jobs" :key="index">
                <div class="flex gap-2">
                    <span
                        class="min-w-0 truncate"
                        style="letter-spacing: 0.01em"
                        >{{ job.job }}</span
                    >
                    <span
                        class="nc-num ml-auto flex-none"
                        :style="{ color: color(job.elapsedSeconds) }"
                        >{{ formatDuration(job.elapsedSeconds) }}</span
                    >
                </div>
                <div
                    class="mt-[6px] h-[3px] overflow-hidden rounded-[2px]"
                    style="background: var(--nc-neutral-900)"
                >
                    <div
                        class="h-[3px]"
                        :style="{
                            width: width(job.elapsedSeconds),
                            background: color(job.elapsedSeconds),
                        }"
                    />
                </div>
                <div class="nc-tone-faint mt-1">
                    {{
                        $t('queue :queue · started at :time', {
                            queue: job.queue,
                            time: formatClock(new Date(job.startedAt), locale),
                        })
                    }}
                </div>
            </div>
        </div>
    </SectionCard>
</template>
