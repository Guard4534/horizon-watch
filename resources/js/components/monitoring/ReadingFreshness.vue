<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    readingErrorLabel,
    secondsSince,
} from '@/components/monitoring/environment/readings';
import { formatSeconds } from '@/lib/monitoring';

const {
    lastReadingAt,
    stale,
    pollingEnabled,
    readingError,
    hideReason = false,
} = defineProps<{
    lastReadingAt: string | null;
    stale: boolean;
    pollingEnabled: boolean;
    readingError: App.Enums.ReadingError | null;
    hideReason?: boolean;
}>();

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);
});

onUnmounted(() => clearInterval(timer));

const age = computed(() =>
    lastReadingAt
        ? formatSeconds(secondsSince(lastReadingAt, now.value), 1)
        : null,
);

const tone = computed(() => {
    if (!pollingEnabled) {
        return 'var(--nc-neutral-400)';
    }

    if (hideReason) {
        return 'var(--nc-neutral-500)';
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
        <template v-else-if="readingError !== null && !hideReason">{{
            readingErrorLabel(readingError)
        }}</template>
        <template v-else-if="stale && !hideReason">{{
            $t('Not updated')
        }}</template>
        <template v-else-if="age">{{
            $t('Last reading :time ago', { time: age })
        }}</template>
        <template v-else>{{ $t('No reading yet') }}</template>
        <template
            v-if="
                age &&
                (!pollingEnabled ||
                    (!hideReason && (readingError !== null || stale)))
            "
        >
            ·
            <span class="nc-tone-muted">{{
                $t('last reading :time ago', { time: age })
            }}</span>
        </template>
    </span>
</template>
