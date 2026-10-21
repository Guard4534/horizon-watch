<script setup lang="ts">
import { computed } from 'vue';
import {
    readingErrorLabel,
    readingProblemLabel,
} from '@/components/monitoring/environment/readings';
import { formatRule } from '@/lib/alertRules';
import { formatElapsed, statusColor, statusIcon } from '@/lib/monitoring';

const {
    status,
    readingError,
    readingProblem = null,
    alert,
    hasEarlierReading,
    compact = false,
} = defineProps<{
    status: Exclude<App.Enums.EnvironmentStatus, 'active'>;
    readingError: App.Enums.ReadingError | null;
    readingProblem?: App.Data.Monitoring.ReadingProblemData | null;
    alert: App.Data.Monitoring.AlertData | null;
    hasEarlierReading: boolean;
    compact?: boolean;
}>();

const color = computed(() => statusColor(status));
const icon = computed(() => statusIcon(status));

const since = computed(() => (alert ? formatElapsed(alert.minutesAgo) : null));
</script>

<template>
    <div
        class="flex items-start"
        :style="{
            gap: compact ? '10px' : '11px',
            padding: compact
                ? 'var(--nc-space-3)'
                : 'var(--nc-space-3) var(--nc-space-4)',
            borderRadius: 'var(--nc-radius-md)',
            border: `1px solid ${color}`,
            background: `color-mix(in srgb, ${color} 10%, transparent)`,
        }"
    >
        <component
            :is="icon"
            :size="compact ? 16 : 18"
            class="mt-[2px] flex-none"
            :style="{ color }"
        />
        <div class="min-w-0">
            <div
                :style="{
                    fontSize: compact ? '13px' : '14px',
                    lineHeight: 1.35,
                }"
            >
                <template v-if="status === 'unreachable'">{{
                    $t('Endpoint unreachable')
                }}</template>
                <template v-else-if="status === 'inactive'">{{
                    $t('Horizon inactive')
                }}</template>
                <template v-else-if="status === 'paused'">{{
                    $t('Horizon paused')
                }}</template>
                <template v-else-if="status === 'stale'">{{
                    $t('Readings are not updated')
                }}</template>
                <template v-else>{{ $t('Threshold exceeded') }}</template>
                <span v-if="since" class="nc-num nc-t-2xs nc-tone-muted">
                    · {{ $t('started :elapsed', { elapsed: since }) }}</span
                >
            </div>
            <div
                class="mt-[3px]"
                :style="{
                    fontSize: compact ? '11px' : '12px',
                    lineHeight: 1.45,
                    color: compact
                        ? 'var(--nc-neutral-400)'
                        : 'var(--nc-neutral-300)',
                }"
            >
                <template v-if="status === 'unreachable'">
                    {{ readingErrorLabel(readingError ?? 'unreachable') }}
                    <span
                        v-if="readingProblem"
                        class="[overflow-wrap:anywhere]"
                    >
                        · {{ readingProblemLabel(readingProblem) }}
                    </span>
                    <template v-if="hasEarlierReading">
                        ·
                        {{
                            $t(
                                'The counters read zero until it answers again; nodes and queues are the last known.',
                            )
                        }}
                    </template>
                </template>
                <template v-else-if="status === 'inactive'">{{
                    $t(
                        'No active master supervisor: jobs stay pending until one starts again.',
                    )
                }}</template>
                <template v-else-if="status === 'paused'">{{
                    $t(
                        'Horizon is paused: jobs stay pending until it is continued.',
                    )
                }}</template>
                <template v-else-if="status === 'stale'">{{
                    $t(
                        'No reading has arrived for several intervals: the scheduler or the workers of Horizon Watch may be stopped, and no alert is evaluated meanwhile. The figures are the last known.',
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'Jobs are being consumed, but at least one threshold is exceeded.',
                    )
                }}</template>
            </div>
            <div
                v-if="alert && !compact"
                class="nc-t-2xs nc-tone-muted mt-[5px]"
            >
                {{ $t('Rule:') }}
                <span :title="alert.metric">{{
                    formatRule(alert.metric, alert.threshold, alert.unit)
                }}</span>
            </div>
        </div>
    </div>
</template>
