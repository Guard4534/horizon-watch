<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, useId, watch } from 'vue';
import { update as updateAlertEmails } from '@/routes/alert-emails';

const { enabled } = defineProps<{
    enabled: boolean;
}>();

const id = useId();
const checked = ref(enabled);
const saving = ref(false);

watch(
    () => enabled,
    (value) => (checked.value = value),
);

function change(value: boolean): void {
    checked.value = value;

    router.patch(
        updateAlertEmails().url,
        { alertEmails: value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onError: () => (checked.value = enabled),
        },
    );
}
</script>

<template>
    <label class="nc-switch flex-none" :for="id">
        <input
            :id="id"
            type="checkbox"
            role="switch"
            :checked="checked"
            :aria-checked="checked"
            :disabled="saving"
            @change="change(($event.target as HTMLInputElement).checked)"
        />
        <span class="nc-track" aria-hidden="true" />
        <span v-if="$slots.default" class="nc-t-sm"><slot /></span>
        <span v-else class="sr-only">{{ $t('Alert emails') }}</span>
    </label>
</template>
