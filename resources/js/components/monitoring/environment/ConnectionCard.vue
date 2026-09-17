<script setup lang="ts">
import { computed } from 'vue';
import ReadingFreshness from '@/components/monitoring/ReadingFreshness.vue';
import { formatInterval } from '@/components/monitoring/environment/readings';
import SectionCard from '@/components/nocturne/SectionCard.vue';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const path = computed(() => {
    try {
        return new URL(environment.horizonUrl).pathname.replace(/\/+$/, '');
    } catch {
        return '';
    }
});

const endpoint = computed(() => `${path.value}/api`);
const interval = computed(() =>
    formatInterval(environment.pollIntervalSeconds),
);

const snippet = computed(() =>
    [
        '# .env of the monitored application',
        `HORIZON_PATH=${path.value.replace(/^\/+/, '') || 'horizon'}`,
        ...(environment.basicAuthUser
            ? [
                  `HORIZON_BASIC_AUTH_USER=${environment.basicAuthUser}`,
                  'HORIZON_BASIC_AUTH_PASSWORD=••••',
              ]
            : []),
        '',
        '# allow the self-hosted panel',
        'HORIZON_ALLOWED_IPS=<panel-ip>',
    ].join('\n'),
);
</script>

<template>
    <SectionCard>
        <div class="mb-[var(--nc-space-3)] flex items-center gap-2">
            <span style="font-size: 14px">{{ $t('Connection') }}</span>
            <span
                style="
                    font-size: 10px;
                    padding: 2px 7px;
                    border-radius: var(--nc-radius-sm);
                    background: var(--nc-neutral-900);
                    color: var(--nc-neutral-400);
                "
            >
                {{
                    environment.basicAuthUser ? $t('basic auth') : $t('no auth')
                }}
            </span>
        </div>
        <dl
            class="m-0 flex flex-col"
            style="
                gap: var(--nc-space-2);
                font-size: 12px;
                color: var(--nc-neutral-500);
            "
        >
            <div class="flex gap-2">
                <dt>Endpoint</dt>
                <dd
                    class="m-0 ml-auto min-w-0 truncate text-right"
                    style="letter-spacing: 0.01em; color: var(--nc-text)"
                    :title="endpoint"
                >
                    {{ endpoint }}
                </dd>
            </div>
            <div class="flex gap-2">
                <dt>{{ $t('Authentication') }}</dt>
                <dd
                    class="m-0 ml-auto min-w-0 truncate text-right"
                    style="letter-spacing: 0.01em; color: var(--nc-text)"
                >
                    {{
                        environment.basicAuthUser
                            ? `Basic · ${environment.basicAuthUser}`
                            : $t('none')
                    }}
                </dd>
            </div>
            <div class="flex gap-2">
                <dt>{{ $t('Poll interval') }}</dt>
                <dd
                    class="nc-num m-0 ml-auto text-right"
                    :style="{
                        color: environment.pollingEnabled
                            ? 'var(--nc-text)'
                            : 'var(--nc-neutral-400)',
                    }"
                >
                    {{
                        environment.pollingEnabled
                            ? interval
                            : $t('Collection paused')
                    }}
                </dd>
            </div>
            <div class="flex gap-2">
                <dt>{{ $t('Last response') }}</dt>
                <dd
                    class="nc-num m-0 ml-auto text-right"
                    style="color: var(--nc-text)"
                >
                    {{
                        environment.latencyMs === null
                            ? '—'
                            : `${environment.latencyMs} ms`
                    }}
                </dd>
            </div>
            <div class="flex gap-2">
                <dt class="flex-none">{{ $t('Last contact') }}</dt>
                <dd class="m-0 ml-auto min-w-0 text-right">
                    <ReadingFreshness
                        :last-reading-at="environment.lastReadingAt"
                        :stale="environment.stale"
                        :polling-enabled="environment.pollingEnabled"
                        :reading-error="environment.readingError"
                    />
                </dd>
            </div>
            <div class="flex gap-2">
                <dt>{{ $t('Network') }}</dt>
                <dd
                    class="m-0 ml-auto text-right"
                    style="color: var(--nc-neutral-300)"
                >
                    {{ $t('internal, no outside access') }}
                </dd>
            </div>
        </dl>
        <div
            class="nc-label"
            style="
                font-size: 11px;
                margin: var(--nc-space-4) 0 var(--nc-space-2);
            "
        >
            {{ $t('Configuration on the monitored app') }}
        </div>
        <pre
            class="m-0 break-words whitespace-pre-wrap"
            style="
                padding: var(--nc-space-3);
                border-radius: var(--nc-radius-sm);
                background: var(--nc-bg);
                border: 1px solid var(--nc-divider);
                font-family: var(--nc-font);
                letter-spacing: 0.01em;
                font-size: 11px;
                line-height: 1.6;
                color: var(--nc-neutral-300);
            "
            >{{ snippet }}</pre>
        <div
            class="mt-[var(--nc-space-2)]"
            style="font-size: 11px; color: var(--nc-neutral-600)"
        >
            {{
                environment.pollingEnabled
                    ? $t(
                          'The panel runs inside your network and reads :endpoint every :interval. Credentials never leave: no outside service needs to reach the applications.',
                          { endpoint, interval },
                      )
                    : $t(
                          'Collection is paused: the panel does not contact this environment until it is switched back on in its settings.',
                      )
            }}
        </div>
    </SectionCard>
</template>
