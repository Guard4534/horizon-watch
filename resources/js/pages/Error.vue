<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { PhArrowClockwise, PhHouse } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watchEffect } from 'vue';
import { home } from '@/routes';

const { status } = defineProps<{
    status: number;
}>();

const copy = computed(() => {
    switch (status) {
        case 403:
            return {
                title: trans('Access denied'),
                description: trans(
                    'Your account cannot open this page. If you were removed from the organization, ask one of its admins for a new invitation.',
                ),
            };
        case 404:
            return {
                title: trans('Page not found'),
                description: trans(
                    'This page does not exist any more: what it showed may have been renamed or deleted.',
                ),
            };
        case 419:
            return {
                title: trans('Page expired'),
                description: trans(
                    'The page stayed open too long. Go back, reload it and try again.',
                ),
            };
        case 503:
            return {
                title: trans('Under maintenance'),
                description: trans(
                    'Horizon Watch is being updated and will be back in a few minutes.',
                ),
            };
        default:
            return {
                title: trans('Something went wrong'),
                description: trans(
                    'An unexpected error stopped this page. Try again in a moment.',
                ),
            };
    }
});

const transient = computed(() => status >= 500);

watchEffect(() => {
    setLayoutProps(copy.value);
});

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="copy.title" />

    <div class="flex flex-wrap" style="gap: var(--nc-space-2)">
        <button
            v-if="transient"
            type="button"
            class="nc-btn nc-btn-primary flex-1 justify-center"
            @click="reload"
        >
            <PhArrowClockwise :size="14" />{{ $t('Try again') }}
        </button>
        <Link
            :href="home()"
            class="nc-btn flex-1 justify-center"
            :class="transient ? 'nc-btn-secondary' : 'nc-btn-primary'"
        >
            <PhHouse :size="14" />{{ $t('Go to the home page') }}
        </Link>
    </div>
    <p
        class="nc-t-2xs nc-tone-faint nc-num"
        style="margin: var(--nc-space-3) 0 0"
    >
        {{ $t('Error :status', { status: String(status) }) }}
    </p>
</template>
