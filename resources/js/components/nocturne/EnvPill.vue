<script setup lang="ts">
import { PhShieldCheck } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { envColor } from '@/lib/monitoring';

const {
    name,
    color,
    size = 12,
    production = false,
} = defineProps<{
    name: string;
    color: App.Enums.EnvironmentColor;
    size?: number;
    production?: boolean;
}>();

const style = computed(() => {
    const background = envColor(color);

    return {
        color: background,
        background: `color-mix(in srgb, ${background} 20%, transparent)`,
        fontSize: `${size}px`,
        padding: size >= 12 ? '2px 8px' : '1px 7px',
    };
});

const title = computed(() =>
    production ? `${name} · ${trans('Production environment')}` : name,
);
</script>

<template>
    <span class="env-pill" :style="style" :title="title"
        ><PhShieldCheck
            v-if="production"
            class="env-pill-mark"
            :size="size"
            weight="bold"
            :aria-label="$t('Production environment')"
            role="img"
        />{{ name }}</span
    >
</template>

<style scoped>
.env-pill {
    display: inline-block;
    max-width: 100%;
    box-sizing: border-box;
    border-radius: var(--nc-radius-sm);
    letter-spacing: 0.01em;
    line-height: 1.45;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}

.env-pill-mark {
    display: inline-block;
    margin-right: 4px;
    vertical-align: -0.125em;
}
</style>
