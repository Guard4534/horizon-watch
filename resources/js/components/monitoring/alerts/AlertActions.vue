<script setup lang="ts">
import { PhBell, PhCheck } from '@phosphor-icons/vue';
import { computed } from 'vue';
import MuteMenu from '@/components/monitoring/alerts/MuteMenu.vue';
import { useAlertActions } from '@/composables/useAlertActions';
import { isMuted } from '@/lib/alerts';

const {
    alert,
    layout = 'buttons',
    block = false,
} = defineProps<{
    alert: App.Data.Monitoring.AlertData;
    layout?: 'icons' | 'buttons';
    block?: boolean;
}>();

const { busy, unmuteAlert, handleAlert } = useAlertActions();

const pending = computed(() => busy.value.has(alert.id));
const muted = computed(() => isMuted(alert));
const canTakeInCharge = computed(
    () => alert.canHandle && alert.handledBy === null,
);
const hasActions = computed(() => alert.canMute || canTakeInCharge.value);
</script>

<template>
    <div
        v-if="hasActions && layout === 'icons'"
        class="inline-flex items-center justify-end"
        style="gap: 2px"
    >
        <template v-if="alert.canMute">
            <button
                v-if="muted"
                type="button"
                class="nc-btn nc-btn-ghost icon-action"
                :title="$t('Unmute')"
                :aria-label="$t('Unmute')"
                :disabled="pending"
                @click="unmuteAlert(alert)"
            >
                <PhBell :size="14" />
            </button>
            <MuteMenu v-else :alert="alert" variant="icon" />
        </template>
        <button
            v-if="canTakeInCharge"
            type="button"
            class="nc-btn nc-btn-ghost icon-action"
            :title="$t('Mark as handled')"
            :aria-label="$t('Mark as handled')"
            :disabled="pending"
            @click="handleAlert(alert)"
        >
            <PhCheck :size="14" />
        </button>
    </div>
    <div
        v-else-if="hasActions"
        class="flex flex-wrap"
        :class="{ 'w-full': block }"
        style="gap: var(--nc-space-2)"
    >
        <template v-if="alert.canMute">
            <button
                v-if="muted"
                type="button"
                class="nc-btn nc-btn-secondary text-action"
                :class="{ 'is-block': block }"
                :disabled="pending"
                @click="unmuteAlert(alert)"
            >
                <PhBell :size="12" />{{ $t('Unmute') }}
            </button>
            <MuteMenu v-else :alert="alert" :block="block" />
        </template>
        <button
            v-if="canTakeInCharge"
            type="button"
            class="nc-btn nc-btn-ghost text-action"
            :class="{ 'is-block': block }"
            :disabled="pending"
            @click="handleAlert(alert)"
        >
            <PhCheck :size="12" />{{ $t('Handled') }}
        </button>
    </div>
</template>

<style scoped>
.icon-action {
    font-size: 11px;
    padding: 2px 7px;
}

.text-action {
    font-size: 11px;
    padding: 2px 8px;
}

.text-action.is-block {
    flex: 1;
    justify-content: center;
    padding: 3px 8px;
}
</style>
