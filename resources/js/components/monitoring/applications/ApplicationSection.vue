<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { PhGearSix, PhPencilSimple, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';
import {
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount } from '@/lib/monitoring';
import {
    edit as editApplication,
    show as showApplication,
} from '@/routes/applications';
import {
    create as createEnvironment,
    edit as editEnvironment,
    show as showEnvironment,
} from '@/routes/environments';

defineProps<{
    group: App.Data.Pages.ApplicationGroupData;
}>();

const slug = useTeamSlug();
const shared = usePage();

const canManageApplications = computed(
    () => shared.props.canManageApplications,
);
function environmentHref(environmentId: string): string {
    return showEnvironment({
        current_team: slug.value,
        environment: environmentId,
    }).url;
}

function openRow(
    environment: { id: string; watched: boolean },
    event: MouseEvent,
): void {
    if (!environment.watched) {
        return;
    }

    if ((event.target as HTMLElement).closest('a, button, input, select')) {
        return;
    }

    router.visit(environmentHref(environment.id));
}
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
                style="
                    font-size: 11px;
                    color: var(--nc-neutral-500);
                    letter-spacing: 0.01em;
                "
                >{{ group.application.host }}</span
            >
            <span
                v-if="group.triageCount"
                style="
                    font-size: 11px;
                    padding: 2px 8px;
                    border-radius: var(--nc-radius-sm);
                    background: color-mix(
                        in srgb,
                        var(--st-down) 16%,
                        transparent
                    );
                    color: var(--st-down);
                "
                >{{
                    $t(':count to triage', { count: String(group.triageCount) })
                }}</span
            >
            <span
                v-else
                style="
                    font-size: 11px;
                    padding: 2px 8px;
                    border-radius: var(--nc-radius-sm);
                    background: var(--nc-neutral-900);
                    color: var(--nc-neutral-400);
                "
            >
                {{ $t('all good') }}
            </span>
            <template v-if="canManageApplications">
                <Link
                    class="nc-btn nc-btn-ghost ml-auto"
                    style="font-size: 12px"
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
                    class="nc-btn nc-btn-ghost"
                    style="font-size: 12px; color: var(--nc-neutral-400)"
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
                        <th style="text-align: right">{{ $t('Pending') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="environment in group.environments"
                        :key="environment.id"
                        :class="{ 'row-link': environment.watched }"
                        @click="openRow(environment, $event)"
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
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                                letter-spacing: 0.01em;
                            "
                        >
                            {{
                                environment.horizonUrl.replace(
                                    /^https?:\/\//,
                                    '',
                                )
                            }}
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{
                                environment.status === null
                                    ? '—'
                                    : environment.nodeCount
                            }}
                        </td>
                        <td class="nc-num" style="text-align: right">
                            {{
                                environment.status === null
                                    ? '—'
                                    : formatCount(environment.pending)
                            }}
                        </td>
                        <td>
                            <span
                                class="inline-flex items-center gap-[5px]"
                                style="font-size: 12px"
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
                        <td class="whitespace-nowrap" style="text-align: right">
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
                                class="nc-btn nc-btn-ghost"
                                style="
                                    font-size: 12px;
                                    color: var(--nc-neutral-400);
                                "
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

.row-link {
    cursor: pointer;
}

.row-anchor {
    display: inline-flex;
    border-radius: var(--nc-radius-sm);
    text-decoration: none;
}
</style>
