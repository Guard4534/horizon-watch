<script setup lang="ts">
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { formatElapsed } from '@/lib/monitoring';

defineProps<{
    jobs: App.Data.Monitoring.FailedJobData[];
    note?: string | null;
}>();
</script>

<template>
    <SectionCard :title="$t('Recent failed jobs')">
        <template #actions>
            <span v-if="note" class="nc-t-2xs" style="color: var(--st-warn)">{{
                note
            }}</span>
            <span class="nc-t-2xs nc-tone-faint">{{
                $t('retry happens in Horizon — this panel is read-only')
            }}</span>
        </template>
        <div v-if="!jobs.length" class="nc-t-xs nc-tone-muted">
            {{ $t('No failed jobs in the latest reading.') }}
        </div>
        <div v-else class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Job') }}</th>
                        <th>{{ $t('Queue') }}</th>
                        <th>{{ $t('Exception') }}</th>
                        <th class="nc-right">{{ $t('Tries') }}</th>
                        <th>{{ $t('When') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(job, index) in jobs" :key="index">
                        <td
                            class="nc-t-xs whitespace-nowrap"
                            style="letter-spacing: 0.01em"
                        >
                            {{ job.job }}
                        </td>
                        <td class="nc-t-xs nc-tone-soft">
                            {{ job.queue }}
                        </td>
                        <td
                            class="nc-t-xs nc-tone-soft max-w-[250px] truncate"
                            :title="job.exception"
                        >
                            {{ job.exception }}
                        </td>
                        <td class="nc-right nc-t-xs nc-tone-soft">
                            {{ job.tries }}
                        </td>
                        <td class="nc-t-xs nc-tone-faint whitespace-nowrap">
                            {{ formatElapsed(job.minutesAgo) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
