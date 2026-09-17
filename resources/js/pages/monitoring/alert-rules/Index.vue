<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhInfo, PhSlidersHorizontal } from '@phosphor-icons/vue';
import { computed } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import NotificationSettings from '@/components/monitoring/rules/NotificationSettings.vue';
import RuleRow from '@/components/monitoring/rules/RuleRow.vue';
import ScopeList from '@/components/monitoring/rules/ScopeList.vue';

defineOptions({
    layout: { title: 'Alert settings' },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertRulesPageData;
}>();

const organization = computed(() => page.scope === 'organization');

const shared = usePage();

// The organization scope counts every visible environment (see
// MonitoringRepository::ruleScopes): zero means there are no thresholds
// worth showing, since there is nothing they could apply to.
const nothingVisible = computed(
    () =>
        page.scopes.find((scope) => scope.id === 'organization')
            ?.environmentCount === 0,
);

// Restricted only means something is being kept from this member if the
// organization holds anything at all: a viewer limited to non-production
// in an empty organization has nothing hidden from them.
const somethingIsHidden = computed(
    () =>
        shared.props.visibilityRestricted &&
        shared.props.organizationHasEnvironments,
);
</script>

<template>
    <Head :title="$t('Alert settings')" />

    <div v-if="nothingVisible" style="padding: var(--nc-space-6)">
        <EmptyState
            :icon="PhSlidersHorizontal"
            :kicker="$t('Nothing to watch')"
            :title="$t('No thresholds yet')"
            :body="
                somethingIsHidden
                    ? $t(
                          'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                      )
                    : shared.props.canManageApplications
                      ? $t(
                            'Configure an application with at least one environment first: its thresholds can be reviewed here afterwards.',
                        )
                      : $t(
                            'Nothing is configured yet. An administrator of this organization has to add an application before anything shows up here.',
                        )
            "
        />
    </div>

    <div v-else class="rules-grid">
        <ScopeList :scopes="page.scopes" :current="page.scope" />

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <div class="read-only-note" role="note">
                <PhInfo :size="15" class="mt-px flex-none" />
                <div class="flex flex-col" style="gap: 4px">
                    <span>{{
                        $t(
                            'Thresholds are read-only for now: the defaults below already drive the anomalies.',
                        )
                    }}</span>
                    <span style="color: var(--nc-neutral-500)">{{
                        $t(
                            'Horizon inactive, endpoint unreachable and Horizon paused open an anomaly at the first reading in that state: their minutes are not applied yet.',
                        )
                    }}</span>
                    <span
                        v-if="!organization"
                        style="color: var(--nc-neutral-500)"
                        >{{
                            $t(
                                'The overrides shown here are examples: every environment is measured against the organization defaults until the next release.',
                            )
                        }}</span
                    >
                </div>
            </div>
            <section class="nc-card">
                <div
                    class="flex flex-wrap items-start"
                    style="
                        gap: var(--nc-space-3);
                        margin-bottom: var(--nc-space-4);
                    "
                >
                    <div class="min-w-0">
                        <div style="font-size: 17px">
                            {{
                                organization
                                    ? $t('Default thresholds')
                                    : $t('Override · :scope environments', {
                                          scope: page.scope,
                                      })
                            }}
                        </div>
                        <div
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            {{
                                organization
                                    ? $t(
                                          'Apply to every environment without an override',
                                      )
                                    : $t(
                                          'Override the organization defaults for these environments only',
                                      )
                            }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="nc-btn nc-btn-ghost ml-auto"
                        style="font-size: 12px"
                        disabled
                        :title="$t('Available soon')"
                    >
                        {{
                            organization
                                ? $t('Reset to recommended')
                                : $t('Remove all overrides')
                        }}
                    </button>
                </div>
                <div class="flex flex-col" style="gap: var(--nc-space-3)">
                    <RuleRow
                        v-for="rule in page.rules"
                        :key="rule.metric"
                        :rule="rule"
                        :organization-scope="organization"
                    />
                </div>
            </section>

            <NotificationSettings :settings="page.notifications" />
        </div>
    </div>
</template>

<style scoped>
.rules-grid {
    display: grid;
    align-items: start;
    padding: var(--nc-space-6);
    gap: var(--nc-space-6);
    grid-template-columns: 224px minmax(0, 1fr);
}

@media (max-width: 767px) {
    .rules-grid {
        grid-template-columns: minmax(0, 1fr);
        padding: var(--nc-space-4);
        gap: var(--nc-space-4);
    }
}

.read-only-note {
    display: flex;
    gap: var(--nc-space-2);
    align-items: flex-start;
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    background: color-mix(in srgb, var(--nc-accent) 12%, transparent);
    font-size: 12px;
    color: var(--nc-neutral-300);
    line-height: 1.45;
}
</style>
