<script setup lang="ts">
import { PhEye, PhEyeSlash } from '@phosphor-icons/vue';
import { ref, useTemplateRef } from 'vue';

defineOptions({ inheritAttrs: false });

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    focus: () => inputRef.value?.focus(),
});
</script>

<template>
    <div class="relative">
        <input
            ref="inputRef"
            class="nc-input"
            style="padding-right: 40px"
            :type="showPassword ? 'text' : 'password'"
            v-bind="$attrs"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-[var(--nc-neutral-500)] hover:text-[var(--nc-text)]"
            style="border-radius: 0 var(--nc-radius-md) var(--nc-radius-md) 0"
            @click="showPassword = !showPassword"
            :aria-label="
                showPassword ? $t('Hide password') : $t('Show password')
            "
        >
            <PhEyeSlash v-if="showPassword" :size="16" />
            <PhEye v-else :size="16" />
        </button>
    </div>
</template>
