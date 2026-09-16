<script setup lang="ts">
import { PhCheckCircle, PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

// The banner of an invitation that cannot be acted on. It says what happened
// and nothing more: InvitationPageData sends no organization name and no
// email for these states, so there is nothing else it could say.
const props = defineProps<{
    state: string;
}>();

const isGoodNews = computed(() => props.state === 'accepted');
const tone = computed(() =>
    isGoodNews.value ? 'var(--st-ok)' : 'var(--st-warn)',
);
</script>

<template>
    <div data-test="team-invitation-alert" :data-state="state">
        <Alert
            :style="{
                borderColor: tone,
                background: `color-mix(in srgb, ${tone} 10%, transparent)`,
            }"
        >
            <component
                :is="isGoodNews ? PhCheckCircle : PhWarningCircle"
                class="size-4"
                :style="{ color: tone }"
            />
            <AlertDescription style="color: var(--nc-text)">
                <template v-if="state === 'wrong_account'">
                    {{
                        $t(
                            'This invitation was sent to another address. Sign out, then open the link again.',
                        )
                    }}
                </template>
                <template v-else-if="state === 'expired'">
                    {{
                        $t(
                            'This invitation has expired. Ask an administrator to send a new one.',
                        )
                    }}
                </template>
                <template v-else-if="state === 'revoked'">
                    {{ $t('This invitation is no longer valid.') }}
                </template>
                <template v-else-if="state === 'accepted'">
                    {{ $t('This invitation has already been accepted.') }}
                </template>
            </AlertDescription>
        </Alert>
    </div>
</template>
