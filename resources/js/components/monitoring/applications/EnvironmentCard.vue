<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    formatCount,
    formatWait,
    statusColor,
    statusLabel,
    waitColor,
} from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    card: App.Data.Pages.EnvironmentCardData;
}>();

const slug = useTeamSlug();
</script>

<template>
    <!-- An unwatched environment (listed by the viewer's permission,
         hidden by their visibility) has no detail page for them, so its
         card is not a link: see EnvironmentData::$watched. -->
    <component
        :is="card.environment.watched ? Link : 'div'"
        :href="
            card.environment.watched
                ? showEnvironment({
                      current_team: slug,
                      environment: card.environment.id,
                  })
                : undefined
        "
        class="env-card"
        :class="{ 'env-card-link': card.environment.watched }"
    >
        <EnvSwatch :color="card.environment.color" shape="edge" :size="3" />
        <span class="flex items-center gap-2">
            <span style="font-size: 16px">{{ card.environment.name }}</span>
            <StatusLamp :status="card.environment.status" />
            <span
                class="ml-auto"
                style="font-size: 11px"
                :style="{ color: statusColor(card.environment.status) }"
                >{{ statusLabel(card.environment.status) }}</span
            >
        </span>
        <span
            class="mt-[3px] block truncate"
            style="
                font-size: 11px;
                color: var(--nc-neutral-600);
                letter-spacing: 0.01em;
            "
        >
            {{ card.environment.horizonUrl.replace('https://', '') }}
        </span>
        <span
            v-if="!card.environment.watched"
            class="mt-[var(--nc-space-3)] flex items-center"
            style="height: 26px; font-size: 11px; color: var(--nc-neutral-500)"
            :title="
                $t(
                    'Your visibility does not cover this environment: no detail page and no chart, but you can still configure it.',
                )
            "
        >
            {{ $t('Not on your wall') }}
        </span>
        <span v-else class="mt-[var(--nc-space-3)] block">
            <TrendLine
                :values="card.sparkline"
                :width="190"
                :height="26"
                :color="
                    card.environment.status === 'active'
                        ? 'var(--nc-accent)'
                        : statusColor(card.environment.status)
                "
                fill
            />
        </span>
        <span
            class="nc-num mt-[var(--nc-space-2)] grid grid-cols-3"
            style="gap: var(--nc-space-2)"
        >
            <span>
                <span class="block" style="font-size: 16px">{{
                    formatCount(card.environment.pending)
                }}</span>
                <span
                    class="block"
                    style="
                        font-size: 9px;
                        letter-spacing: 0.08em;
                        text-transform: uppercase;
                        color: var(--nc-neutral-600);
                    "
                    >pending</span
                >
            </span>
            <span>
                <span
                    class="block"
                    style="font-size: 16px"
                    :style="{
                        color: waitColor(card.environment.maxWaitSeconds),
                    }"
                    >{{ formatWait(card.environment.maxWaitSeconds) }}</span
                >
                <span
                    class="block"
                    style="
                        font-size: 9px;
                        letter-spacing: 0.08em;
                        text-transform: uppercase;
                        color: var(--nc-neutral-600);
                    "
                    >max wait</span
                >
            </span>
            <span>
                <span class="block" style="font-size: 16px">{{
                    card.environment.nodeCount
                }}</span>
                <span
                    class="block"
                    style="
                        font-size: 9px;
                        letter-spacing: 0.08em;
                        text-transform: uppercase;
                        color: var(--nc-neutral-600);
                    "
                >
                    {{ $tChoice('node|nodes', card.environment.nodeCount) }}
                </span>
            </span>
        </span>
    </component>
</template>

<style scoped>
.env-card {
    position: relative;
    display: block;
    width: 100%;
    text-align: left;
    padding: var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.env-card-link:hover {
    box-shadow: var(--nc-shadow-md);
}
</style>
