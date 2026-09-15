<script setup lang="ts">
import { computed } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { isDown, statusColor } from '@/lib/monitoring';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const rows = computed(() => [
    { key: 'Endpoint', value: '/horizon/api', color: 'var(--nc-text)', translate: false },
    { key: 'Authentication', value: environment.basicAuthUser ? `Basic · ${environment.basicAuthUser}` : 'none', color: 'var(--nc-text)', translate: !environment.basicAuthUser },
    { key: 'Poll interval', value: '15s', color: 'var(--nc-text)', translate: false },
    { key: 'Last response', value: `${environment.latencyMs} ms`, color: 'var(--nc-text)', translate: false },
    { key: 'Last contact', value: isDown(environment.status) ? '14 min ago' : '2 s ago', color: statusColor(environment.status), translate: true },
    { key: 'Network', value: 'internal, no outside access', color: 'var(--nc-neutral-300)', translate: true },
]);

const snippet = computed(() =>
    [
        '# .env of the monitored application',
        'HORIZON_PATH=horizon',
        `HORIZON_BASIC_AUTH_USER=${environment.basicAuthUser ?? 'horizon-bot'}`,
        'HORIZON_BASIC_AUTH_PASSWORD=••••',
        '',
        '# allow the self-hosted panel',
        'HORIZON_ALLOWED_IPS=10.20.0.14',
    ].join('\n'),
);
</script>

<template>
    <SectionCard>
        <div class="mb-[var(--nc-space-3)] flex items-center gap-2">
            <span style="font-size: 14px">{{ $t('Connection') }}</span>
            <span style="font-size: 10px; padding: 2px 7px; border-radius: var(--nc-radius-sm); background: var(--nc-neutral-900); color: var(--nc-neutral-400)">
                {{ environment.basicAuthUser ? $t('basic auth') : $t('no auth') }}
            </span>
        </div>
        <div class="flex flex-col" style="gap: var(--nc-space-2); font-size: 12px; color: var(--nc-neutral-500)">
            <div v-for="row in rows" :key="row.key" class="flex gap-2">
                <span>{{ $t(row.key) }}</span>
                <span class="ml-auto" style="letter-spacing: 0.01em" :style="{ color: row.color }">{{ row.translate ? $t(row.value) : row.value }}</span>
            </div>
        </div>
        <div class="nc-label" style="font-size: 11px; margin: var(--nc-space-4) 0 var(--nc-space-2)">{{ $t('Configuration on the monitored app') }}</div>
        <pre
            class="m-0 whitespace-pre-wrap break-words"
            style="padding: var(--nc-space-3); border-radius: var(--nc-radius-sm); background: var(--nc-bg); border: 1px solid var(--nc-divider); font-family: var(--nc-font); font-size: 11px; line-height: 1.6; color: var(--nc-neutral-300)"
        >{{ snippet }}</pre>
        <div class="mt-[var(--nc-space-2)]" style="font-size: 11px; color: var(--nc-neutral-600)">
            {{ $t('The panel runs inside your network and calls /horizon/api every 15 seconds. Credentials never leave: no outside service needs to reach the applications.') }}
        </div>
    </SectionCard>
</template>
