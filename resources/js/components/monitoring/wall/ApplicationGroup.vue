<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCaretDown, PhCaretRight } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EnvironmentTile from '@/components/monitoring/wall/EnvironmentTile.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    envColor,
    formatCount,
    statusColor,
    statusLabel,
} from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';

// One application's environments, already in the wall's order (worst
// first), so the first troubled one is also the worst.
const { applicationId, applicationName, environments, failedPerHourThreshold } =
    defineProps<{
        applicationId: string;
        applicationName: string;
        environments: App.Data.Monitoring.EnvironmentData[];
        failedPerHourThreshold: number;
    }>();

// Undefined until the viewer clicks: then the click wins over the default.
const forced = defineModel<boolean | undefined>('expanded');

const slug = useTeamSlug();

const troubled = computed(() =>
    environments.filter(
        (environment) =>
            environment.status !== null && environment.status !== 'active',
    ),
);
const worstColor = computed(() => {
    const status = troubled.value[0]?.status;

    return status ? statusColor(status) : 'var(--nc-neutral-500)';
});

// Healthy groups fold away by default: on 50+ environments only the ones
// asking for attention take vertical space.
const expanded = computed(() => forced.value ?? troubled.value.length > 0);

// Summed on the raw numbers, never on the formatted "4.0k" strings.
const pending = computed(() =>
    environments.reduce((total, environment) => total + environment.pending, 0),
);

function dotLabel(environment: App.Data.Monitoring.EnvironmentData): string {
    return `${environment.name} · ${
        environment.status === null
            ? trans('No reading yet')
            : statusLabel(environment.status)
    }`;
}

function dotStyle(environment: App.Data.Monitoring.EnvironmentData) {
    const halo =
        environment.status !== null && environment.status !== 'active'
            ? `0 0 0 2px color-mix(in srgb, ${statusColor(environment.status)} 55%, transparent)`
            : undefined;

    return { background: envColor(environment.color), boxShadow: halo };
}
</script>

<template>
    <div
        class="flex flex-col"
        style="gap: var(--nc-space-2); margin-top: var(--nc-space-2)"
    >
        <div class="head flex items-center gap-2">
            <!-- The name opens the application (PROMPT item 1); the chevron
                 folds the group. -->
            <button
                type="button"
                class="toggle"
                :aria-expanded="expanded"
                :aria-controls="`wall-group-${applicationId}`"
                :aria-label="
                    expanded
                        ? $t('Hide the environments of :application', {
                              application: applicationName,
                          })
                        : $t('Show the environments of :application', {
                              application: applicationName,
                          })
                "
                @click="forced = !expanded"
            >
                <component
                    :is="expanded ? PhCaretDown : PhCaretRight"
                    :size="12"
                />
            </button>
            <Link
                :href="
                    showApplication({
                        current_team: slug,
                        application: applicationId,
                    })
                "
                class="name min-w-0 truncate"
                >{{ applicationName }}</Link
            >
            <span
                v-if="troubled.length"
                class="badge"
                :style="{
                    background: `color-mix(in srgb, ${worstColor} 18%, transparent)`,
                    color: worstColor,
                }"
                >{{
                    $t(':count to handle', { count: String(troubled.length) })
                }}</span
            >
            <span
                v-else
                class="badge"
                style="
                    background: var(--nc-neutral-900);
                    color: var(--nc-neutral-500);
                "
                >{{ $t('all good') }}</span
            >
            <span
                v-if="!expanded"
                class="flex min-w-0 flex-wrap items-center gap-[5px]"
            >
                <span
                    v-for="environment in environments"
                    :key="environment.id"
                    class="dot"
                    role="img"
                    :style="dotStyle(environment)"
                    :title="dotLabel(environment)"
                    :aria-label="dotLabel(environment)"
                />
            </span>
            <span
                class="nc-num ml-auto flex-none"
                style="font-size: 11px; color: var(--nc-neutral-600)"
                >{{
                    $tChoice(
                        ':count environment|:count environments',
                        environments.length,
                    )
                }}
                · {{ formatCount(pending) }} pending</span
            >
        </div>
        <div
            v-if="expanded"
            :id="`wall-group-${applicationId}`"
            class="grid"
            style="
                grid-template-columns: repeat(auto-fill, minmax(176px, 1fr));
                gap: var(--nc-space-3);
            "
        >
            <EnvironmentTile
                v-for="environment in environments"
                :key="environment.id"
                :environment="environment"
                :failed-per-hour-threshold="failedPerHourThreshold"
            />
        </div>
    </div>
</template>

<style scoped>
.head {
    padding-bottom: var(--nc-space-1);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 8%, transparent);
}

.toggle {
    display: inline-grid;
    place-items: center;
    flex: none;
    width: 20px;
    height: 20px;
    margin-right: -4px;
    border: 0;
    border-radius: var(--nc-radius-sm);
    background: transparent;
    padding: 0;
    color: var(--nc-neutral-500);
    cursor: pointer;
}

.toggle:hover {
    color: var(--nc-accent);
    background: color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.name {
    font-size: 14px;
    color: inherit;
    text-decoration: none;
}

.name:hover {
    color: var(--nc-accent);
}

.badge {
    flex: none;
    font-size: 10px;
    padding: 1px 7px;
    border-radius: var(--nc-radius-sm);
}

.dot {
    width: 7px;
    height: 7px;
    flex: none;
    border-radius: 50%;
}
</style>
