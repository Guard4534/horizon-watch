<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { PhEnvelopeSimple, PhWebhooksLogo } from '@phosphor-icons/vue';
import { computed, ref, useId, watch } from 'vue';
import WebhookSecret from '@/components/monitoring/rules/WebhookSecret.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    test as testNotification,
    update as updateSettings,
} from '@/routes/alert-settings';

const { summary, settings, newWebhookSecret, repeatChoices, maxRecipients } =
    defineProps<{
        summary: App.Data.Pages.NotificationSummaryData;
        settings: App.Data.Monitoring.NotificationSettingsData | null;
        newWebhookSecret: string | null;
        repeatChoices: number[];
        maxRecipients: number;
    }>();

const slug = useTeamSlug();
const id = useId();

type SettingsForm = {
    recipients: string;
    webhookUrl: string;
    quietFrom: string;
    quietTo: string;
    timezone: string;
    repeatMinutes: number | null;
};

function received(): SettingsForm {
    return {
        recipients: settings?.recipients.join(', ') ?? '',
        webhookUrl: settings?.webhookUrl ?? '',
        quietFrom: settings?.quietFrom ?? '',
        quietTo: settings?.quietTo ?? '',
        timezone: settings?.timezone ?? summary.timezone,
        repeatMinutes: settings?.repeatMinutes ?? null,
    };
}

const form = useForm<SettingsForm>(received());

watch(
    () => settings,
    () => {
        form.defaults(received());
        form.reset();
        form.clearErrors();
    },
);

function splitRecipients(value: string): string[] {
    return value
        .split(/[\s,;]+/)
        .map((address) => address.trim())
        .filter((address) => address !== '');
}

const submitted = ref<string[]>([]);

function save(): void {
    submitted.value = splitRecipients(form.recipients);

    form.transform((data): App.Data.Alerts.NotificationSettingsInputData => ({
        recipients: splitRecipients(data.recipients),
        webhookUrl:
            data.webhookUrl.trim() === '' ? null : data.webhookUrl.trim(),
        quietFrom: data.quietFrom === '' ? null : data.quietFrom,
        quietTo: data.quietTo === '' ? null : data.quietTo,
        timezone: data.timezone.trim(),
        repeatMinutes: data.repeatMinutes,
    })).put(updateSettings(slug.value).url, { preserveScroll: true });
}

function cancel(): void {
    form.reset();
    form.clearErrors();
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const recipientErrors = computed(() => {
    const messages: string[] = [];

    if (errors.value.recipients) {
        messages.push(errors.value.recipients);
    }

    for (const [key, message] of Object.entries(errors.value)) {
        const match = /^recipients\.(\d+)$/.exec(key);

        if (match && message) {
            const address = submitted.value[Number(match[1])];

            messages.push(address ? `${address}: ${message}` : message);
        }
    }

    return messages;
});

const testing = ref<App.Enums.NotificationChannel | null>(null);
const testError = ref<string | null>(null);

function sendTest(channel: App.Enums.NotificationChannel): void {
    testError.value = null;

    router.post(
        testNotification(slug.value).url,
        { channel },
        {
            preserveScroll: true,
            onStart: () => (testing.value = channel),
            onFinish: () => (testing.value = null),
            onError: (bag) => (testError.value = bag.channel ?? null),
        },
    );
}

const webhookSaved = computed(
    () => settings !== null && (settings.webhookUrl ?? '') !== '',
);
</script>

<template>
    <SectionCard :title="$t('Notifications')">
        <form v-if="settings" novalidate @submit.prevent="save">
            <div class="settings-grid">
                <div class="nc-field wide">
                    <label :for="`${id}-recipients`">{{
                        $t('Email recipients')
                    }}</label>
                    <input
                        :id="`${id}-recipients`"
                        v-model="form.recipients"
                        class="nc-input"
                        type="text"
                        inputmode="email"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="ops@example.com, oncall@example.com"
                        :aria-invalid="
                            recipientErrors.length ? true : undefined
                        "
                    />
                    <div
                        v-for="message in recipientErrors"
                        :key="message"
                        class="field-error"
                    >
                        {{ message }}
                    </div>
                    <div class="field-hint">
                        {{
                            $t(
                                'Comma-separated, up to :count addresses, in addition to the members who turned on alert emails',
                                { count: String(maxRecipients) },
                            )
                        }}
                    </div>
                </div>

                <div class="nc-field wide">
                    <label :for="`${id}-webhook`">{{ $t('Webhook') }}</label>
                    <input
                        :id="`${id}-webhook`"
                        v-model="form.webhookUrl"
                        class="nc-input nc-code"
                        type="url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://hooks.example.com/horizon"
                        :aria-invalid="errors.webhookUrl ? true : undefined"
                    />
                    <div v-if="errors.webhookUrl" class="field-error">
                        {{ errors.webhookUrl }}
                    </div>
                    <div class="field-hint">
                        {{ $t('JSON POST with severity, rule and metrics') }}
                    </div>
                </div>

                <div class="nc-field">
                    <label :for="`${id}-quiet-from`">{{
                        $t('Quiet hours')
                    }}</label>
                    <div class="flex items-center gap-2">
                        <input
                            :id="`${id}-quiet-from`"
                            v-model="form.quietFrom"
                            class="nc-input nc-num"
                            style="width: 84px; text-align: center"
                            type="text"
                            inputmode="numeric"
                            maxlength="5"
                            autocomplete="off"
                            placeholder="23:00"
                            :aria-label="$t('Quiet hours start')"
                            :aria-invalid="errors.quietFrom ? true : undefined"
                        />
                        <span
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                            >→</span
                        >
                        <input
                            v-model="form.quietTo"
                            class="nc-input nc-num"
                            style="width: 84px; text-align: center"
                            type="text"
                            inputmode="numeric"
                            maxlength="5"
                            autocomplete="off"
                            placeholder="07:00"
                            :aria-label="$t('Quiet hours end')"
                            :aria-invalid="errors.quietTo ? true : undefined"
                        />
                    </div>
                    <div v-if="errors.quietFrom" class="field-error">
                        {{ errors.quietFrom }}
                    </div>
                    <div v-if="errors.quietTo" class="field-error">
                        {{ errors.quietTo }}
                    </div>
                    <div class="field-hint">
                        {{ $t('criticals still get through') }}
                    </div>
                </div>

                <div class="nc-field">
                    <label :for="`${id}-timezone`">{{ $t('Time zone') }}</label>
                    <input
                        :id="`${id}-timezone`"
                        v-model="form.timezone"
                        class="nc-input"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :list="`${id}-timezones`"
                        :aria-invalid="errors.timezone ? true : undefined"
                    />
                    <datalist :id="`${id}-timezones`">
                        <option
                            v-for="zone in settings.timezones"
                            :key="zone"
                            :value="zone"
                        />
                    </datalist>
                    <div v-if="errors.timezone" class="field-error">
                        {{ errors.timezone }}
                    </div>
                    <div class="field-hint">
                        {{ $t('the quiet hours follow this time zone') }}
                    </div>
                </div>

                <div class="nc-field">
                    <label :for="`${id}-repeat`">{{
                        $t('Alert repeat')
                    }}</label>
                    <select
                        :id="`${id}-repeat`"
                        v-model="form.repeatMinutes"
                        class="nc-input"
                        :aria-invalid="errors.repeatMinutes ? true : undefined"
                    >
                        <option
                            v-for="minutes in repeatChoices"
                            :key="minutes"
                            :value="minutes"
                        >
                            {{
                                $t('every :minutes min while open', {
                                    minutes: String(minutes),
                                })
                            }}
                        </option>
                        <option :value="null">
                            {{ $t('first detection only') }}
                        </option>
                    </select>
                    <div v-if="errors.repeatMinutes" class="field-error">
                        {{ errors.repeatMinutes }}
                    </div>
                    <div class="field-hint">{{ $t('criticals only') }}</div>
                </div>
            </div>

            <div
                v-if="
                    (webhookSaved && settings.webhookSecretSet) ||
                    newWebhookSecret !== null
                "
                class="flex flex-col"
                style="margin-top: var(--nc-space-4); gap: var(--nc-space-2)"
            >
                <WebhookSecret
                    :secret-set="settings.webhookSecretSet"
                    :new-secret="newWebhookSecret"
                />
                <div class="signature">
                    <div style="color: var(--nc-neutral-500)">
                        {{
                            $t(
                                'Each delivery is signed with HMAC SHA-256 over the timestamp, a dot and the raw body:',
                            )
                        }}
                    </div>
                    <code class="nc-code"
                        >X-Horizon-Watch-Timestamp: 1758189600</code
                    >
                    <code class="nc-code"
                        >X-Horizon-Watch-Signature: sha256=HMAC(timestamp + "."
                        + body)</code
                    >
                </div>
            </div>

            <div
                class="flex flex-wrap items-center"
                style="gap: var(--nc-space-2); margin-top: var(--nc-space-4)"
            >
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    style="font-size: 12px"
                    :disabled="testing !== null || form.isDirty"
                    :title="
                        form.isDirty
                            ? $t(
                                  'Save first: the test uses the saved settings.',
                              )
                            : undefined
                    "
                    @click="sendTest('mail')"
                >
                    <PhEnvelopeSimple :size="13" />
                    {{ $t('Send a test email') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    style="font-size: 12px"
                    :disabled="
                        testing !== null || form.isDirty || !webhookSaved
                    "
                    :title="
                        form.isDirty
                            ? $t(
                                  'Save first: the test uses the saved settings.',
                              )
                            : undefined
                    "
                    @click="sendTest('webhook')"
                >
                    <PhWebhooksLogo :size="13" />
                    {{ $t('Send to webhook') }}
                </button>
                <div class="ml-auto flex" style="gap: var(--nc-space-2)">
                    <button
                        type="button"
                        class="nc-btn nc-btn-secondary"
                        :disabled="!form.isDirty || form.processing"
                        @click="cancel"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="nc-btn nc-btn-primary"
                        :disabled="!form.isDirty || form.processing"
                    >
                        {{ $t('Save') }}
                    </button>
                </div>
            </div>
            <div v-if="testError" class="field-error" role="alert">
                {{ testError }}
            </div>
        </form>

        <div
            v-else
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: var(--nc-space-4);
                font-size: 13px;
            "
        >
            <div>
                <div class="nc-label">{{ $t('Email recipients') }}</div>
                <div class="nc-num">
                    {{
                        $tChoice(
                            ':count recipient|:count recipients',
                            summary.recipientCount,
                        )
                    }}
                </div>
            </div>
            <div>
                <div class="nc-label">{{ $t('Webhook') }}</div>
                <div>
                    {{
                        summary.webhookConfigured
                            ? $t('Webhook configured')
                            : $t('No webhook')
                    }}
                </div>
            </div>
            <div>
                <div class="nc-label">{{ $t('Quiet hours') }}</div>
                <div class="nc-num">
                    {{
                        summary.quietFrom && summary.quietTo
                            ? `${summary.quietFrom} → ${summary.quietTo} · ${summary.timezone}`
                            : $t('None')
                    }}
                </div>
            </div>
            <div>
                <div class="nc-label">{{ $t('Alert repeat') }}</div>
                <div>
                    {{
                        summary.repeatMinutes === null
                            ? $t('first detection only')
                            : $t('every :minutes min while open', {
                                  minutes: String(summary.repeatMinutes),
                              })
                    }}
                </div>
            </div>
        </div>
    </SectionCard>
</template>

<style scoped>
.settings-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: var(--nc-space-4);
}

.settings-grid > * {
    grid-column: span 2;
}

.settings-grid > .wide {
    grid-column: span 3;
}

@media (max-width: 899px) {
    .settings-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .settings-grid > *,
    .settings-grid > .wide {
        grid-column: auto;
    }
}

.field-hint {
    margin-top: 4px;
    font-size: 11px;
    color: var(--nc-neutral-600);
}

.field-error {
    margin-top: 4px;
    font-size: 11px;
    line-height: 1.35;
    color: var(--st-down);
}

.signature {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 11px;
    color: var(--nc-neutral-400);
    overflow-wrap: anywhere;
}
</style>
