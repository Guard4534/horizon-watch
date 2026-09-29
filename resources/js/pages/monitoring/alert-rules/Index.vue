<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PhInfo } from '@phosphor-icons/vue';
import { computed } from 'vue';
import NotificationSettings from '@/components/monitoring/rules/NotificationSettings.vue';
import RulesForm from '@/components/monitoring/rules/RulesForm.vue';
import ScopeList from '@/components/monitoring/rules/ScopeList.vue';
import { useVisibility } from '@/composables/useVisibility';

defineOptions({
    layout: { title: 'Alert settings' },
});

const { page } = defineProps<{
    page: App.Data.Pages.AlertRulesPageData;
}>();

const { somethingIsHidden } = useVisibility();

const currentScope = computed(() =>
    page.scopes.find((scope) => scope.id === page.scope),
);

const nothingVisible = computed(
    () =>
        page.scopes.find((scope) => scope.id === 'organization')
            ?.environmentCount === 0,
);
</script>

<template>
    <Head :title="$t('Alert settings')" />

    <div class="rules-grid">
        <ScopeList :scopes="page.scopes" :current="page.scope" />

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <div
                v-if="nothingVisible"
                class="nc-card nc-t-xs nc-tone-soft nothing-visible"
            >
                <PhInfo :size="15" class="mt-[2px] flex-none" />
                <span>{{
                    somethingIsHidden
                        ? $t(
                              'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                          )
                        : $t(
                              'No environment yet: the organization rules below apply to every environment from its first reading.',
                          )
                }}</span>
            </div>

            <RulesForm
                :key="page.scope"
                :scope="page.scope"
                :rules="page.rules"
                :organization-rules="page.organizationRules"
                :can-manage="page.canManage"
                :override-count="currentScope?.overrideCount ?? 0"
            />

            <NotificationSettings
                :summary="page.notificationSummary"
                :settings="page.notifications"
                :new-webhook-secret="page.newWebhookSecret"
                :repeat-choices="page.repeatChoices"
                :max-recipients="page.maxRecipients"
                :timezones="page.timezones"
            />
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

.nothing-visible {
    display: flex;
    gap: var(--nc-space-2);
    line-height: 1.5;
}

@media (max-width: 767px) {
    .rules-grid {
        grid-template-columns: minmax(0, 1fr);
        padding: var(--nc-space-4);
        gap: var(--nc-space-4);
    }
}
</style>
