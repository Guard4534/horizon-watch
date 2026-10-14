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
    long?: boolean;
}>();

const names: Record<App.Enums.Locale, string> = {
    it: 'Italiano',
    en: 'English',
};

const options = Object.keys(names) as App.Enums.Locale[];

const { locale, setLocale } = useLocale();
</script>

<template>
    <div
        class="nc-seg"
        style="border-radius: var(--nc-radius-sm)"
        role="radiogroup"
        :aria-label="$t('Language')"
    >
        <label
            v-for="option in options"
            :key="option"
            class="nc-seg-opt"
            :style="{ padding, fontSize }"
            :lang="option"
        >
            <input
                type="radio"
                :name="name"
                :checked="locale === option"
                :aria-label="long ? undefined : names[option]"
                @change="setLocale(option)"
            />
            {{ long ? names[option] : option.toUpperCase() }}
        </label>
    </div>
</template>
