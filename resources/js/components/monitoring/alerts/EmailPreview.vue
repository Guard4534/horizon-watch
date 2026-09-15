<script setup lang="ts">
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { formatRule } from '@/lib/alertRules';
import { formatCount } from '@/lib/monitoring';

const { alert } = defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();

const critical = computed(() => alert.severity === 'critical');
const accent = computed(() => (critical.value ? 'var(--st-down)' : 'var(--st-warn)'));
</script>

<template>
    <SectionCard :title="$t('Email preview')">
        <div style="border: 1px solid var(--nc-divider); border-radius: var(--nc-radius-sm); padding: var(--nc-space-3); font-size: 12px">
            <div class="nc-label">{{ $t('Subject') }}</div>
            <div style="margin: 3px 0 var(--nc-space-3)">
                [{{ critical ? $t('CRITICAL') : $t('WARNING') }}] {{ alert.applicationName }} · {{ alert.environmentName }} — {{ alert.metric }}
            </div>
            <div class="flex items-center gap-[9px]" style="margin-bottom: var(--nc-space-3)">
                <span class="h-[30px] w-[3px] flex-none rounded-[2px]" :style="{ background: accent }" />
                <div>
                    <div>{{ formatRule(alert.metric, alert.threshold, alert.unit) }}</div>
                    <div style="color: var(--nc-neutral-500); font-size: 11px">
                        {{ $t(':count jobs pending', { count: formatCount(alert.pending) }) }}
                    </div>
                </div>
            </div>
            <div style="color: var(--nc-neutral-400); line-height: 1.5">
                {{ critical
                    ? $tChoice('No active workers across :count node. Queues are not draining and the backlog keeps growing.|No active workers across :count nodes. Queues are not draining and the backlog keeps growing.', alert.nodeCount)
                    : $t('Jobs are being consumed, but the delay keeps growing past the configured threshold.') }}
            </div>
            <div class="flex flex-col" style="gap: 4px; margin: var(--nc-space-3) 0; font-size: 11px; color: var(--nc-neutral-500)">
                <div class="flex gap-2"><span>{{ $t('Environment') }}</span><span class="ml-auto" style="color: var(--nc-text)">{{ alert.environmentName }}</span></div>
                <div class="flex gap-2"><span>{{ $t('Nodes') }}</span><span class="ml-auto" style="color: var(--nc-text)">{{ alert.nodeCount }}</span></div>
                <div class="flex gap-2"><span>{{ $t('Rule') }}</span><span class="ml-auto" style="color: var(--nc-text)">{{ formatRule(alert.metric, alert.threshold, alert.unit) }}</span></div>
            </div>
            <span class="nc-btn nc-btn-primary" style="font-size: 12px">{{ $t('Open the panel') }}</span>
        </div>
    </SectionCard>
</template>
