<script setup lang="ts">
import { ruleHint, ruleIcon, ruleLabel } from '@/lib/alertRules';

const { rule, organizationScope } = defineProps<{
    rule: App.Data.Monitoring.AlertRuleData;
    organizationScope: boolean;
}>();
</script>

<template>
    <div class="rule-row">
        <div class="min-w-0">
            <div class="flex items-center gap-2" style="font-size: 13px">
                <component
                    :is="ruleIcon(rule.metric)"
                    :size="15"
                    class="flex-none"
                    style="color: var(--nc-neutral-400)"
                />
                {{ $t(ruleLabel(rule.metric)) }}
                <!-- "override" stays English in both languages: the mockup keeps
                     rule-origin badges untranslated on purpose. -->
                <span
                    v-if="rule.origin === 'override'"
                    class="nc-tag nc-tag-sm nc-tag-accent flex-none"
                    >override</span
                >
                <span
                    v-else-if="!organizationScope"
                    class="nc-tag nc-tag-sm nc-tag-neutral flex-none"
                    >{{ $t('from org') }}</span
                >
            </div>
            <div
                class="mt-[2px]"
                style="font-size: 11px; color: var(--nc-neutral-600)"
            >
                {{ $t(ruleHint(rule.metric)) }}
            </div>
        </div>
        <div class="flex items-center gap-[6px]">
            <input
                class="nc-input nc-num"
                style="text-align: right"
                :value="rule.threshold"
                disabled
                :title="$t('Available soon')"
            />
            <span
                class="flex-none"
                style="font-size: 11px; color: var(--nc-neutral-500)"
                >{{ rule.unit }}</span
            >
        </div>
        <select
            class="nc-input"
            style="font-size: 12px"
            :value="rule.severity ?? 'disabled'"
            disabled
            :title="$t('Available soon')"
        >
            <option value="warning">{{ $t('Warning') }}</option>
            <option value="critical">{{ $t('Critical') }}</option>
            <option value="disabled">{{ $t('Disabled') }}</option>
        </select>
        <label
            class="nc-radio"
            style="font-size: 12px"
            :title="$t('Available soon')"
        >
            <input type="checkbox" :checked="rule.notifyByEmail" disabled />
            <span class="nc-dot" />
            {{ $t('Email') }}
        </label>
    </div>
</template>

<style scoped>
.rule-row {
    display: grid;
    align-items: center;
    grid-template-columns: minmax(0, 1fr) 128px 122px 96px;
    gap: var(--nc-space-3);
    padding-bottom: var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

@media (max-width: 639px) {
    .rule-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .rule-row > :first-child {
        grid-column: 1 / -1;
    }
}
</style>
