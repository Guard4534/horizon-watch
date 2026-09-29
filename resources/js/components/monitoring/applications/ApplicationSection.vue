<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhGearSix, PhPencilSimple, PhPlus } from '@phosphor-icons/vue';
import {
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import { useEnvironmentHref } from '@/composables/useEnvironmentHref';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { useVisibility } from '@/composables/useVisibility';
import { formatCount } from '@/lib/monitoring';
import {
    edit as editApplication,
    show as showApplication,
} from '@/routes/applications';
import {
    create as createEnvironment,
    edit as editEnvironment,
} from '@/routes/environments';

defineProps<{
    group: App.Data.Pages.ApplicationGroupData;
}>();

const slug = useTeamSlug();
const environmentHref = useEnvironmentHref();
const { canManageApplications } = useVisibility();
</script>

<template>
    <section class="nc-card">
        <div
            class="mb-[var(--nc-space-2)] flex flex-wrap items-center"
            style="gap: var(--nc-space-3)"
        >
            <Link
                :href="
                    showApplication({
                        current_team: slug,
                        application: group.application.id,
                    })
                "
                class="app-name"
            >
                {{ group.application.name }}
            </Link>
            <span
                class="nc-t-2xs nc-tone-muted"
                style="letter-spacing: 0.01em"
                >{{ group.application.host }}</span
            >
            <span
                v-if="group.triageCount"
                class="nc-tag nc-tag-sm nc-tone-down flex-none"
                style="
                    background: color-mix(
                        in srgb,
                        var(--st-down) 16%,
                        transparent
                    );
                "
                >{{
                    $t(':count to triage', { count: String(group.triageCount) })
                }}</span
            >
            <span v-else class="nc-tag nc-tag-sm nc-tag-neutral flex-none">
                {{ $t('all good') }}
            </span>
            <template v-if="canManageApplications">
                <Link
                    class="nc-btn nc-btn-ghost nc-t-xs ml-auto"
                    :href="
                        createEnvironment({
                            current_team: slug,
                            application: group.application.id,
                        })
                    "
                >
                    <PhPlus :size="13" />{{ $t('Environment') }}
                </Link>
                <Link
                    class="nc-btn nc-btn-ghost nc-t-xs nc-tone-soft"
                    :href="
                        editApplication({
                            current_team: slug,
                            application: group.application.id,
                        })
                    "
                    :title="$t('Edit application')"
                >
                    <PhGearSix :size="14" />
                </Link>
            </template>
        </div>
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Environment') }}</th>
                        <th>{{ $t('Horizon URL') }}</th>
                        <th>{{ $t('Nodes') }}</th>
                        <th class="nc-right">{{ $t('Pending') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="environment in group.environments"
                        :key="environment.id"
                    >
                        <td>
                            <Link
                                v-if="environment.watched"
                                :href="environmentHref(environment.id)"
                                class="row-anchor"
                            >
                                <EnvPill
                                    :name="environment.name"
                                    :color="environment.color"
                                />
                            </Link>
                            <EnvPill
                                v-else
                                :name="environment.name"
                                :color="environment.color"
                            />
                        </td>
                        <td
                            class="nc-t-xs nc-tone-soft"
                            style="letter-spacing: 0.01em"
                        >
                            {{
                                environment.horizonUrl.replace(
                                    /^https?:\/\//,
                                    '',
                                )
                            }}
                        </td>
                        <td class="nc-t-xs nc-tone-soft">
                            {{
                                environment.status === null
                                    ? '—'
                                    : environment.nodeCount
                            }}
                        </td>
                        <td class="nc-num nc-right">
                            {{
                                environment.status === null
                                    ? '—'
                                    : formatCount(environment.pending)
                            }}
                        </td>
                        <td>
                            <span
                                class="nc-t-xs inline-flex items-center gap-[5px]"
                                :style="{ color: statusTone(environment) }"
                            >
                                <template v-if="environment.watched">
                                    <span
                                        class="size-[6px] rounded-full"
                                        :style="{
                                            background: statusTone(environment),
                                        }"
                                    />
                                    {{ statusText(environment) }}
                                </template>
                                <template v-else>—</template>
                            </span>
                        </td>
                        <td class="nc-right whitespace-nowrap">
                            <span
                                v-if="!environment.watched"
                                class="nc-tag nc-tag-neutral"
                                :title="
                                    $t(
                                        'Your visibility does not cover this environment: no detail page and no chart, but you can still configure it.',
                                    )
                                "
                                >{{ $t('Not on your wall') }}</span
                            >
                            <Link
                                v-if="canManageApplications"
                                class="nc-btn nc-btn-ghost nc-t-xs nc-tone-soft"
                                :href="
                                    editEnvironment({
                                        current_team: slug,
                                        environment: environment.id,
                                    })
                                "
                                :title="$t('Edit environment')"
                            >
                                <PhPencilSimple :size="13" />
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<style scoped>
.app-name {
    color: inherit;
    font-size: 16px;
    text-decoration: none;
}

.app-name:hover {
    color: var(--nc-accent);
}

.row-anchor {
    display: inline-flex;
    border-radius: var(--nc-radius-sm);
    text-decoration: none;
}
</style>
