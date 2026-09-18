<script setup lang="ts">
import { PhArrowCounterClockwise, PhPencilSimple } from '@phosphor-icons/vue';

defineProps<{
    inherited: boolean;
}>();

defineEmits<{
    toggle: [];
}>();
</script>

<template>
    <button
        type="button"
        class="nc-btn nc-btn-ghost inherit-toggle"
        :title="
            inherited
                ? $t('Override the organization value')
                : $t('Use the organization value')
        "
        :aria-label="
            inherited
                ? $t('Override the organization value')
                : $t('Use the organization value')
        "
        @click="$emit('toggle')"
    >
        <PhPencilSimple v-if="inherited" :size="14" />
        <PhArrowCounterClockwise v-else :size="14" />
    </button>
</template>

<style scoped>
.inherit-toggle {
    flex: none;
    width: 26px;
    height: 26px;
    padding: 0;
    color: var(--nc-neutral-500);
}

.inherit-toggle:hover {
    color: var(--nc-accent);
}
</style>
