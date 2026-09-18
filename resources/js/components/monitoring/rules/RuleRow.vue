<script setup lang="ts">
import { computed } from 'vue';
import InheritToggle from '@/components/monitoring/rules/InheritToggle.vue';
import type { RuleField, RuleValues } from '@/lib/alertRules';
import {
    formatThreshold,
    overriddenFieldCount,
    ruleHint,
    ruleIcon,
    ruleLabel,
    ruleValues,
    unitLabel,
} from '@/lib/alertRules';
import { severityColor, severityLabel } from '@/lib/alerts';

const {
    rule,
    inherited = null,
    editable,
    errors = {},
} = defineProps<{
    rule: App.Data.Monitoring.AlertRuleData;
    inherited?: App.Data.Monitoring.AlertRuleData | null;
    editable: boolean;
    errors?: Partial<Record<RuleField, string>>;
}>();

const fields = defineModel<App.Data.Alerts.AlertRuleInputData>({
    required: true,
});

const environmentScope = computed(() => inherited !== null);

const fallback = computed<RuleValues>(() => ruleValues(inherited ?? rule));

const hasOverride = computed(() =>
    editable
        ? environmentScope.value && overriddenFieldCount(fields.value) > 0
        : rule.origin === 'override',
);

const shown = computed<RuleValues>(() => {
    if (!editable) {
        return ruleValues(rule);
    }

    return {
        threshold: fields.value.threshold ?? fallback.value.threshold,
        severity: fields.value.severity ?? fallback.value.severity,
        notifyByEmail:
            fields.value.notifyByEmail ?? fallback.value.notifyByEmail,
        enabled: fields.value.enabled ?? fallback.value.enabled,
    };
});

function isInherited(field: RuleField): boolean {
    return environmentScope.value && fields.value[field] === null;
}

function update(patch: Partial<App.Data.Alerts.AlertRuleInputData>): void {
    fields.value = { ...fields.value, ...patch };
}

function toggleInheritance(field: RuleField): void {
    update({
        [field]: isInherited(field) ? fallback.value[field] : null,
    });
}

function readThreshold(event: Event): void {
    const raw = (event.target as HTMLInputElement).value;

    update({ threshold: raw === '' ? null : Number(raw) });
}
</script>

<template>
    <div
        class="rule-row"
        :class="{
            'is-editable': editable,
            inherits: editable && environmentScope,
        }"
    >
        <div class="min-w-0">
            <div
                class="flex flex-wrap items-center gap-2"
                style="font-size: 13px"
            >
                <component
                    :is="ruleIcon(rule.metric)"
                    :size="15"
                    class="flex-none"
                    style="color: var(--nc-neutral-400)"
                />
                <span :style="{ opacity: shown.enabled ? 1 : 0.55 }">{{
                    ruleLabel(rule.metric)
                }}</span>
                <span
                    v-if="hasOverride"
                    class="nc-tag nc-tag-sm nc-tag-accent flex-none"
                    >{{ $t('override') }}</span
                >
                <span
                    v-else-if="environmentScope"
                    class="nc-tag nc-tag-sm nc-tag-neutral flex-none"
                    >{{ $t('from org') }}</span
                >
            </div>
            <div
                class="mt-[2px]"
                style="font-size: 11px; color: var(--nc-neutral-600)"
            >
                {{ ruleHint(rule.metric) }}
            </div>
        </div>

        <template v-if="editable">
            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <input
                        type="number"
                        inputmode="numeric"
                        step="1"
                        class="nc-input nc-num"
                        :class="{ 'is-inherited': isInherited('threshold') }"
                        style="text-align: right"
                        :min="rule.minimum"
                        :max="rule.maximum"
                        :value="shown.threshold"
                        :disabled="isInherited('threshold')"
                        :aria-label="
                            $t(':rule threshold', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        :aria-invalid="errors.threshold ? true : undefined"
                        @input="readThreshold"
                    />
                    <span
                        class="flex-none"
                        style="font-size: 11px; color: var(--nc-neutral-500)"
                        >{{ unitLabel(rule.unit) }}</span
                    >
                    <InheritToggle
                        v-if="environmentScope"
                        :inherited="isInherited('threshold')"
                        @toggle="toggleInheritance('threshold')"
                    />
                </div>
                <div v-if="errors.threshold" class="field-error">
                    {{ errors.threshold }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <select
                        class="nc-input"
                        :class="{ 'is-inherited': isInherited('severity') }"
                        style="font-size: 12px"
                        :value="shown.severity"
                        :disabled="isInherited('severity')"
                        :aria-label="
                            $t(':rule severity', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        @change="
                            update({
                                severity: ($event.target as HTMLSelectElement)
                                    .value as App.Enums.AlertSeverity,
                            })
                        "
                    >
                        <option value="warning">{{ $t('Warning') }}</option>
                        <option value="critical">{{ $t('Critical') }}</option>
                    </select>
                    <InheritToggle
                        v-if="environmentScope"
                        :inherited="isInherited('severity')"
                        @toggle="toggleInheritance('severity')"
                    />
                </div>
                <div v-if="errors.severity" class="field-error">
                    {{ errors.severity }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <label
                        class="nc-radio check"
                        :class="{
                            'is-inherited': isInherited('notifyByEmail'),
                        }"
                        style="font-size: 12px"
                    >
                        <input
                            type="checkbox"
                            :checked="shown.notifyByEmail"
                            :disabled="isInherited('notifyByEmail')"
                            @change="
                                update({
                                    notifyByEmail: (
                                        $event.target as HTMLInputElement
                                    ).checked,
                                })
                            "
                        />
                        <span class="nc-dot" />
                        {{ $t('Email') }}
                    </label>
                    <InheritToggle
                        v-if="environmentScope"
                        :inherited="isInherited('notifyByEmail')"
                        @toggle="toggleInheritance('notifyByEmail')"
                    />
                </div>
                <div v-if="errors.notifyByEmail" class="field-error">
                    {{ errors.notifyByEmail }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <label
                        class="nc-radio check"
                        :class="{ 'is-inherited': isInherited('enabled') }"
                        style="font-size: 12px"
                    >
                        <input
                            type="checkbox"
                            role="switch"
                            :checked="shown.enabled"
                            :aria-checked="shown.enabled"
                            :disabled="isInherited('enabled')"
                            @change="
                                update({
                                    enabled: ($event.target as HTMLInputElement)
                                        .checked,
                                })
                            "
                        />
                        <span class="nc-dot" />
                        {{ $t('Active') }}
                    </label>
                    <InheritToggle
                        v-if="environmentScope"
                        :inherited="isInherited('enabled')"
                        @toggle="toggleInheritance('enabled')"
                    />
                </div>
                <div v-if="errors.enabled" class="field-error">
                    {{ errors.enabled }}
                </div>
            </div>
        </template>

        <template v-else>
            <div
                class="nc-num read-value"
                :style="{
                    color: shown.enabled
                        ? 'var(--nc-text)'
                        : 'var(--nc-neutral-500)',
                }"
            >
                {{ formatThreshold(shown.threshold, rule.unit) }}
            </div>
            <div
                class="read-value"
                :style="{ color: severityColor(shown.severity) }"
            >
                {{ severityLabel(shown.severity) }}
            </div>
            <div class="read-value" style="color: var(--nc-neutral-400)">
                {{ shown.notifyByEmail ? $t('Email') : $t('No email') }}
            </div>
            <div class="read-value" style="color: var(--nc-neutral-400)">
                {{ shown.enabled ? $t('Active') : $t('Disabled') }}
            </div>
        </template>
    </div>
</template>

<style scoped>
.rule-row {
    display: grid;
    align-items: center;
    grid-template-columns: minmax(0, 1fr) 104px 96px 80px 72px;
    gap: var(--nc-space-3);
    padding-bottom: var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.rule-row.is-editable {
    align-items: start;
    grid-template-columns: minmax(0, 1fr) 128px 122px 88px 88px;
}

.rule-row.is-editable.inherits {
    grid-template-columns: minmax(0, 1fr) 162px 150px 114px 114px;
}

.rule-row.is-editable > :first-child {
    padding-top: 6px;
}

.cell {
    min-width: 0;
}

.cell .nc-input {
    min-width: 0;
}

.check {
    min-height: 36px;
}

.is-inherited.nc-input:disabled {
    opacity: 1;
    border-style: dashed;
    color: var(--nc-neutral-500);
    background: transparent;
    cursor: default;
}

.check.is-inherited {
    color: var(--nc-neutral-500);
    cursor: default;
}

.check.is-inherited .nc-dot {
    border-style: dashed;
}

.check.is-inherited input:checked + .nc-dot {
    background: var(--nc-neutral-600);
    border-color: var(--nc-neutral-600);
}

.field-error {
    margin-top: 4px;
    font-size: 11px;
    line-height: 1.35;
    color: var(--st-down);
}

.read-value {
    font-size: 12px;
}

@media (max-width: 639px) {
    .rule-row,
    .rule-row.is-editable,
    .rule-row.is-editable.inherits {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .rule-row > :first-child {
        grid-column: 1 / -1;
    }
}
</style>
