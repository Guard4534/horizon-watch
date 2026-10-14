<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandMark from '@/components/nocturne/BrandMark.vue';
import { useLastRefresh } from '@/composables/useLastRefresh';
import { useLiveConnection } from '@/composables/useLiveConnection';
import { useRefreshInterval } from '@/composables/useRefreshInterval';

const {
    title,
    subtitle,
    live = false,
} = defineProps<{
    title?: string;
    subtitle?: string;
    live?: boolean;
}>();

const page = usePage();
const updatedAt = useLastRefresh();
const { connected } = useLiveConnection();
const connectionLabel = computed(() =>
    connected.value
        ? trans('Live updates are working')
        : trans('Live updates are failing: the figures may be out of date'),
);
const secondLine = computed(
    () => subtitle ?? page.props.currentTeam?.name ?? '',
);

const { interval, options, set } = useRefreshInterval();

function label(ms: number): string {
    return ms < 60000 ? `${ms / 1000}s` : `${ms / 60000} min`;
}

function choose(event: Event): void {
    set(Number((event.target as HTMLSelectElement).value));
}
</script>

<template>
    <header class="bar">
        <BrandMark class="phone-only" :size="20" :with-name="false" />
        <div class="min-w-0">
            <h1 class="title truncate">
                {{ title ? $t(title) : '' }}
            </h1>
            <div class="subtitle truncate">
                {{ subtitle ? $t(subtitle) : secondLine }}
            </div>
        </div>
        <div class="ml-auto flex items-center" style="gap: var(--nc-space-3)">
            <span
                v-if="live"
                class="nc-num nc-t-2xs nc-tone-soft inline-flex items-center gap-[7px]"
            >
                <span
                    class="size-[6px] flex-none rounded-full"
                    :class="{ 'live-dot-on': connected }"
                    :style="{
                        background: connected
                            ? 'var(--st-ok)'
                            : 'var(--st-down)',
                    }"
                    role="img"
                    :aria-label="connectionLabel"
                    :title="connectionLabel"
                />
                <label class="inline-flex items-center gap-[7px]">
                    <span class="phone-hidden-words">{{
                        $t('refresh every')
                    }}</span>
                    <select
                        class="nc-input nc-num interval"
                        :value="interval"
                        @change="choose"
                    >
                        <option
                            v-for="option in options"
                            :key="option"
                            :value="option"
                        >
                            {{ label(option) }}
                        </option>
                    </select>
                </label>
                <span v-if="connected" class="desktop-only">{{
                    $t('· updated :time', { time: updatedAt })
                }}</span>
                <span
                    v-else
                    class="desktop-only"
                    style="color: var(--st-down)"
                    >{{
                        $t('· not updating since :time', { time: updatedAt })
                    }}</span
                >
            </span>
        </div>
    </header>
</template>

<style scoped>
.bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--nc-space-4);
    padding: var(--nc-space-4) var(--nc-space-6);
    border-bottom: 1px solid var(--nc-divider);
}

.title {
    margin: 0;
    font-size: 20px;
    letter-spacing: -0.015em;
}

.subtitle {
    font-size: 12px;
    color: var(--nc-neutral-500);
}

.live-dot-on {
    animation: nc-pulse 2.4s ease-in-out infinite;
}

.interval {
    width: auto;
    min-height: 0;
    padding: 3px 22px 3px 8px;
    font-size: 11px;
    line-height: 1.4;
    color: var(--nc-neutral-300);
    background-color: transparent;
    background-position: right 6px center;
    background-size: 10px 10px;
    border-radius: var(--nc-radius-sm);
}
.interval:hover {
    color: var(--nc-text);
}

.phone-only {
    display: none;
}

@media (max-width: 639px) {
    .bar {
        flex-wrap: nowrap;
        gap: var(--nc-space-2);
        padding: var(--nc-space-3) var(--nc-space-4);
    }

    .title {
        font-size: 14px;
        letter-spacing: 0;
    }

    .subtitle {
        display: none;
    }

    .phone-only {
        display: inline-flex;
    }

    .desktop-only {
        display: none;
    }

    .phone-hidden-words {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
}
</style>
