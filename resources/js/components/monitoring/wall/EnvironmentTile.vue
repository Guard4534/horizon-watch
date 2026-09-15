<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envColor, formatCount, formatWait, pendingColor, statusColor, waitColor } from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const { environment } = defineProps<{
    environment: App.Data.Monitoring.EnvironmentData;
}>();

const slug = useTeamSlug();
const unhealthy = computed(() => environment.status !== 'active');
const load = computed(() => `${Math.min(100, Math.round(environment.pending / 40))}%`);
</script>

<template>
    <Link
        :href="showEnvironment({ current_team: slug, environment: environment.id })"
        class="tile"
        :style="{ boxShadow: unhealthy ? `0 0 0 1px ${statusColor(environment.status)}` : 'var(--nc-shadow-sm)' }"
    >
        <span class="absolute top-0 bottom-0 left-0 w-[3px]" :style="{ background: envColor(environment.color) }" />
        <span class="flex items-start gap-[7px]">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-size: 13px">{{ environment.name }}</span>
                <span class="block truncate" style="font-size: 11px; color: var(--nc-neutral-500)">{{ environment.applicationName }}</span>
            </span>
            <StatusLamp :status="environment.status" />
        </span>
        <span class="nc-num mt-[11px] flex items-baseline gap-[5px]">
            <span style="font-size: 19px; line-height: 1" :style="{ color: pendingColor(environment.pending) }">{{ formatCount(environment.pending) }}</span>
            <span style="font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--nc-neutral-600)">pending</span>
            <span class="ml-auto" style="font-size: 11px" :style="{ color: waitColor(environment.maxWaitSeconds) }">{{ formatWait(environment.maxWaitSeconds) }}</span>
        </span>
        <span class="mt-2 block h-[2px] overflow-hidden rounded-[2px]" style="background: var(--nc-neutral-900)">
            <span class="block h-[2px]" :style="{ width: load, background: statusColor(environment.status) }" />
        </span>
        <span class="mt-[7px] flex gap-2" style="font-size: 10px; color: var(--nc-neutral-600)">
            <span>{{ $tChoice(':count node|:count nodes', environment.nodeCount) }} · {{ environment.workers }} workers</span>
            <span class="ml-auto" :style="{ color: environment.failedLast24Hours > 20 ? 'var(--st-warn)' : 'var(--nc-neutral-600)' }">
                {{ environment.failedLast24Hours }} failed
            </span>
        </span>
    </Link>
</template>

<style scoped>
.tile {
    position: relative;
    display: block;
    width: 100%;
    text-align: left;
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3) var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.tile:hover {
    box-shadow: var(--nc-shadow-md) !important;
}
</style>
