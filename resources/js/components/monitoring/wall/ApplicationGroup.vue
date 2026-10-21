<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCaretDown, PhCaretRight } from '@phosphor-icons/vue';
import { computed } from 'vue';
import AttentionBadge from '@/components/monitoring/AttentionBadge.vue';
import EnvironmentTile from '@/components/monitoring/wall/EnvironmentTile.vue';
import {
    needsAttention,
    statusText,
    statusTone,
} from '@/components/monitoring/environment/readings';
import StatusLamp from '@/components/nocturne/StatusLamp.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatCount } from '@/lib/monitoring';
import { show as showApplication } from '@/routes/applications';

const { applicationId, applicationName, environments } = defineProps<{
    applicationId: string;
    applicationName: string;
    environments: App.Data.Monitoring.EnvironmentData[];
}>();

const forced = defineModel<boolean | undefined>('expanded');

const slug = useTeamSlug();

const troubled = computed(() => environments.filter(needsAttention));

const expanded = computed(() => forced.value ?? troubled.value.length > 0);

const pending = computed(() =>
    environments.reduce((total, environment) => total + environment.pending, 0),
);
</script>

<template>
    <div
        class="flex flex-col"
        style="gap: var(--nc-space-2); margin-top: var(--nc-space-2)"
    >
        <div class="head flex items-center gap-2">
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
            <AttentionBadge
                :count="troubled.length"
                :environments="environments"
            />
            <span
                v-if="!expanded"
                class="flex min-w-0 flex-wrap items-center gap-[5px]"
            >
                <StatusLamp
                    v-for="environment in environments"
                    :key="environment.id"
                    :status="environment.status"
                    :tone="statusTone(environment)"
                    :label="`${environment.name} · ${statusText(environment)}`"
                    :size="7"
                />
            </span>
            <span class="nc-num nc-t-2xs nc-tone-faint ml-auto flex-none"
                >{{
                    $tChoice(
                        ':count environment|:count environments',
                        environments.length,
                    )
                }}
                · {{ formatCount(pending) }} {{ $t('pending') }}</span
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
</style>
