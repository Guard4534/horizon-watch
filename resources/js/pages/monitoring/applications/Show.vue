<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhGearSix, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvironmentCard from '@/components/monitoring/applications/EnvironmentCard.vue';
import EnvironmentComparison from '@/components/monitoring/applications/EnvironmentComparison.vue';
import RecentAlerts from '@/components/monitoring/applications/RecentAlerts.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useLivePoll } from '@/composables/useLivePoll';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount, statusColor, statusLabel } from '@/lib/monitoring';
import {
    edit as editApplication,
    index as applicationsIndex,
} from '@/routes/applications';
import { create as createEnvironment } from '@/routes/environments';

defineOptions({
    layout: { title: 'Application detail', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.ApplicationDetailPageData;
}>();

useLivePoll(['page', 'openAlertCount']);

const slug = useTeamSlug();
const shared = usePage();
const isMobile = useIsMobile();

// Unwatched rows carry zeros, not readings: they are counted as
// environments and nothing else.
const watched = computed(() =>
    page.environments.filter((environment) => environment.status !== null),
);

const stats = computed(() => [
    {
        label: 'Environments',
        value: String(page.environments.length),
        color: 'var(--nc-text)',
    },
    {
        label: 'Nodes',
        value: String(
            watched.value.reduce(
                (sum, environment) => sum + environment.nodeCount,
                0,
            ),
        ),
        color: 'var(--nc-text)',
    },
    {
        label: 'Pending',
        value: formatCount(
            watched.value.reduce(
                (sum, environment) => sum + environment.pending,
                0,
            ),
        ),
        color: 'var(--nc-text)',
    },
    {
        label: 'Worst status',
        value: page.worstStatus ? statusLabel(page.worstStatus) : '—',
        color: page.worstStatus
            ? statusColor(page.worstStatus)
            : 'var(--nc-text)',
    },
]);
</script>

<template>
    <Head :title="page.application.name" />

    <!-- The mockup draws no phone version of this page: its grids already
         reflow at 360 px, and the table scrolls sideways. -->
    <div
        class="flex flex-col"
        :style="{
            padding: isMobile
                ? 'var(--nc-space-3) var(--nc-space-4)'
                : 'var(--nc-space-6)',
            gap: isMobile ? 'var(--nc-space-4)' : 'var(--nc-space-6)',
        }"
    >
        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0">
                    <div style="font-size: 11px; color: var(--nc-neutral-500)">
                        <Link :href="applicationsIndex(slug)">{{
                            $t('Applications')
                        }}</Link>
                    </div>
                    <div style="font-size: 26px; line-height: 1.15">
                        {{ page.application.name }}
                    </div>
                    <div
                        style="
                            font-size: 12px;
                            color: var(--nc-neutral-500);
                            letter-spacing: 0.01em;
                        "
                    >
                        {{ page.application.host }}
                    </div>
                </div>
                <!-- The ways into configuration that the removed
                     "Credentials & collection" box used to hold. -->
                <div
                    v-if="shared.props.canManageApplications"
                    class="ml-auto flex flex-wrap"
                    style="gap: var(--nc-space-2)"
                >
                    <Link
                        class="nc-btn nc-btn-secondary"
                        style="font-size: 12px"
                        :href="
                            createEnvironment({
                                current_team: slug,
                                application: page.application.id,
                            })
                        "
                    >
                        <PhPlus :size="13" />{{ $t('Add environment') }}
                    </Link>
                    <Link
                        class="nc-btn nc-btn-ghost"
                        style="font-size: 12px"
                        :href="
                            editApplication({
                                current_team: slug,
                                application: page.application.id,
                            })
                        "
                    >
                        <PhGearSix :size="14" />{{ $t('Edit application') }}
                    </Link>
                </div>
            </div>
            <div
                class="grid"
                style="
                    grid-template-columns: repeat(auto-fit, minmax(152px, 1fr));
                    gap: var(--nc-space-3);
                "
            >
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    style="
                        padding: var(--nc-space-3) var(--nc-space-4);
                        border-radius: var(--nc-radius-md);
                        background: var(--nc-surface);
                        box-shadow: var(--nc-shadow-sm);
                    "
                >
                    <!-- "Pending" is Horizon vocabulary: English in both
                         languages. -->
                    <div class="nc-label">
                        <template v-if="stat.label === 'Environments'">{{
                            $t('Environments')
                        }}</template>
                        <template v-else-if="stat.label === 'Nodes'">{{
                            $t('Nodes')
                        }}</template>
                        <template v-else-if="stat.label === 'Worst status'">{{
                            $t('Worst status')
                        }}</template>
                        <template v-else>Pending</template>
                    </div>
                    <div
                        class="nc-num"
                        style="
                            font-size: 26px;
                            line-height: 1.15;
                            margin-top: 4px;
                        "
                        :style="{ color: stat.color }"
                    >
                        {{ stat.value }}
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-2)">
            <div class="nc-label">{{ $t('Environments') }}</div>
            <div
                v-if="!page.environments.length"
                style="font-size: 12px; color: var(--nc-neutral-500)"
            >
                {{ $t('This application has no environment yet.') }}
            </div>
            <div
                v-else
                class="grid"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(230px, 1fr)
                    );
                    gap: var(--nc-space-3);
                "
            >
                <EnvironmentCard
                    v-for="environment in page.environments"
                    :key="environment.id"
                    :environment="environment"
                />
            </div>
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <EnvironmentComparison
                v-if="page.environments.length"
                :environments="page.environments"
                :failed-per-hour-threshold="page.failedPerHourThreshold"
            />
            <RecentAlerts :alerts="page.recentAlerts" />
        </div>
    </div>
</template>
