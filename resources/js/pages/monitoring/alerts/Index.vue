<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { PhBellSimpleSlash } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import AlertTable from '@/components/monitoring/alerts/AlertTable.vue';
import EmailPreview from '@/components/monitoring/alerts/EmailPreview.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import SegmentedControl from '@/components/nocturne/SegmentedControl.vue';

defineOptions({
    layout: { title: 'Alerts', live: true },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertLogPageData;
}>();

usePoll(15000, { only: ['page', 'openAlertCount'] });

const shared = usePage();

// No visible environment at all: the three tabs and the delivery policy have
// nothing to describe, so the page is just the explanation.
const nothingVisible = computed(() => page.environmentCount === 0);

const state = computed({
    get: () => page.state,
    set: (next: App.Enums.AlertState) =>
        // ReloadOptions omits preserveScroll/preserveState from Inertia 3's Visit type: reload() already
        // preserves both, so the flag from the brief is redundant and does not type-check here.
        router.reload({ data: { state: next }, only: ['page'] }),
});

const search = ref('');

const alerts = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? page.alerts.filter((alert) =>
              `${alert.applicationName} ${alert.environmentName} ${alert.metric}`
                  .toLowerCase()
                  .includes(needle),
          )
        : page.alerts;
});
</script>

<template>
    <Head :title="$t('Alerts')" />

    <div v-if="nothingVisible" style="padding: var(--nc-space-6)">
        <EmptyState
            :icon="PhBellSimpleSlash"
            :kicker="$t('Nothing to watch')"
            :title="$t('No alerts yet')"
            :body="
                shared.props.canManageApplications
                    ? $t(
                          'Configure an application with at least one environment first: alerts appear as soon as there is something to watch.',
                      )
                    : $t(
                          'No environment is visible to you yet. An administrator of this organization can widen your visibility or configure an application.',
                      )
            "
        />
    </div>

    <div
        v-else
        class="grid items-start"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            grid-template-columns: minmax(0, 1fr) 300px;
        "
    >
        <section class="nc-card">
            <div
                class="mb-[var(--nc-space-3)] flex flex-wrap items-center"
                style="gap: var(--nc-space-3)"
            >
                <SegmentedControl
                    v-model="state"
                    name="alert-state"
                    :options="[
                        {
                            value: 'open',
                            label: `${$t('Open alerts')} ${page.counts.open}`,
                        },
                        {
                            value: 'muted',
                            label: `${$t('Muted alerts')} ${page.counts.muted}`,
                        },
                        {
                            value: 'resolved',
                            label: `${$t('Resolved alerts')} ${page.counts.resolved}`,
                        },
                    ]"
                />
                <input
                    v-model="search"
                    class="nc-input ml-auto"
                    style="max-width: 230px"
                    :placeholder="$t('Filter by application')"
                />
            </div>
            <AlertTable :alerts="alerts" />
        </section>

        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <EmailPreview v-if="page.preview" :alert="page.preview" />
            <SectionCard :title="$t('Delivery policy')">
                <div style="font-size: 12px; color: var(--nc-neutral-400)">
                    {{
                        $t(
                            'A critical alert repeats every 30 minutes until it clears or gets muted. Warnings are grouped into a digest every 15 minutes. During quiet hours only criticals get through.',
                        )
                    }}
                </div>
            </SectionCard>
        </div>
    </div>
</template>
