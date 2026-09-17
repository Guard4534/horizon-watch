<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    needsAttention,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const slug = useTeamSlug();
const tone = computed(() => statusTone(environment));

// The left bar is the environment's own colour, so status lives on the
// lamp, a tinted ground and a soft halo (as on the wall's tiles).
const cardStyle = computed(() =>
    needsAttention(environment)
        ? {
              background: `color-mix(in srgb, ${tone.value} 12%, var(--nc-surface))`,
              boxShadow: `var(--nc-shadow-sm), 0 0 0 4px color-mix(in srgb, ${tone.value} 16%, transparent)`,
          }
        : {},
);

// Without a status the counters are zeros that mean nothing: no reading
// yet, or a row whose reading the viewer may not see.
const hasNumbers = computed(() => environment.status !== null);
</script>

<template>
    <!-- An unwatched environment (listed by the viewer's permission,
         hidden by their visibility) has no detail page for them, so its
         card is not a link: see EnvironmentData::$watched. -->
    <component
        :is="environment.watched ? Link : 'div'"
        :href="
            environment.watched
                ? showEnvironment({
                      current_team: slug,
                      environment: environment.id,
                  })
                : undefined
        "
        class="env-card"
        :class="{ 'env-card-link': environment.watched }"
        :style="cardStyle"
    >
        <EnvSwatch :color="environment.color" shape="edge" :size="4" />
        <span class="flex items-center gap-2">
            <span class="min-w-0">
                <EnvPill
                    :name="environment.name"
                    :color="environment.color"
                    :size="13"
                />
            </span>
            <StatusLamp :status="environment.status" />
            <span
                class="ml-auto flex-none"
                style="font-size: 11px"
                :style="{ color: tone }"
                >{{ statusText(environment) }}</span
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
            {{ environment.horizonUrl.replace(/^https?:\/\//, '') }}
        </span>
        <span
            v-if="!environment.watched"
            class="mt-[var(--nc-space-3)] flex items-center"
            style="
                min-height: 26px;
                font-size: 11px;
                line-height: 1.35;
                color: var(--nc-neutral-500);
            "
        >
            {{
                $t(
                    'Your visibility does not cover this environment: no detail page and no chart, but you can still configure it.',
                )
            }}
        </span>
        <!-- The pending trend of the last hour, as on the wall. -->
        <span v-else class="mt-[var(--nc-space-3)] block">
            <TrendLine
                :values="environment.trend"
                :width="190"
                :height="26"
                :color="
                    environment.status === 'active' ||
                    environment.status === null
                        ? 'var(--nc-accent)'
                        : tone
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
                    hasNumbers ? formatCount(environment.pending) : '—'
                }}</span>
                <span class="figure-label">pending</span>
            </span>
            <span>
                <span
                    class="block"
                    style="font-size: 16px"
                    :style="{
                        color: hasNumbers
                            ? waitColor(environment.maxWaitSeconds)
                            : undefined,
                    }"
                    >{{
                        hasNumbers
                            ? formatWait(environment.maxWaitSeconds)
                            : '—'
                    }}</span
                >
                <span class="figure-label">max wait</span>
            </span>
            <span>
                <span class="block" style="font-size: 16px">{{
                    hasNumbers ? environment.nodeCount : '—'
                }}</span>
                <span class="figure-label">
                    {{ $tChoice('node|nodes', environment.nodeCount) }}
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

.figure-label {
    display: block;
    font-size: 9px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--nc-neutral-600);
}
</style>
