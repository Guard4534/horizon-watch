<script setup lang="ts">
import {
    failedLastHourText,
    hasMeasurement,
    statusText,
    statusTone,
    thresholdTone,
} from '@/components/monitoring/environment/readings';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { failedWindowNote, failedWindowShort } from '@/lib/failedWindow';
import { formatCount, formatWait } from '@/lib/monitoring';

defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
}>();
</script>

<template>
    <SectionCard :title="$t('Environment comparison')">
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Environment') }}</th>
                        <th class="nc-right">{{ $t('Pending') }}</th>
                        <th class="nc-right">{{ $t('Max wait') }}</th>
                        <th class="nc-right">{{ $t('Failed · last hour') }}</th>
                        <th class="nc-right">{{ $t('Workers') }}</th>
                        <th class="nc-right">{{ $t('Throughput') }}</th>
                    </tr>
                </thead>
                <tbody class="nc-num">
                    <tr
                        v-for="environment in environments"
                        :key="environment.id"
                    >
                        <td>
                            <span class="inline-flex items-center gap-2"
                                ><EnvSwatch :type="environment.type" />{{
                                    environment.name
                                }}</span
                            >
                        </td>
                        <template v-if="!hasMeasurement(environment)">
                            <td
                                v-for="column in 5"
                                :key="column"
                                class="nc-right nc-tone-faint"
                            >
                                <span
                                    v-if="column === 1"
                                    class="nc-t-2xs"
                                    style="margin-right: 6px"
                                    :style="{ color: statusTone(environment) }"
                                    >{{ statusText(environment) }}</span
                                >—
                            </td>
                        </template>
                        <template v-else>
                            <td
                                class="nc-right"
                                :style="{
                                    color: thresholdTone(
                                        environment,
                                        'queue.pending',
                                        environment.pending,
                                    ),
                                }"
                            >
                                {{ formatCount(environment.pending) }}
                            </td>
                            <td
                                class="nc-right"
                                :style="{
                                    color: thresholdTone(
                                        environment,
                                        'queue.max_wait',
                                        environment.maxWaitSeconds,
                                    ),
                                }"
                            >
                                {{ formatWait(environment.maxWaitSeconds) }}
                            </td>
                            <td class="nc-right">
                                <span
                                    :style="{
                                        color: thresholdTone(
                                            environment,
                                            'jobs.failed_per_hour',
                                            environment.failedLastHour,
                                        ),
                                    }"
                                    >{{ failedLastHourText(environment) }}</span
                                ><span
                                    :title="
                                        failedWindowNote(
                                            environment.failedWindowMinutes,
                                        )
                                    "
                                    style="
                                        margin-left: 6px;
                                        font-size: 11px;
                                        color: var(--nc-neutral-500);
                                    "
                                    >{{
                                        formatCount(environment.failedInWindow)
                                    }}
                                    {{
                                        failedWindowShort(
                                            environment.failedWindowMinutes,
                                        )
                                    }}</span
                                >
                            </td>
                            <td class="nc-right">
                                {{ environment.workers }}
                            </td>
                            <td class="nc-right">
                                {{ `${environment.jobsPerMinute}/min` }}
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
