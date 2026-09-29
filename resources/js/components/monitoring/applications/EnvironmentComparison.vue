<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import {
    failedTone,
    hasMeasurement,
    pendingTone,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { thresholdOf } from '@/lib/alertRules';
import { failedLabel, failedWindowShort } from '@/lib/failedWindow';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';

const { environments } = defineProps<{
    environments: App.Data.Monitoring.EnvironmentData[];
}>();

const windows = computed(
    () =>
        new Set(
            environments
                .filter(hasMeasurement)
                .map((environment) => environment.failedWindowMinutes),
        ),
);

const header = computed(() => {
    if (windows.value.size === 0) {
        return trans('Failed');
    }

    return windows.value.size === 1
        ? failedLabel([...windows.value][0])
        : failedLabel(null);
});
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
                        <th class="nc-right">
                            {{ header }}
                        </th>
                        <th class="nc-right">{{ $t('Workers') }}</th>
                        <th class="nc-right">{{ $t('jobs/min') }}</th>
                    </tr>
                </thead>
                <tbody class="nc-num">
                    <tr
                        v-for="environment in environments"
                        :key="environment.id"
                    >
                        <td>
                            <span class="inline-flex items-center gap-2"
                                ><EnvSwatch :color="environment.color" />{{
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
                                    color: pendingTone(
                                        environment.pending,
                                        thresholdOf(
                                            environment,
                                            'queue.pending',
                                        ),
                                    ),
                                }"
                            >
                                {{ formatCount(environment.pending) }}
                            </td>
                            <td
                                class="nc-right"
                                :style="{
                                    color: waitColor(
                                        environment.maxWaitSeconds,
                                        thresholdOf(
                                            environment,
                                            'queue.max_wait',
                                        ),
                                    ),
                                }"
                            >
                                {{ formatWait(environment.maxWaitSeconds) }}
                            </td>
                            <td
                                class="nc-right"
                                :style="{
                                    color: failedTone(
                                        environment.failedLastHour,
                                        thresholdOf(
                                            environment,
                                            'jobs.failed_per_hour',
                                        ),
                                    ),
                                }"
                            >
                                {{ formatCount(environment.failedInWindow)
                                }}<span
                                    v-if="windows.size > 1"
                                    style="
                                        margin-left: 4px;
                                        font-size: 10px;
                                        color: var(--nc-neutral-500);
                                    "
                                    >{{
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
                                {{ environment.jobsPerMinute }}
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
