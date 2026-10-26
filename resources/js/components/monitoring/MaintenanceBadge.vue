<script setup lang="ts">
import { PhWrench } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { maintenanceUntilText } from '@/lib/maintenance';

const {
    until,
    reason = null,
    compact = false,
} = defineProps<{
    until: string;
    reason?: string | null;
    compact?: boolean;
}>();

const { locale } = useLocale();

const text = computed(() => maintenanceUntilText(until, locale.value));
const title = computed(() =>
    reason ? `${text.value} · ${reason}` : text.value,
);
</script>

<template>
    <span
        v-if="compact"
        class="maintenance-icon"
        role="img"
        :title="title"
        :aria-label="title"
    >
        <PhWrench :size="12" weight="bold" />
    </span>
    <span v-else class="maintenance-badge nc-t-xs" :title="title">
        <PhWrench :size="13" weight="bold" class="flex-none" />
        <span class="min-w-0 truncate">{{ text }}</span>
        <span v-if="reason" class="nc-tone-muted min-w-0 truncate"
            >· {{ reason }}</span
        >
    </span>
</template>

<style scoped>
.maintenance-icon {
    display: inline-flex;
    flex: none;
    color: var(--nc-accent);
}

.maintenance-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    padding: 3px 9px;
    border-radius: var(--nc-radius-sm);
    color: var(--nc-accent);
    border: 1px solid color-mix(in srgb, var(--nc-accent) 45%, transparent);
    background: color-mix(in srgb, var(--nc-accent) 10%, transparent);
}
</style>
