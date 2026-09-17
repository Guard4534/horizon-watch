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

const envSnippet = computed(() =>
    [
        '# .env',
        `HORIZON_PATH=${path.value.replace(/^\/+/, '') || 'horizon'}`,
    ].join('\n'),
);

const gateSnippet = computed(() =>
    [
        '// app/Providers/HorizonServiceProvider.php',
        "Gate::define('viewHorizon', fn ($user = null) =>",
        environment.basicAuthUser
            ? `    request()->getUser() === '${environment.basicAuthUser.replace(/['\\]/g, '\\$&')}'`
            : "    request()->ip() === '<panel-ip>'",
        ');',
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
        <pre class="snippet m-0 break-words whitespace-pre-wrap">{{
            envSnippet
        }}</pre>
        <div
            style="
                font-size: 11px;
                color: var(--nc-neutral-500);
                margin: var(--nc-space-2) 0;
            "
        >
            {{
                environment.basicAuthUser
                    ? $t(
                          'Outside the local environment Horizon admits only who passes the viewHorizon gate: with the dashboard behind basic auth middleware, let the panel’s user through in the app’s HorizonServiceProvider.',
                      )
                    : $t(
                          'Outside the local environment Horizon admits only who passes the viewHorizon gate: let the panel through by its IP address in the app’s HorizonServiceProvider.',
                      )
            }}
        </div>
        <pre class="snippet m-0 break-words whitespace-pre-wrap">{{
            gateSnippet
        }}</pre>
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

<style scoped>
.snippet {
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-sm);
    background: var(--nc-bg);
    border: 1px solid var(--nc-divider);
    font-family: var(--nc-font);
    letter-spacing: 0.01em;
    font-size: 11px;
    line-height: 1.6;
    color: var(--nc-neutral-300);
}
</style>
