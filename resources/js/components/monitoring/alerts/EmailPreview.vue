<script setup lang="ts">
import { computed } from 'vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { ruleLabel } from '@/lib/alertRules';
import { preview } from '@/routes/alerts';

const { alert } = defineProps<{
    alert: App.Data.Monitoring.AlertData | null;
}>();

const slug = useTeamSlug();

const src = computed(() =>
    alert === null
        ? null
        : preview({ current_team: slug.value, alert: alert.id }).url,
);
</script>

<template>
    <SectionCard :title="$t('Email preview')">
        <div
            v-if="alert === null || src === null"
            class="nc-t-xs nc-tone-muted"
            style="line-height: 1.5"
        >
            {{
                $t(
                    'No critical alert in this tab. Warnings are never emailed one by one: they arrive in the digest.',
                )
            }}
        </div>
        <template v-else>
            <div
                class="nc-t-2xs nc-tone-muted mb-[var(--nc-space-2)] flex items-center gap-[7px]"
            >
                <EnvSwatch :color="alert.color" shape="bar" :size="11" />
                <span class="min-w-0 truncate"
                    >{{ alert.applicationName }} / {{ alert.environmentName }} ·
                    {{ ruleLabel(alert.metric) }}</span
                >
            </div>
            <iframe
                :key="src"
                :src="src"
                sandbox=""
                referrerpolicy="no-referrer"
                class="preview"
                :title="$t('Email preview')"
            />
            <div
                class="nc-t-2xs nc-tone-faint"
                style="margin-top: var(--nc-space-2); line-height: 1.5"
            >
                {{
                    $t(
                        'The email sent when this alert opens, shown in your language.',
                    )
                }}
            </div>
        </template>
    </SectionCard>
</template>

<style scoped>
.preview {
    display: block;
    width: 100%;
    height: 440px;
    border: 1px solid var(--nc-divider);
    border-radius: var(--nc-radius-md);
    background: var(--nc-bg);
}
</style>
