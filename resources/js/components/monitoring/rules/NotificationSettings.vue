<script setup lang="ts">
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineProps<{
    summary: App.Data.Pages.NotificationSummaryData;
    settings: App.Data.Monitoring.NotificationSettingsData | null;
}>();
</script>

<template>
    <SectionCard :title="$t('Notifications')">
        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(214px, 1fr));
                gap: var(--nc-space-4);
            "
        >
            <div class="nc-field">
                <label>{{ $t('Email recipients') }}</label>
                <input
                    class="nc-input"
                    :value="
                        settings
                            ? settings.recipients.join(', ')
                            : $tChoice(
                                  ':count recipient|:count recipients',
                                  summary.recipientCount,
                              )
                    "
                    disabled
                    :title="$t('Available soon')"
                />
                <div
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{ $t('in addition to organization admins') }}
                </div>
            </div>
            <div class="nc-field">
                <label>{{ $t('Webhook') }}</label>
                <input
                    class="nc-input"
                    :value="
                        settings
                            ? (settings.webhookUrl ?? '')
                            : summary.webhookConfigured
                              ? $t('Webhook configured')
                              : $t('No webhook')
                    "
                    disabled
                    :title="$t('Available soon')"
                />
                <div
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{ $t('JSON POST with severity, rule and metrics') }}
                </div>
            </div>
            <div class="nc-field">
                <label>{{ $t('Quiet hours') }}</label>
                <div class="flex items-center gap-2">
                    <input
                        class="nc-input"
                        style="width: 78px"
                        :value="summary.quietFrom ?? ''"
                        disabled
                        :title="$t('Available soon')"
                    />
                    <span style="font-size: 12px; color: var(--nc-neutral-500)"
                        >→</span
                    >
                    <input
                        class="nc-input"
                        style="width: 78px"
                        :value="summary.quietTo ?? ''"
                        disabled
                        :title="$t('Available soon')"
                    />
                </div>
                <div
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{ $t('criticals still get through') }}
                </div>
            </div>
            <div class="nc-field">
                <label>{{ $t('Alert repeat') }}</label>
                <select
                    class="nc-input"
                    :value="summary.repeatMinutes ?? 0"
                    disabled
                    :title="$t('Available soon')"
                >
                    <option :value="30">
                        {{ $t('every 30 min while open') }}
                    </option>
                    <option :value="15">
                        {{ $t('every 15 min while open') }}
                    </option>
                    <option :value="0">{{ $t('first detection only') }}</option>
                </select>
                <div
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{ $t('criticals only') }}
                </div>
            </div>
        </div>
        <div
            class="flex justify-end"
            style="gap: var(--nc-space-2); margin-top: var(--nc-space-4)"
        >
            <button
                type="button"
                class="nc-btn nc-btn-secondary"
                disabled
                :title="$t('Available soon')"
            >
                {{ $t('Cancel') }}
            </button>
            <button
                type="button"
                class="nc-btn nc-btn-primary"
                disabled
                :title="$t('Available soon')"
            >
                {{ $t('Save') }}
            </button>
        </div>
    </SectionCard>
</template>
