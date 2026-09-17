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
    waitThreshold: number;
}>();
</script>

<template>
    <SectionCard :title="$t('Workload by queue')">
        <template v-if="note" #actions>
            <span style="font-size: 11px; color: var(--st-warn)">{{
                note
            }}</span>
        </template>
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>Queue</th>
                        <th>Supervisor</th>
                        <th style="text-align: right">Workers</th>
                        <th style="text-align: right">Pending</th>
                        <th style="text-align: right">Wait</th>
                        <th style="text-align: right">Runtime</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="queue in queues" :key="queue.name">
                        <td style="letter-spacing: 0.01em; font-size: 13px">
                            {{ queue.name }}
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                                letter-spacing: 0.01em;
                            "
                        >
                            {{ queue.supervisor ?? '—' }}
                        </td>
                        <td class="nc-num" style="text-align: right">
                            {{ queue.workers }}
                        </td>
                        <td class="nc-num" style="text-align: right">
                            {{ formatCount(queue.pending) }}
                        </td>
                        <td
                            class="nc-num"
                            style="text-align: right"
                            :style="{
                                color: waitColor(
                                    queue.waitSeconds,
                                    waitThreshold,
                                ),
                            }"
                        >
                            {{ formatWait(queue.waitSeconds) }}
                        </td>
                        <td
                            class="nc-num"
                            style="
                                text-align: right;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{
                                queue.runtimeSeconds === null
                                    ? '—'
                                    : `${queue.runtimeSeconds.toFixed(1)}s`
                            }}
                        </td>
                        <td>
                            <span
                                class="inline-flex items-center gap-[5px]"
                                style="font-size: 12px"
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
