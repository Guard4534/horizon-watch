<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { envColor } from '@/lib/monitoring';
import { index as alertRulesIndex } from '@/routes/alert-rules';

defineProps<{
    scopes: App.Data.Monitoring.RuleScopeData[];
    current: string;
}>();

const slug = useTeamSlug();
</script>

<template>
    <section class="nc-card" style="padding: var(--nc-space-3)">
        <div
            class="nc-label"
            style="padding: 0 var(--nc-space-2) var(--nc-space-2)"
        >
            {{ $t('Scope') }}
        </div>
        <div class="flex flex-col gap-[2px]">
            <Link
                v-for="scope in scopes"
                :key="scope.id"
                :href="alertRulesIndex({ current_team: slug, scope: scope.id })"
                class="scope"
                :class="{ 'is-active': scope.id === current }"
                preserve-scroll
            >
                <span class="flex items-center gap-2">
                    <span
                        class="size-2 flex-none rounded-[2px]"
                        :style="{
                            background: scope.color
                                ? envColor(scope.color)
                                : 'var(--nc-accent)',
                        }"
                    />
                    <span class="min-w-0 truncate">{{
                        scope.id === 'organization'
                            ? $t('Organization default')
                            : scope.id
                    }}</span>
                    <span
                        v-if="scope.overrideCount"
                        class="nc-tag nc-tag-sm nc-tag-accent ml-auto flex-none"
                        >{{
                            $tChoice(
                                ':count override|:count overrides',
                                scope.overrideCount,
                            )
                        }}</span
                    >
                </span>
                <span
                    class="mt-px block"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
                    {{
                        scope.id === 'organization'
                            ? $t('applies to everything')
                            : $tChoice(
                                  ':count environment|:count environments',
                                  scope.environmentCount,
                              )
                    }}
                </span>
            </Link>
        </div>
    </section>
</template>

<style scoped>
.scope {
    display: block;
    width: 100%;
    font-size: 13px;
    padding: 6px var(--nc-space-2);
    border-radius: var(--nc-radius-sm);
    color: inherit;
    text-decoration: none;
}

.scope:hover {
    background: color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.scope.is-active {
    background: color-mix(in srgb, var(--nc-accent) 14%, transparent);
    box-shadow: inset 2px 0 0 var(--nc-accent);
}
</style>
