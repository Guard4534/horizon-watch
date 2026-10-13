<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { PhHeartbeat } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { formatAge } from '@/components/monitoring/environment/readings';

const page = usePage();

const health = computed(() => page.props.panelHealth);
const unhealthy = computed(
    () => health.value !== null && !health.value.healthy,
);
</script>

<template>
    <div v-if="health && unhealthy" class="banner" role="alert">
        <PhHeartbeat :size="18" class="mt-[2px] flex-none" />
        <div class="min-w-0">
            <template v-if="health.schedulerLate">
                <div class="headline">
                    {{ $t('Horizon Watch is not reading anything') }}
                </div>
                <div class="detail">
                    <template v-if="health.schedulerSecondsAgo === null">{{
                        $t(
                            'Its scheduler has not run yet: no environment is read and no alert is sent until it does.',
                        )
                    }}</template>
                    <template v-else>{{
                        $t(
                            'Its scheduler last ran :time ago: no environment is read and no alert is sent until it runs again.',
                            { time: formatAge(health.schedulerSecondsAgo) },
                        )
                    }}</template>
                </div>
            </template>
            <template v-if="health.backlogLate">
                <div class="headline">
                    {{ $t('Horizon Watch is falling behind') }}
                </div>
                <div class="detail">
                    {{
                        $t(
                            'A background job has been waiting :time for a worker: readings and alert notifications are late.',
                            { time: formatAge(health.oldestWaitSeconds ?? 0) },
                        )
                    }}
                </div>
            </template>
            <div class="detail hint">
                {{
                    $t(
                        'Whoever runs this panel should check its scheduler and worker containers.',
                    )
                }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.banner {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    margin: var(--nc-space-4) var(--nc-space-6) 0;
    padding: var(--nc-space-3) var(--nc-space-4);
    border: 1px solid var(--st-down);
    border-radius: var(--nc-radius-md);
    background: color-mix(in srgb, var(--st-down) 10%, transparent);
    color: var(--st-down);
}

.headline {
    font-size: 14px;
    line-height: 1.35;
    color: var(--nc-text);
}

.detail {
    margin-top: 3px;
    font-size: 12px;
    line-height: 1.45;
    color: var(--nc-neutral-300);
}

.hint {
    color: var(--nc-neutral-400);
}

@media (max-width: 639px) {
    .banner {
        margin: var(--nc-space-3) var(--nc-space-4) 0;
    }
}
</style>
