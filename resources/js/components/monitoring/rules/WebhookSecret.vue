<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhCheck, PhCopy, PhKey, PhWarningCircle } from '@phosphor-icons/vue';
import { onBeforeUnmount, ref, useTemplateRef } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { regenerateSecret } from '@/routes/alert-settings';

const { secretSet, newSecret } = defineProps<{
    secretSet: boolean;
    newSecret: string | null;
}>();

const slug = useTeamSlug();

const field = useTemplateRef<HTMLInputElement>('field');
const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    if (newSecret === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(newSecret);
    } catch {
        field.value?.select();
        document.execCommand('copy');
    }

    copied.value = true;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = false), 2000);
}

onBeforeUnmount(() => clearTimeout(copiedTimer));

const confirming = ref(false);
const regenerating = ref(false);

function regenerate(): void {
    router.post(
        regenerateSecret(slug.value).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (regenerating.value = true),
            onFinish: () => {
                regenerating.value = false;
                confirming.value = false;
            },
        },
    );
}
</script>

<template>
    <div v-if="newSecret !== null" class="one-time" role="status">
        <div class="flex items-center gap-2" style="font-size: 13px">
            <PhKey :size="15" class="flex-none" />
            {{ $t('Webhook signing secret') }}
        </div>
        <div class="flex items-center gap-2">
            <input
                ref="field"
                class="nc-input nc-code"
                style="font-size: 13px"
                :value="newSecret"
                readonly
                autocomplete="off"
                spellcheck="false"
                :aria-label="$t('Webhook signing secret')"
                @focus="($event.target as HTMLInputElement).select()"
            />
            <button
                type="button"
                class="nc-btn nc-btn-secondary flex-none"
                style="font-size: 12px"
                @click="copy"
            >
                <PhCheck v-if="copied" :size="14" />
                <PhCopy v-else :size="14" />
                {{ copied ? $t('Copied') : $t('Copy') }}
            </button>
        </div>
        <div
            class="flex items-start gap-2"
            style="font-size: 12px; color: var(--st-warn)"
        >
            <PhWarningCircle :size="14" class="mt-px flex-none" />
            {{
                $t(
                    'Copy it now: it will not be shown again. Store it where the receiving service can verify signatures.',
                )
            }}
        </div>
    </div>

    <div
        v-else-if="secretSet"
        class="flex flex-wrap items-center gap-2"
        style="font-size: 12px; color: var(--nc-neutral-400)"
    >
        <PhKey :size="14" class="flex-none" />
        {{ $t('Signing secret set') }}
        <button
            type="button"
            class="nc-btn nc-btn-ghost"
            style="font-size: 12px"
            :disabled="regenerating"
            @click="confirming = true"
        >
            {{ $t('Regenerate') }}
        </button>
    </div>

    <Dialog
        :open="confirming"
        @update:open="(open) => !open && (confirming = false)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    $t('Regenerate the signing secret')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The current secret stops working at once: webhook deliveries are signed with the new one, which is shown only once.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="nc-btn nc-btn-secondary"
                    @click="confirming = false"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    :disabled="regenerating"
                    @click="regenerate"
                >
                    {{ $t('Regenerate') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.one-time {
    display: flex;
    flex-direction: column;
    gap: var(--nc-space-2);
    padding: var(--nc-space-3);
    border-radius: var(--nc-radius-md);
    border: 1px solid color-mix(in srgb, var(--st-warn) 55%, transparent);
    background: color-mix(in srgb, var(--st-warn) 8%, transparent);
}
</style>
