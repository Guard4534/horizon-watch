<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCaretRight } from '@phosphor-icons/vue';
import AttentionBadge from '@/components/monitoring/AttentionBadge.vue';
import {
    needsAttention,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envTypeColor } from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';

defineProps<{
    groups: App.Data.Pages.ApplicationGroupData[];
}>();

const slug = useTeamSlug();

const dotStyle = (environment: App.Data.Monitoring.EnvironmentData) => ({
    background: envTypeColor(environment.type),
    boxShadow: needsAttention(environment)
        ? `0 0 0 2px color-mix(in srgb, ${statusTone(environment)} 55%, transparent)`
        : undefined,
});
</script>

<template>
    <div class="flex flex-col">
        <Link
            v-for="group in groups"
            :key="group.application.id"
            :href="
                showApplication({
                    current_team: slug,
                    application: group.application.id,
                })
            "
            class="app-row flex items-center"
        >
            <span class="min-w-0 flex-1">
                <span class="nc-t-sm block truncate">{{
                    group.application.name
                }}</span>
                <span class="mt-[5px] flex flex-wrap items-center gap-1">
                    <span
                        v-for="environment in group.environments"
                        :key="environment.id"
                        class="size-[7px] flex-none rounded-full"
                        :style="dotStyle(environment)"
                        :title="`${environment.name} · ${statusText(environment)}`"
                    />
                    <span
                        class="nc-num nc-tone-faint"
                        style="margin-left: 6px; font-size: 11px"
                        >{{
                            $tChoice(
                                ':count environment|:count environments',
                                group.environments.length,
                            )
                        }}</span
                    >
                </span>
            </span>
            <AttentionBadge
                :count="group.attentionCount"
                :environments="group.environments"
            />
            <PhCaretRight :size="14" class="nc-tone-faint flex-none" />
        </Link>
    </div>
</template>

<style scoped>
.app-row {
    gap: 9px;
    padding: var(--nc-space-3) 0;
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
    color: inherit;
    text-decoration: none;
}
</style>
