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
    <label class="alert-emails-switch" :for="id">
        <input
            :id="id"
            type="checkbox"
            role="switch"
            :checked="checked"
            :aria-checked="checked"
            :disabled="saving"
            @change="change(($event.target as HTMLInputElement).checked)"
        />
        <span class="track" aria-hidden="true"><span class="knob" /></span>
        <span v-if="$slots.default" class="text"><slot /></span>
        <span v-else class="sr-only">{{ $t('Alert emails') }}</span>
    </label>
</template>

<style scoped>
.alert-emails-switch {
    position: relative;
    display: inline-flex;
    flex: none;
    align-items: center;
    cursor: pointer;
}

.text {
    margin-left: 10px;
    font-size: 14px;
}

.alert-emails-switch input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.track {
    display: inline-flex;
    align-items: center;
    width: 36px;
    height: 20px;
    padding: 2px;
    border-radius: 999px;
    background: var(--nc-neutral-800);
    border: 1px solid var(--nc-divider);
    transition: background 0.15s ease;
}

.knob {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--nc-neutral-400);
    transition:
        transform 0.15s ease,
        background 0.15s ease;
}

input:checked + .track {
    background: color-mix(in srgb, var(--nc-accent) 35%, transparent);
    border-color: var(--nc-accent);
}

input:checked + .track .knob {
    transform: translateX(16px);
    background: var(--nc-accent);
}

input:focus-visible + .track {
    outline: 2px solid var(--nc-accent);
    outline-offset: 2px;
}

input:disabled + .track {
    opacity: 0.6;
    cursor: progress;
}

@media (prefers-reduced-motion: reduce) {
    .track,
    .knob {
        transition: none;
    }
}
</style>
