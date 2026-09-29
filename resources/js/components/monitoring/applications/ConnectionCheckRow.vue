<script setup lang="ts">
import {
    PhCheckCircle,
    PhCircleNotch,
    PhMinusCircle,
    PhWarning,
} from '@phosphor-icons/vue';
import type { Component } from 'vue';
import { computed, useTemplateRef } from 'vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import type { ConnectionOutcome } from '@/components/monitoring/applications/ConnectionTest.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import type { RouteDefinition } from '@/wayfinder';

const { environment, auto = false } = defineProps<{
    environment: App.Data.Applications.EnvironmentFormData;
    url: RouteDefinition<'post'>;
    payload: App.Data.Applications.TestConnectionData;
    auto?: boolean;
}>();

const outcome = defineModel<ConnectionOutcome>('outcome', { required: true });

const test = useTemplateRef('test');

const testing = computed(() => outcome.value.state === 'testing');

const reachable = computed(
    () => outcome.value.state === 'done' && outcome.value.result.reachable,
);

const icon = computed<Component>(() => {
    if (outcome.value.state === 'testing') {
        return PhCircleNotch;
    }

    if (outcome.value.state !== 'done') {
        return PhMinusCircle;
    }

    return reachable.value ? PhCheckCircle : PhWarning;
});

const tone = computed(() => {
    if (outcome.value.state !== 'done') {
        return 'var(--nc-neutral-500)';
    }

    return reachable.value ? 'var(--st-ok)' : 'var(--st-warn)';
});

const refusedCredentials = computed(
    () =>
        outcome.value.state === 'done' &&
        outcome.value.result.error === 'unauthorized',
);

defineExpose({
    run: async (): Promise<void> => {
        await test.value?.run();
    },
});
</script>

<template>
    <div class="nc-card check">
        <component
            :is="icon"
            :size="16"
            class="mt-[2px] flex-none"
            :class="{ 'animate-spin': testing }"
            :style="{ color: tone }"
        />
        <div class="min-w-0 flex-1">
            <div class="flex items-center" style="gap: 8px">
                <EnvPill :name="environment.name" :color="environment.color" />
                <span
                    class="ml-auto flex-none"
                    :style="{ fontSize: '11px', color: tone }"
                >
                    <template v-if="testing">{{ $t('Testing…') }}</template>
                    <template v-else-if="reachable">{{
                        $t('Connected')
                    }}</template>
                    <template v-else-if="outcome.state === 'done'">{{
                        $t('Not connected')
                    }}</template>
                    <template v-else>{{ $t('Not tested') }}</template>
                </span>
            </div>
            <div class="url">{{ environment.horizonUrl }}</div>
            <div class="nc-num nc-t-2xs nc-tone-faint" style="margin-top: 2px">
                {{
                    environment.basicAuthUser
                        ? `${$t('basic auth')} · ${environment.basicAuthUser}`
                        : $t('no auth')
                }}
                · {{ environment.pollIntervalSeconds }} {{ $t('seconds') }}
                <template v-if="!environment.pollingEnabled">
                    · {{ $t('Collection paused') }}</template
                >
            </div>
            <ConnectionTest
                ref="test"
                v-model:outcome="outcome"
                class="mt-[6px]"
                :url="url"
                :payload="payload"
                :show-button="false"
                :auto="auto"
            />
            <div
                v-if="refusedCredentials"
                class="nc-t-2xs nc-tone-muted"
                style="margin-top: 2px"
            >
                {{
                    $t(
                        'The endpoint asks for credentials: go back and fill them in.',
                    )
                }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: var(--nc-space-3);
}

.url {
    margin-top: 4px;
    overflow: hidden;
    font-size: 11px;
    letter-spacing: 0.01em;
    color: var(--nc-neutral-500);
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
