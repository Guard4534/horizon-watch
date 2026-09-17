<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    formatAge,
    secondsSince,
} from '@/components/monitoring/environment/readings';

const { lastReadingAt, stale, pollingEnabled, readingError } = defineProps<{
    lastReadingAt: string | null;
    stale: boolean;
    pollingEnabled: boolean;
    readingError: App.Enums.ReadingError | null;
}>();

// The age moves between two polls, so it is counted here rather than
// taken from the server.
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);
});

onUnmounted(() => clearInterval(timer));

const age = computed(() =>
    lastReadingAt ? formatAge(secondsSince(lastReadingAt, now.value)) : null,
);

// The first state that explains the numbers wins; the age follows it when
// there is one, so "why" and "how old" are read together.
const tone = computed(() => {
    if (!pollingEnabled) {
        return 'var(--nc-neutral-400)';
    }

    if (readingError) {
        return 'var(--st-down)';
    }

    return stale ? 'var(--st-warn)' : 'var(--nc-neutral-500)';
});
</script>

<template>
    <span class="nc-num" :style="{ color: tone }">
        <template v-if="!pollingEnabled">{{
            $t('Collection paused')
        }}</template>
        <template v-else-if="readingError === 'unauthorized'">{{
            $t('Horizon refused the credentials')
        }}</template>
        <template v-else-if="readingError === 'not_horizon'">{{
            $t('The address does not answer like Horizon')
        }}</template>
        <template v-else-if="readingError === 'blocked'">{{
            $t('This address is not allowed')
        }}</template>
        <template v-else-if="readingError === 'unreachable'">{{
            $t('Horizon does not answer')
        }}</template>
        <template v-else-if="stale">{{ $t('Not updated') }}</template>
        <template v-else-if="age">{{
            $t('Last reading :time ago', { time: age })
        }}</template>
        <template v-else>{{ $t('No reading yet') }}</template>
        <template
            v-if="age && (!pollingEnabled || readingError !== null || stale)"
        >
            ·
            <span style="color: var(--nc-neutral-500)">{{
                $t('last reading :time ago', { time: age })
            }}</span>
        </template>
    </span>
</template>
