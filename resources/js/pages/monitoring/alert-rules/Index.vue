<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
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
</script>

<template>
    <Head :title="$t('Alert settings')" />

    <div class="grid items-start" style="padding: var(--nc-space-6); gap: var(--nc-space-6); grid-template-columns: 224px minmax(0, 1fr)">
        <ScopeList :scopes="page.scopes" :current="page.scope" />

        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <section class="nc-card">
                <div class="flex flex-wrap items-start" style="gap: var(--nc-space-3); margin-bottom: var(--nc-space-4)">
                    <div class="min-w-0">
                        <div style="font-size: 17px">
                            {{ organization ? $t('Default thresholds') : $t('Override · :scope environments', { scope: page.scope }) }}
                        </div>
                        <div style="font-size: 12px; color: var(--nc-neutral-500)">
                            {{ organization ? $t('Apply to every environment without an override') : $t('Override the organization defaults for these environments only') }}
                        </div>
                    </div>
                    <button type="button" class="nc-btn nc-btn-ghost ml-auto" style="font-size: 12px" disabled :title="$t('Available soon')">
                        {{ organization ? $t('Reset to recommended') : $t('Remove all overrides') }}
                    </button>
                </div>
                <div class="flex flex-col" style="gap: var(--nc-space-3)">
                    <RuleRow v-for="rule in page.rules" :key="rule.metric" :rule="rule" :organization-scope="organization" />
                </div>
            </section>

            <NotificationSettings :settings="page.notifications" />
        </div>
    </div>
</template>
