<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import KpiCard from '@/components/nocturne/KpiCard.vue';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import {
    calmStyle,
    failedTone,
    needsAttention,
    pendingTone,
    statusTone,
    troubledStyle,
} from '@/components/monitoring/environment/readings';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { thresholdOf } from '@/lib/alertRules';
import { failedWindowNote, failedWindowShort } from '@/lib/failedWindow';
import {
    formatCount,
    formatWait,
    statusColor,
    statusLabel,
    waitColor,
} from '@/lib/monitoring';
import { show as showEnvironment } from '@/routes/environments';

const {
    rows,
    totalCount,
    problemCount,
    waitingCount,
    environmentName,
    search,
    kpis,
} = defineProps<{
    rows: App.Data.Monitoring.EnvironmentData[];
    totalCount: number;
    problemCount: number;
    waitingCount: number;
    environmentName: string;
    search: string;
    kpis: App.Data.Pages.WallKpisData;
}>();

const emit = defineEmits<{ clear: [] }>();

const filter = defineModel<'all' | 'problems'>('filter', { required: true });

const slug = useTeamSlug();

const narrowing = computed(() =>
    [environmentName, search.trim() ? `"${search.trim()}"` : '']
        .filter(Boolean)
        .join(' · '),
);

function rowStyle(environment: App.Data.Monitoring.EnvironmentData) {
    return needsAttention(environment)
        ? troubledStyle(statusTone(environment), 3)
        : calmStyle();
}
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-3)">
        <div class="flex" style="gap: var(--nc-space-2)">
            <KpiCard
                compact
                class="flex-1"
                :label="$t('Up')"
                :value="`${kpis.environmentsActive}/${kpis.environmentsTotal}`"
            />
            <KpiCard
                compact
                class="flex-1"
                :label="$t('Issues')"
                :value="String(problemCount)"
                :color="problemCount ? 'var(--st-down)' : 'var(--st-ok)'"
            />
            <KpiCard
                compact
                class="flex-1"
                :label="$t('Pending')"
                :value="formatCount(kpis.pendingTotal)"
            />
        </div>

        <SegmentedControl
            v-model="filter"
            name="mobile-wall-filter"
            class="nc-seg-full"
            :options="[
                {
                    value: 'problems',
                    label: `${$t('Problems')} ${problemCount}`,
                },
                { value: 'all', label: `${$t('All')} ${totalCount}` },
            ]"
        />

        <div v-if="narrowing" class="narrowing">
            <span class="min-w-0 truncate">{{
                $t('Filtered by :filters', { filters: narrowing })
            }}</span>
            <button
                type="button"
                class="nc-btn nc-btn-ghost nc-t-xs"
                @click="emit('clear')"
            >
                {{ $t('Clear filters') }}
            </button>
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-2)">
            <Link
                v-for="environment in rows"
                :key="environment.id"
                :href="
                    showEnvironment({
                        current_team: slug,
                        environment: environment.id,
                    })
                "
                class="row"
                :style="rowStyle(environment)"
            >
                <EnvSwatch :color="environment.color" shape="edge" :size="4" />
                <span class="flex items-center gap-[9px]">
                    <StatusLamp :status="environment.status" glow />
                    <span class="min-w-0 flex-1">
                        <span class="nc-t-sm block truncate">{{
                            environment.applicationName
                        }}</span>
                        <EnvPill
                            :name="environment.name"
                            :color="environment.color"
                            :size="11"
                            style="margin-top: 3px"
                        />
                    </span>
                    <span class="flex-none text-right">
                        <span
                            class="nc-num block"
                            style="font-size: 16px; line-height: 1.2"
                            :style="{
                                color: pendingTone(
                                    environment.pending,
                                    thresholdOf(environment, 'queue.pending'),
                                ),
                            }"
                            >{{ formatCount(environment.pending) }}</span
                        >
                        <span class="nc-micro nc-tone-faint block truncate">{{
                            $t('Pending')
                        }}</span>
                    </span>
                </span>
                <span
                    class="nc-num nc-t-2xs nc-tone-faint mt-2 flex items-center gap-2"
                >
                    <span
                        v-if="environment.status !== null"
                        :style="{ color: statusColor(environment.status) }"
                        >{{ statusLabel(environment.status) }}</span
                    >
                    <span v-else>{{ $t('No reading yet') }}</span>
                    <span
                        class="ml-auto"
                        :style="{
                            color: waitColor(
                                environment.maxWaitSeconds,
                                thresholdOf(environment, 'queue.max_wait'),
                            ),
                        }"
                        >{{
                            $t(':wait wait', {
                                wait: formatWait(environment.maxWaitSeconds),
                            })
                        }}</span
                    >
                    <span
                        :title="
                            failedWindowNote(environment.failedWindowMinutes)
                        "
                        :style="{
                            color: failedTone(
                                environment.failedLastHour,
                                thresholdOf(
                                    environment,
                                    'jobs.failed_per_hour',
                                ),
                            ),
                        }"
                        >{{
                            $t(':count failed', {
                                count: String(environment.failedInWindow),
                            })
                        }}
                        ·
                        {{
                            failedWindowShort(environment.failedWindowMinutes)
                        }}</span
                    >
                </span>
            </Link>
            <p
                v-if="rows.length === 0"
                class="nc-t-xs nc-tone-muted"
                style="
                    margin: 0;
                    padding: var(--nc-space-4) 0;
                    text-align: center;
                "
            >
                <template v-if="narrowing">{{
                    $t('No environment matches these filters.')
                }}</template>
                <template v-else-if="waitingCount > 0">{{
                    $t(
                        'Nothing to handle. Some environments are still waiting for their first reading.',
                    )
                }}</template>
                <template v-else>{{
                    $t('Nothing to handle: every environment is running.')
                }}</template>
            </p>
        </div>
    </div>
</template>

<style scoped>
.narrowing {
    display: flex;
    align-items: center;
    gap: var(--nc-space-2);
    font-size: 12px;
    color: var(--nc-neutral-400);
}

.row {
    position: relative;
    display: block;
    width: 100%;
    padding: var(--nc-space-3) var(--nc-space-3) var(--nc-space-3)
        var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    color: inherit;
    text-decoration: none;
    overflow: hidden;
}

.row:hover {
    box-shadow: var(--nc-shadow-md) !important;
}
</style>
