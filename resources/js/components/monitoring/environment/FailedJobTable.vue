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
            <span v-if="note" style="font-size: 11px; color: var(--st-warn)">{{
                note
            }}</span>
            <span style="font-size: 11px; color: var(--nc-neutral-600)">{{
                $t('retry happens in Horizon — this panel is read-only')
            }}</span>
        </template>
        <div
            v-if="!jobs.length"
            style="font-size: 12px; color: var(--nc-neutral-500)"
        >
            {{ $t('No failed jobs in the latest reading.') }}
        </div>
        <div v-else class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Job') }}</th>
                        <th>{{ $t('Queue') }}</th>
                        <th>{{ $t('Exception') }}</th>
                        <th style="text-align: right">{{ $t('Tries') }}</th>
                        <th>{{ $t('When') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(job, index) in jobs" :key="index">
                        <td
                            class="whitespace-nowrap"
                            style="letter-spacing: 0.01em; font-size: 12px"
                        >
                            {{ job.job }}
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{ job.queue }}
                        </td>
                        <td
                            class="max-w-[250px] truncate"
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                            :title="job.exception"
                        >
                            {{ job.exception }}
                        </td>
                        <td
                            style="
                                text-align: right;
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{ job.tries }}
                        </td>
                        <td
                            class="whitespace-nowrap"
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-600);
                            "
                        >
                            {{ formatElapsed(job.minutesAgo) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
