<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    hasMeasurement,
    needsAttention,
    pendingTone,
    statusText,
    statusTone,
    troubledStyle,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import TrendLine from '@/components/nocturne/TrendLine.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { thresholdOf } from '@/lib/alertRules';
import { formatCount, formatWait, waitColor } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const slug = useTeamSlug();
const tone = computed(() => statusTone(environment));

const cardStyle = computed(() =>
    needsAttention(environment) ? troubledStyle(tone.value) : {},
);

const hasNumbers = computed(() => hasMeasurement(environment));
</script>

<template>
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
        class="nc-card env-card"
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
            <span class="nc-t-2xs ml-auto flex-none" :style="{ color: tone }">{{
                statusText(environment)
            }}</span>
        </span>
        <span
            class="nc-t-2xs nc-tone-faint mt-[3px] block truncate"
            style="letter-spacing: 0.01em"
        >
            {{ environment.horizonUrl.replace(/^https?:\/\//, '') }}
        </span>
        <span
            v-if="!environment.watched"
            class="nc-t-2xs nc-tone-muted mt-[var(--nc-space-3)] flex items-center"
            style="min-height: 26px; line-height: 1.35"
        >
            {{
                $t(
                    'Your visibility does not cover this environment: no detail page and no chart, but you can still configure it.',
                )
            }}
        </span>
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
                <span
                    class="block"
                    style="font-size: 16px"
                    :style="{
                        color: hasNumbers
                            ? pendingTone(
                                  environment.pending,
                                  thresholdOf(environment, 'queue.pending'),
                              )
                            : undefined,
                    }"
                    >{{
                        hasNumbers ? formatCount(environment.pending) : '—'
                    }}</span
                >
                <span class="nc-micro nc-tone-faint block">{{
                    $t('Pending')
                }}</span>
            </span>
            <span>
                <span
                    class="block"
                    style="font-size: 16px"
                    :style="{
                        color: hasNumbers
                            ? waitColor(
                                  environment.maxWaitSeconds,
                                  thresholdOf(environment, 'queue.max_wait'),
                              )
                            : undefined,
                    }"
                    >{{
                        hasNumbers
                            ? formatWait(environment.maxWaitSeconds)
                            : '—'
                    }}</span
                >
                <span class="nc-micro nc-tone-faint block">{{
                    $t('Max wait')
                }}</span>
            </span>
            <span>
                <span class="block" style="font-size: 16px">{{
                    hasNumbers ? environment.nodeCount : '—'
                }}</span>
                <span class="nc-micro nc-tone-faint block">
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
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.env-card-link:hover {
    box-shadow: var(--nc-shadow-md);
}
</style>
