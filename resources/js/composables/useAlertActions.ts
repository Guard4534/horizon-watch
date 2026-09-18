import { router } from '@inertiajs/vue3';
import type { Errors } from '@inertiajs/core';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { handle, mute, unmute } from '@/routes/alerts';

const busyAlerts = ref(new Set<string>());

export function useAlertActions(): {
    busy: Ref<Set<string>>;
    muteAlert: (
        alert: App.Data.Monitoring.AlertData,
        duration: App.Enums.MuteDuration,
    ) => void;
    unmuteAlert: (alert: App.Data.Monitoring.AlertData) => void;
    handleAlert: (alert: App.Data.Monitoring.AlertData) => void;
} {
    const slug = useTeamSlug();

    function options(alert: App.Data.Monitoring.AlertData) {
        return {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                busyAlerts.value = new Set(busyAlerts.value).add(alert.id);
            },
            onFinish: () => {
                const next = new Set(busyAlerts.value);
                next.delete(alert.id);
                busyAlerts.value = next;
            },
            onError: (errors: Errors) => {
                const message = Object.values(errors)[0];

                if (message) {
                    toast.error(message);
                }
            },
        };
    }

    function route(alert: App.Data.Monitoring.AlertData) {
        return { current_team: slug.value, alert: alert.id };
    }

    return {
        busy: busyAlerts,
        muteAlert: (alert, duration) =>
            router.post(mute(route(alert)).url, { duration }, options(alert)),
        unmuteAlert: (alert) =>
            router.delete(unmute(route(alert)).url, options(alert)),
        handleAlert: (alert) =>
            router.post(handle(route(alert)).url, {}, options(alert)),
    };
}
