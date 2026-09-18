<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhEnvelopeSimple, PhWebhooksLogo } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { index as alertRulesIndex } from '@/routes/alert-rules';

defineProps<{
    summary: App.Data.Pages.NotificationSummaryData;
    manages: boolean;
}>();

const slug = useTeamSlug();
</script>

<template>
    <SectionCard :title="$t('Delivery policy')">
        <template v-if="manages" #actions>
            <Link
                :href="alertRulesIndex({ current_team: slug })"
                style="font-size: 11px"
                >{{ $t('Alert settings') }}</Link
            >
        </template>
        <ul class="policy">
            <li>
                <template v-if="summary.repeatMinutes">{{
                    $t(
                        'A critical alert is sent as soon as it opens, then again every :minutes minutes until it clears, gets muted or is taken in charge.',
                        { minutes: String(summary.repeatMinutes) },
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'A critical alert is sent once, as soon as it opens: it does not repeat.',
                    )
                }}</template>
            </li>
            <li>
                {{ $t('Warnings are grouped into a digest every 15 minutes.') }}
            </li>
            <li>
                <template v-if="summary.quietFrom && summary.quietTo">{{
                    $t(
                        'From :from to :to (:timezone) only criticals get through; the digest waits for the end of the window.',
                        {
                            from: summary.quietFrom,
                            to: summary.quietTo,
                            timezone: summary.timezone,
                        },
                    )
                }}</template>
                <template v-else>{{
                    $t('No quiet hours: every notification goes out at once.')
                }}</template>
            </li>
            <li>
                {{
                    $t(
                        'When a notified alert clears, a resolution follows. A muted alert sends nothing at all.',
                    )
                }}
            </li>
        </ul>
        <div
            class="flex flex-col"
            style="
                gap: var(--nc-space-2);
                margin-top: var(--nc-space-3);
                padding-top: var(--nc-space-3);
                border-top: 1px solid
                    color-mix(in srgb, var(--nc-text) 7%, transparent);
                font-size: 12px;
                color: var(--nc-neutral-400);
            "
        >
            <div class="flex items-start gap-2">
                <PhEnvelopeSimple :size="14" class="mt-[2px] flex-none" />
                <span>{{
                    $tChoice(
                        '{0} By email to the members who turned on alert emails|{1} By email to the members who turned on alert emails, plus one extra address|[2,*] By email to the members who turned on alert emails, plus :count extra addresses',
                        summary.recipientCount,
                    )
                }}</span>
            </div>
            <div class="flex items-center gap-2">
                <PhWebhooksLogo :size="14" class="flex-none" />
                <span>{{
                    summary.webhookConfigured
                        ? $t('Webhook configured')
                        : $t('No webhook')
                }}</span>
            </div>
        </div>
    </SectionCard>
</template>

<style scoped>
.policy {
    margin: 0;
    padding: 0 0 0 1.1em;
    display: flex;
    flex-direction: column;
    gap: var(--nc-space-2);
    font-size: 12px;
    line-height: 1.5;
    color: var(--nc-neutral-400);
    list-style: disc;
}
</style>
