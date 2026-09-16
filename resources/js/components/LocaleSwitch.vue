<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

const { name, padding = '1px 7px' } = defineProps<{
    name: string;
    padding?: string;
}>();

const { locale, setLocale } = useLocale();
</script>

<template>
    <div class="nc-seg" style="border-radius: var(--nc-radius-sm)">
        <label
            v-for="option in ['it', 'en'] as const"
            :key="option"
            class="nc-seg-opt"
            :style="{ padding, fontSize: '10px' }"
        >
            <input
                type="radio"
                :name="name"
                :checked="locale === option"
                @change="setLocale(option)"
            />
            {{ option.toUpperCase() }}
        </label>
    </div>
</template>
