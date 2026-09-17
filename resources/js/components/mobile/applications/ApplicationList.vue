<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCaretRight } from '@phosphor-icons/vue';
import {
    needsAttention,
    statusTone,
} from '@/components/monitoring/environment/readings';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envColor } from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';

// The phone list: one row per application, a dot per environment in its
// own colour, ringed in the status colour when it needs attention.
defineProps<{
    groups: App.Data.Pages.ApplicationGroupData[];
}>();

const slug = useTeamSlug();

const dotStyle = (environment: App.Data.Monitoring.EnvironmentData) => ({
    background: envColor(environment.color),
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
                <span class="block truncate" style="font-size: 13px">{{
                    group.application.name
                }}</span>
                <span class="mt-[5px] flex flex-wrap items-center gap-1">
                    <span
                        v-for="environment in group.environments"
                        :key="environment.id"
                        class="size-[7px] flex-none rounded-full"
                        :style="dotStyle(environment)"
                        :title="environment.name"
                    />
                    <span
                        class="nc-num"
                        style="
                            margin-left: 6px;
                            font-size: 10px;
                            color: var(--nc-neutral-600);
                        "
                        >{{
                            $tChoice(
                                ':count environment|:count environments',
                                group.environments.length,
                            )
                        }}</span
                    >
                </span>
            </span>
            <!-- Triage count, or a check mark when nothing needs a look. -->
            <span
                class="nc-num flex-none"
                style="
                    font-size: 10px;
                    padding: 1px 7px;
                    border-radius: var(--nc-radius-sm);
                "
                :style="
                    group.triageCount
                        ? {
                              background:
                                  'color-mix(in srgb, var(--st-down) 18%, transparent)',
                              color: 'var(--st-down)',
                          }
                        : {
                              background: 'var(--nc-neutral-900)',
                              color: 'var(--nc-neutral-500)',
                          }
                "
                :title="
                    group.triageCount
                        ? $t(':count to triage', {
                              count: String(group.triageCount),
                          })
                        : $t('all good')
                "
                >{{ group.triageCount || '✓' }}</span
            >
            <PhCaretRight
                :size="14"
                class="flex-none"
                style="color: var(--nc-neutral-600)"
            />
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
