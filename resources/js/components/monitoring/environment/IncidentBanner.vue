<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { formatAge } from '@/components/monitoring/environment/readings';
import { formatRule } from '@/lib/alertRules';
import { statusColor, statusIcon } from '@/lib/monitoring';

const {
    status,
    readingError,
    alert,
    hasEarlierReading,
    compact = false,
} = defineProps<{
    // Only a status that needs attention: the page decides when to show it.
    status: Exclude<App.Enums.EnvironmentStatus, 'active'>;
    readingError: App.Enums.ReadingError | null;
    // The environment's first open anomaly; null when the reading has not
    // lasted long enough to be one, or its start is unknown.
    alert: App.Data.Monitoring.AlertData | null;
    // Whether anything was ever read: an environment that never answered
    // has no last known detail to point at.
    hasEarlierReading: boolean;
    compact?: boolean;
}>();

const color = computed(() => statusColor(status));
const icon = computed(() => statusIcon(status));

const since = computed(() => {
    if (!alert) {
        return null;
    }

    return alert.sinceTruncated
        ? trans('more than 24 h')
        : formatAge(alert.minutesAgo * 60);
});
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
                    since
                        ? $t('Endpoint unreachable for :time', { time: since })
                        : $t('Endpoint unreachable')
                }}</template>
                <template v-else-if="status === 'inactive'">{{
                    since
                        ? $t('No active master supervisor for :time', {
                              time: since,
                          })
                        : $t('No active master supervisor')
                }}</template>
                <template v-else-if="status === 'paused'">{{
                    since
                        ? $t('Horizon paused for :time', { time: since })
                        : $t('Horizon paused')
                }}</template>
                <template v-else>{{
                    since
                        ? $t('Threshold exceeded for :time', { time: since })
                        : $t('Threshold exceeded')
                }}</template>
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
                    <template v-if="readingError === 'unauthorized'">{{
                        $t('Horizon refused the credentials')
                    }}</template>
                    <template v-else-if="readingError === 'not_horizon'">{{
                        $t('The address does not answer like Horizon')
                    }}</template>
                    <template v-else-if="readingError === 'blocked'">{{
                        $t('This address is not allowed')
                    }}</template>
                    <template v-else>{{
                        $t('Horizon does not answer')
                    }}</template>
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
                        'No active workers: jobs stay pending until a master supervisor starts again.',
                    )
                }}</template>
                <template v-else-if="status === 'paused'">{{
                    $t(
                        'Horizon is paused: jobs stay pending until it is continued.',
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'Jobs are being consumed, but at least one default threshold is exceeded.',
                    )
                }}</template>
            </div>
            <div
                v-if="alert && !compact"
                class="mt-[5px]"
                style="font-size: 11px; color: var(--nc-neutral-500)"
            >
                {{ $t('Rule:') }}
                {{ formatRule(alert.metric, alert.threshold, alert.unit) }}
            </div>
        </div>
    </div>
</template>
