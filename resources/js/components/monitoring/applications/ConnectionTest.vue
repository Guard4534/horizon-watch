<script setup lang="ts">
import { http } from '@inertiajs/vue3';
import { PhPlugsConnected, PhWarning } from '@phosphor-icons/vue';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import {
    connectionIcon,
    connectionMessage,
    connectionTone,
} from '@/components/monitoring/applications/connection';
import type { ConnectionOutcome } from '@/components/monitoring/applications/connection';
import { readingProblemLabel } from '@/components/monitoring/environment/readings';
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

const emit = defineEmits<{
    run: [];
}>();

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
    emit('run');

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

const tone = computed(() => connectionTone(outcome.value));
const icon = computed(() => connectionIcon(outcome.value));
const message = computed(() => connectionMessage(outcome.value));
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
            <template v-if="message !== null">
                <component
                    :is="icon"
                    :size="14"
                    class="flex-none"
                    :class="{ 'animate-spin': outcome.state === 'testing' }"
                />
                <span :class="{ 'nc-num': result?.reachable }">{{
                    message
                }}</span>
                <span
                    v-if="result?.problem"
                    class="min-w-0 [overflow-wrap:anywhere]"
                    >· {{ readingProblemLabel(result.problem) }}</span
                >
            </template>
        </div>

        <div
            v-if="result?.suggestedUrl"
            class="nc-t-2xs w-full [overflow-wrap:anywhere]"
            style="color: var(--nc-neutral-300)"
        >
            {{
                $t('This looks like the address of a Horizon page; try :url', {
                    url: result.suggestedUrl,
                })
            }}
        </div>

        <div
            v-if="result?.credentialsInClear"
            class="nc-t-2xs flex w-full items-start"
            style="gap: 5px; color: var(--st-warn)"
        >
            <PhWarning :size="12" class="mt-[1px] flex-none" />
            {{
                $t(
                    'The basic-auth password travels unencrypted over http://. Use https:// unless the network in between is trusted.',
                )
            }}
        </div>
    </div>
</template>
