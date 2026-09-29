<script lang="ts">
export type ConnectionOutcome =
    | { state: 'idle' }
    | { state: 'testing' }
    | { state: 'done'; result: App.Data.Monitoring.ConnectionResultData }
    | { state: 'throttled' }
    | { state: 'invalid'; message: string }
    | { state: 'failed' };
</script>

<script setup lang="ts">
import { http } from '@inertiajs/vue3';
import {
    PhCheckCircle,
    PhCircleNotch,
    PhHourglassMedium,
    PhPlugsConnected,
    PhWarning,
} from '@phosphor-icons/vue';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { horizonStatusLabel } from '@/components/monitoring/environment/readings';
import type { RouteDefinition } from '@/wayfinder';

const {
    url,
    payload,
    showButton = true,
    auto = false,
    disabled = false,
} = defineProps<{
    url: RouteDefinition<'post'>;
    payload: App.Data.Applications.TestConnectionData | null;
    showButton?: boolean;
    auto?: boolean;
    disabled?: boolean;
}>();

const outcome = defineModel<ConnectionOutcome>('outcome', {
    default: () => ({ state: 'idle' }),
});

let attempt = 0;

const failureOf = (error: unknown): ConnectionOutcome => {
    const response = (
        error as { response?: { status?: number; data?: string } } | null
    )?.response;

    if (response?.status === 429) {
        return { state: 'throttled' };
    }

    if (response?.status === 422) {
        try {
            const body = JSON.parse(response.data ?? '') as {
                errors?: Record<string, string[]>;
            };
            const message = Object.values(body.errors ?? {})[0]?.[0];

            if (message) {
                return { state: 'invalid', message };
            }
        } catch {}
    }

    return { state: 'failed' };
};

const run = async (): Promise<void> => {
    const current = ++attempt;
    outcome.value = { state: 'testing' };

    try {
        const response = await http.getClient().request({
            method: 'post',
            url: url.url,
            data: payload ?? undefined,
            headers: { Accept: 'application/json' },
        });

        if (current === attempt) {
            outcome.value = {
                state: 'done',
                result: JSON.parse(
                    response.data,
                ) as App.Data.Monitoring.ConnectionResultData,
            };
        }
    } catch (error) {
        if (current === attempt) {
            outcome.value = failureOf(error);
        }
    }
};

watch(
    () => JSON.stringify(payload),
    () => {
        attempt++;
        outcome.value = { state: 'idle' };
    },
);

onMounted(() => {
    if (auto && outcome.value.state === 'idle') {
        void run();
    }
});

onUnmounted(() => {
    attempt++;
});

defineExpose({ run });

const result = computed(() =>
    outcome.value.state === 'done' ? outcome.value.result : null,
);

const tone = computed(() => {
    switch (outcome.value.state) {
        case 'done':
            return outcome.value.result.reachable
                ? 'var(--st-ok)'
                : 'var(--st-down)';
        case 'testing':
            return 'var(--nc-neutral-400)';
        case 'throttled':
            return 'var(--st-warn)';
        default:
            return 'var(--st-down)';
    }
});

const icon = computed(() => {
    switch (outcome.value.state) {
        case 'testing':
            return PhCircleNotch;
        case 'throttled':
            return PhHourglassMedium;
        case 'done':
            return outcome.value.result.reachable ? PhCheckCircle : PhWarning;
        default:
            return PhWarning;
    }
});
</script>

<template>
    <div class="flex flex-wrap items-center" style="gap: 10px">
        <button
            v-if="showButton"
            type="button"
            class="nc-btn nc-btn-secondary nc-t-xs"
            :disabled="disabled || outcome.state === 'testing'"
            @click="run"
        >
            <PhPlugsConnected :size="13" />{{ $t('Test connection') }}
        </button>

        <div
            role="status"
            aria-live="polite"
            class="inline-flex min-w-0 items-center"
            :style="{ gap: '6px', fontSize: '12px', color: tone }"
        >
            <template v-if="outcome.state !== 'idle'">
                <component
                    :is="icon"
                    :size="14"
                    class="flex-none"
                    :class="{ 'animate-spin': outcome.state === 'testing' }"
                />
                <span v-if="outcome.state === 'testing'">{{
                    $t('Testing the connection…')
                }}</span>
                <span v-else-if="result?.reachable" class="nc-num">{{
                    $t(
                        'Connected · Horizon :status · :count masters · :ms ms',
                        {
                            status: result.horizonStatus
                                ? horizonStatusLabel(result.horizonStatus)
                                : '—',
                            count: String(result.masterCount ?? 0),
                            ms: String(result.latencyMs ?? 0),
                        },
                    )
                }}</span>
                <span v-else-if="result?.error === 'unauthorized'">{{
                    $t('Horizon refused the credentials')
                }}</span>
                <span v-else-if="result?.error === 'not_horizon'">{{
                    $t('The address does not answer like Horizon')
                }}</span>
                <span v-else-if="result?.error === 'blocked'">{{
                    $t('This address is not allowed')
                }}</span>
                <span v-else-if="result?.error === 'unreachable'">{{
                    $t('Horizon does not answer')
                }}</span>
                <span v-else-if="outcome.state === 'throttled'">{{
                    $t('Too many attempts. Wait a minute and try again.')
                }}</span>
                <span v-else-if="outcome.state === 'invalid'">{{
                    outcome.message
                }}</span>
                <span v-else>{{
                    $t('The test could not run. Reload the page and try again.')
                }}</span>
            </template>
        </div>
    </div>
</template>
