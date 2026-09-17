<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

const {
    name,
    padding = '1px 7px',
    fontSize = '10px',
    long = false,
} = defineProps<{
    name: string;
    padding?: string;
    fontSize?: string;
    // Each language written in itself, not translated: that is how people
    // recognise their own language in a list.
    long?: boolean;
}>();

const names = { it: 'Italiano', en: 'English' } as const;

const { locale, setLocale } = useLocale();
</script>

<template>
    <div class="nc-seg" style="border-radius: var(--nc-radius-sm)">
        <label
            v-for="option in ['it', 'en'] as const"
            :key="option"
            class="nc-seg-opt"
            :style="{ padding, fontSize }"
        >
            <input
                type="radio"
                :name="name"
                :checked="locale === option"
                @change="setLocale(option)"
            />
            {{ long ? names[option] : option.toUpperCase() }}
        </label>
    </div>
</template>
