<script setup lang="ts">
import { PhBellSlash, PhCaretDown } from '@phosphor-icons/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAlertActions } from '@/composables/useAlertActions';
import { ruleLabel } from '@/lib/alertRules';
import { MUTE_DURATIONS, muteDurationLabel } from '@/lib/alerts';

const {
    alert,
    variant = 'button',
    block = false,
    large = false,
} = defineProps<{
    alert: App.Data.Monitoring.AlertData;
    variant?: 'icon' | 'button';
    block?: boolean;
    large?: boolean;
}>();

const { busy, muteAlert } = useAlertActions();

const pending = computed(() => busy.value.has(alert.id));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                v-if="variant === 'icon'"
                type="button"
                class="nc-btn nc-btn-ghost mute-icon"
                :title="$t('Mute')"
                :aria-label="$t('Mute')"
                :disabled="pending"
            >
                <PhBellSlash :size="14" />
            </button>
            <button
                v-else
                type="button"
                class="nc-btn nc-btn-secondary mute-button"
                :class="{ 'is-block': block, 'is-large': large }"
                :disabled="pending"
            >
                <PhBellSlash :size="large ? 13 : 12" />{{ $t('Mute') }}
                <PhCaretDown :size="10" style="opacity: 0.7" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuLabel class="nc-tone-muted" style="font-weight: 400">{{
                $t('Mute :rule', { rule: ruleLabel(alert.metric) })
            }}</DropdownMenuLabel>
            <DropdownMenuItem
                v-for="duration in MUTE_DURATIONS"
                :key="duration"
                @click="muteAlert(alert, duration)"
            >
                {{ muteDurationLabel(duration) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped>
.mute-icon {
    font-size: 11px;
    padding: 2px 7px;
}

.mute-button {
    font-size: 11px;
    padding: 2px 8px;
}

.mute-button.is-block {
    flex: 1;
    justify-content: center;
    padding: 3px 8px;
}

.mute-button.is-large {
    font-size: 12px;
}
</style>
