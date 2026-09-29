<script setup lang="ts">
import SectionCard from '@/components/nocturne/SectionCard.vue';
import {
    formatCount,
    formatWait,
    statusColor,
    statusLabel,
    waitColor,
} from '@/lib/monitoring';

const { waitThreshold } = defineProps<{
    queues: App.Data.Monitoring.QueueData[];
    note?: string | null;
    waitThreshold: number | null;
}>();
</script>

<template>
    <SectionCard :title="$t('Workload by queue')">
        <template v-if="note" #actions>
            <span class="nc-t-2xs" style="color: var(--st-warn)">{{
                note
            }}</span>
        </template>
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Queue') }}</th>
                        <th>{{ $t('Supervisor') }}</th>
                        <th class="nc-right">{{ $t('Workers') }}</th>
                        <th class="nc-right">{{ $t('Pending') }}</th>
                        <th class="nc-right">{{ $t('Wait') }}</th>
                        <th class="nc-right">{{ $t('Runtime') }}</th>
                        <th>{{ $t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="queue in queues" :key="queue.name">
                        <td class="nc-t-sm" style="letter-spacing: 0.01em">
                            {{ queue.name }}
                        </td>
                        <td
                            class="nc-t-xs nc-tone-soft"
                            style="letter-spacing: 0.01em"
                        >
                            {{ queue.supervisor ?? '—' }}
                        </td>
                        <td class="nc-num nc-right">
                            {{ queue.workers }}
                        </td>
                        <td class="nc-num nc-right">
                            {{ formatCount(queue.pending) }}
                        </td>
                        <td
                            class="nc-num nc-right"
                            :style="{
                                color: waitColor(
                                    queue.waitSeconds,
                                    waitThreshold,
                                ),
                            }"
                        >
                            {{ formatWait(queue.waitSeconds) }}
                        </td>
                        <td class="nc-num nc-right nc-tone-soft">
                            {{
                                queue.runtimeSeconds === null
                                    ? '—'
                                    : `${queue.runtimeSeconds.toFixed(1)}s`
                            }}
                        </td>
                        <td>
                            <span
                                class="nc-t-xs inline-flex items-center gap-[5px]"
                                :style="{ color: statusColor(queue.status) }"
                            >
                                <span
                                    class="size-[6px] rounded-full"
                                    :style="{
                                        background: statusColor(queue.status),
                                    }"
                                />{{ statusLabel(queue.status) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
