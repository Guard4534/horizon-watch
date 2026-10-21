<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import ConnectionTest from '@/components/monitoring/applications/ConnectionTest.vue';
import {
    connectionIcon,
    connectionState,
    connectionTone,
} from '@/components/monitoring/applications/connection';
import type { ConnectionOutcome } from '@/components/monitoring/applications/connection';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import { formatSeconds } from '@/lib/monitoring';
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

const icon = computed(() => connectionIcon(outcome.value));
const tone = computed(() => connectionTone(outcome.value));
const state = computed(() => connectionState(outcome.value));

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
                <EnvPill :name="environment.name" :type="environment.type" />
                <span
                    class="ml-auto flex-none"
                    :style="{ fontSize: '11px', color: tone }"
                >
                    {{ state }}
                </span>
            </div>
            <div class="url">{{ environment.horizonUrl }}</div>
            <div class="nc-num nc-t-2xs nc-tone-faint" style="margin-top: 2px">
                {{
                    environment.basicAuthUser
                        ? `${$t('basic auth')} · ${environment.basicAuthUser}`
                        : $t('no auth')
                }}
                · {{ formatSeconds(environment.pollIntervalSeconds) }}
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
