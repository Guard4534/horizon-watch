<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    PhGearSix,
    PhLockSimple,
    PhLockSimpleOpen,
    PhPencilSimple,
    PhPlus,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount, statusColor, statusLabel } from '@/lib/monitoring';
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

// Reading an application needs membership, configuring it needs the
// permission: a member or a viewer sees the same table without the three
// ways into the forms (which would answer 403 anyway).
const canManageApplications = computed(
    () => shared.props.canManageApplications,
);
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
                        <th>{{ $t('Collection') }}</th>
                        <th>{{ $t('Nodes') }}</th>
                        <th>{{ $t('Rules') }}</th>
                        <th style="text-align: right">Pending</th>
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
                            <span class="inline-flex items-center gap-2"
                                ><EnvSwatch :color="environment.color" />{{
                                    environment.name
                                }}</span
                            >
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                                letter-spacing: 0.01em;
                            "
                        >
                            {{ environment.horizonUrl.replace('https://', '') }}
                        </td>
                        <td style="font-size: 12px">
                            <span
                                class="inline-flex items-center gap-[5px]"
                                style="color: var(--nc-neutral-300)"
                            >
                                <component
                                    :is="
                                        environment.basicAuthUser
                                            ? PhLockSimple
                                            : PhLockSimpleOpen
                                    "
                                    :size="13"
                                />
                                {{
                                    environment.basicAuthUser
                                        ? `Basic · ${environment.basicAuthUser}`
                                        : $t('none')
                                }}
                            </span>
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{ environment.nodeCount }}
                        </td>
                        <td>
                            <span class="nc-tag nc-tag-neutral">{{
                                environment.name === 'production'
                                    ? $t('Prod override')
                                    : $t('Org default')
                            }}</span>
                        </td>
                        <td class="nc-num" style="text-align: right">
                            {{ formatCount(environment.pending) }}
                        </td>
                        <td>
                            <span
                                class="inline-flex items-center gap-[5px]"
                                style="font-size: 12px"
                                :style="{
                                    color: statusColor(environment.status),
                                }"
                            >
                                <span
                                    class="size-[6px] rounded-full"
                                    :style="{
                                        background: statusColor(
                                            environment.status,
                                        ),
                                    }"
                                />
                                {{ statusLabel(environment.status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap" style="text-align: right">
                            <Link
                                :href="
                                    showEnvironment({
                                        current_team: slug,
                                        environment: environment.id,
                                    })
                                "
                                class="nc-btn nc-btn-ghost"
                                style="font-size: 12px"
                                >{{ $t('Open') }}</Link
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
</style>
