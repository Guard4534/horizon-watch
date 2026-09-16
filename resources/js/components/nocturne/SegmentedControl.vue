<script setup lang="ts" generic="T extends string">
defineProps<{
    options: Array<{ value: T; label: string }>;
    name: string;
}>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div class="nc-seg">
        <label v-for="option in options" :key="option.value" class="nc-seg-opt">
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="option.value"
            />
            {{ option.label }}
        </label>
    </div>
</template>
