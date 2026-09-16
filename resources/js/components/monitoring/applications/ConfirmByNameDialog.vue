<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PhWarningOctagon } from '@phosphor-icons/vue';
import { computed } from 'vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { required: true });

// The dialog primitive comes from the starter kit (reka-ui: focus trap,
// escape, aria wiring); only the skin is Nocturne, since the shadcn theme
// variables already resolve to --nc-* tokens.
const { resourceName, url } = defineProps<{
    // Typing this exactly is what arms the confirm button.
    resourceName: string;
    // Already translated by the caller, like EmptyState's.
    title: string;
    body: string;
    // What disappears with it, one line each.
    items: string[];
    confirmLabel: string;
    // The DELETE target; the body is ConfirmByNameData.
    url: string;
}>();

const form = useForm<App.Data.Applications.ConfirmByNameData>({ name: '' });

const matches = computed(() => form.name === resourceName);

// The dialog is kept mounted by its parent, so the typed name and any
// server-side mismatch error have to be cleared by hand on every close.
const onOpenChange = (next: boolean) => {
    open.value = next;

    if (!next) {
        form.reset();
        form.clearErrors();
    }
};

const submit = () => {
    form.delete(url, {
        preserveScroll: true,
        onSuccess: () => onOpenChange(false),
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="border-0"
            style="
                background: var(--nc-surface);
                box-shadow: var(--nc-shadow-md);
                color: var(--nc-text);
            "
        >
            <DialogHeader>
                <DialogTitle
                    class="inline-flex items-center gap-2 font-normal"
                    style="font-size: 17px"
                >
                    <PhWarningOctagon
                        :size="17"
                        :style="{ color: 'var(--st-down)' }"
                    />
                    {{ title }}
                </DialogTitle>
                <DialogDescription
                    style="font-size: 13px; color: var(--nc-neutral-400)"
                >
                    {{ body }}
                </DialogDescription>
            </DialogHeader>

            <ul
                class="flex flex-col"
                style="
                    gap: var(--nc-space-1);
                    font-size: 12px;
                    color: var(--nc-neutral-400);
                "
            >
                <li
                    v-for="item in items"
                    :key="item"
                    class="flex items-baseline"
                    style="gap: var(--nc-space-2)"
                >
                    <span
                        class="size-[4px] flex-none translate-y-[-3px] rounded-full"
                        style="background: var(--st-down)"
                    />
                    {{ item }}
                </li>
            </ul>

            <form class="nc-field" @submit.prevent="submit">
                <label for="confirm-by-name">
                    {{ $t('Type') }} <strong>{{ resourceName }}</strong>
                    {{ $t('to confirm') }}
                </label>
                <input
                    id="confirm-by-name"
                    v-model="form.name"
                    class="nc-input"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                />
                <div
                    v-if="form.errors.name"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ form.errors.name }}
                </div>
            </form>

            <DialogFooter>
                <DialogClose as-child>
                    <button type="button" class="nc-btn nc-btn-secondary">
                        {{ $t('Cancel') }}
                    </button>
                </DialogClose>
                <button
                    type="button"
                    class="nc-btn"
                    style="color: var(--st-down); border-color: var(--st-down)"
                    :disabled="!matches || form.processing"
                    @click="submit"
                >
                    {{ confirmLabel }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
