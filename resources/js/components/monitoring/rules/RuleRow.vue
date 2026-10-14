<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { RuleField, RuleValues } from '@/lib/alertRules';
import {
    RULE_FIELDS,
    formatDuration,
    formatThreshold,
    overriddenFieldCount,
    ruleHint,
    ruleIcon,
    ruleLabel,
    ruleValues,
    unitLabel,
} from '@/lib/alertRules';
import { severityColor, severityLabel } from '@/lib/alerts';
import { errorId, invalidField } from '@/lib/fieldErrors';

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

const id = useId();

const environmentScope = computed(() => inherited !== null);

const fallback = computed<RuleValues>(() => ruleValues(inherited ?? rule));

const opened = ref(false);

watch(
    () => rule,
    () => (opened.value = false),
);

const customized = computed(
    () =>
        environmentScope.value &&
        (opened.value || overriddenFieldCount(fields.value) > 0),
);

const locked = computed(() => environmentScope.value && !customized.value);

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
        forMinutes: fields.value.forMinutes ?? fallback.value.forMinutes,
    };
});

function update(patch: Partial<App.Data.Alerts.AlertRuleInputData>): void {
    fields.value = { ...fields.value, ...patch };
}

function toggleCustomized(): void {
    if (!customized.value) {
        opened.value = true;

        return;
    }

    opened.value = false;
    update(
        Object.fromEntries(
            RULE_FIELDS.map((field) => [field, null]),
        ) as Partial<App.Data.Alerts.AlertRuleInputData>,
    );
}

function readNumber(event: Event): number | null {
    const raw = (event.target as HTMLInputElement).value;

    return raw === '' ? null : Number(raw);
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
            <div class="nc-t-sm flex flex-wrap items-center gap-2">
                <component
                    :is="ruleIcon(rule.metric)"
                    :size="15"
                    class="nc-tone-soft flex-none"
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
            <div class="nc-t-2xs nc-tone-faint mt-[2px]">
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
                        class="nc-input nc-num nc-right"
                        :class="{ 'is-inherited': locked }"
                        :min="rule.minimum"
                        :max="rule.maximum"
                        :value="shown.threshold"
                        :disabled="locked"
                        :aria-label="
                            $t(':rule threshold', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        v-bind="
                            invalidField(`${id}-threshold`, errors.threshold)
                        "
                        @input="update({ threshold: readNumber($event) })"
                    />
                    <span class="nc-t-2xs nc-tone-muted flex-none">{{
                        unitLabel(rule.unit)
                    }}</span>
                </div>
                <div
                    v-if="errors.threshold"
                    :id="errorId(`${id}-threshold`)"
                    class="field-error"
                    role="alert"
                >
                    {{ errors.threshold }}
                </div>
                <div
                    v-if="shown.forMinutes !== null"
                    class="mt-[6px] flex items-center gap-[6px]"
                    :title="
                        $t(
                            'How long the threshold must stay exceeded before the alert opens',
                        )
                    "
                >
                    <span class="nc-t-2xs nc-tone-muted flex-none">{{
                        $t('for')
                    }}</span>
                    <input
                        type="number"
                        inputmode="numeric"
                        step="1"
                        min="0"
                        class="nc-input nc-num nc-right"
                        :class="{ 'is-inherited': locked }"
                        :max="rule.maximumForMinutes"
                        :value="shown.forMinutes"
                        :disabled="locked"
                        :aria-label="
                            $t(':rule duration', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        v-bind="
                            invalidField(`${id}-forMinutes`, errors.forMinutes)
                        "
                        @input="update({ forMinutes: readNumber($event) })"
                    />
                    <span class="nc-t-2xs nc-tone-muted flex-none">{{
                        unitLabel('min')
                    }}</span>
                </div>
                <div
                    v-if="errors.forMinutes"
                    :id="errorId(`${id}-forMinutes`)"
                    class="field-error"
                    role="alert"
                >
                    {{ errors.forMinutes }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <select
                        class="nc-input nc-t-xs"
                        :class="{ 'is-inherited': locked }"
                        :value="shown.severity"
                        :disabled="locked"
                        :aria-label="
                            $t(':rule severity', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        v-bind="invalidField(`${id}-severity`, errors.severity)"
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
                </div>
                <div
                    v-if="errors.severity"
                    :id="errorId(`${id}-severity`)"
                    class="field-error"
                    role="alert"
                >
                    {{ errors.severity }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <label
                        class="nc-switch check nc-t-xs"
                        :class="{ 'is-inherited': locked }"
                    >
                        <input
                            type="checkbox"
                            role="switch"
                            :checked="shown.notifyByEmail"
                            :aria-checked="shown.notifyByEmail"
                            :disabled="locked"
                            v-bind="
                                invalidField(
                                    `${id}-notifyByEmail`,
                                    errors.notifyByEmail,
                                )
                            "
                            @change="
                                update({
                                    notifyByEmail: (
                                        $event.target as HTMLInputElement
                                    ).checked,
                                })
                            "
                        />
                        <span class="nc-track" />
                        {{ $t('Email') }}
                    </label>
                </div>
                <div
                    v-if="errors.notifyByEmail"
                    :id="errorId(`${id}-notifyByEmail`)"
                    class="field-error"
                    role="alert"
                >
                    {{ errors.notifyByEmail }}
                </div>
            </div>

            <div class="cell">
                <div class="flex items-center gap-[6px]">
                    <label
                        class="nc-switch check nc-t-xs"
                        :class="{ 'is-inherited': locked }"
                    >
                        <input
                            type="checkbox"
                            role="switch"
                            :checked="shown.enabled"
                            :aria-checked="shown.enabled"
                            :disabled="locked"
                            v-bind="
                                invalidField(`${id}-enabled`, errors.enabled)
                            "
                            @change="
                                update({
                                    enabled: ($event.target as HTMLInputElement)
                                        .checked,
                                })
                            "
                        />
                        <span class="nc-track" />
                        {{ $t('Active') }}
                    </label>
                </div>
                <div
                    v-if="errors.enabled"
                    :id="errorId(`${id}-enabled`)"
                    class="field-error"
                    role="alert"
                >
                    {{ errors.enabled }}
                </div>
            </div>

            <div v-if="environmentScope" class="cell">
                <label class="nc-switch check nc-t-xs">
                    <input
                        type="checkbox"
                        role="switch"
                        :checked="customized"
                        :aria-checked="customized"
                        :aria-label="
                            $t('Customize :rule for this scope', {
                                rule: ruleLabel(rule.metric),
                            })
                        "
                        @change="toggleCustomized"
                    />
                    <span class="nc-track" />
                    {{ $t('Customize') }}
                </label>
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
                <div v-if="shown.forMinutes" class="nc-t-2xs nc-tone-muted">
                    {{ formatDuration(shown.forMinutes) }}
                </div>
            </div>
            <div
                class="read-value"
                :style="{ color: severityColor(shown.severity) }"
            >
                {{ severityLabel(shown.severity) }}
            </div>
            <div class="read-value nc-tone-soft">
                {{ shown.notifyByEmail ? $t('Email') : $t('No email') }}
            </div>
            <div class="read-value nc-tone-soft">
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
    grid-template-columns: minmax(0, 1fr) 128px 122px 88px 88px 112px;
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

.check.is-inherited input:checked + .nc-track {
    background: var(--nc-neutral-700);
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
