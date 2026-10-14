<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhCaretDown, PhWrench } from '@phosphor-icons/vue';
import type { Errors } from '@inertiajs/core';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { silenceDurationLabel } from '@/lib/maintenance';
import { silence } from '@/routes/environments';

const {
    environmentId,
    choices,
    block = false,
} = defineProps<{
    environmentId: string;
    choices: number[];
    block?: boolean;
}>();

const slug = useTeamSlug();
const busy = ref(false);

function silenceFor(minutes: number): void {
    router.post(
        silence({ current_team: slug.value, environment: environmentId }).url,
        { minutes },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onError: (errors: Errors) => {
                const message = Object.values(errors)[0];

                if (message) {
                    toast.error(message);
                }
            },
        },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="nc-btn nc-btn-secondary nc-t-xs"
                :class="{ 'silence-block': block }"
                :disabled="busy"
            >
                <PhWrench :size="13" />{{ $t('Silence for…') }}
                <PhCaretDown :size="10" style="opacity: 0.7" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuLabel class="nc-tone-muted" style="font-weight: 400">{{
                $t('Readings go on; no alert is sent meanwhile')
            }}</DropdownMenuLabel>
            <DropdownMenuItem
                v-for="minutes in choices"
                :key="minutes"
                @click="silenceFor(minutes)"
            >
                {{ silenceDurationLabel(minutes) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped>
.silence-block {
    flex: 1;
    justify-content: center;
    font-size: 12px;
    padding: 3px 8px;
}
</style>
