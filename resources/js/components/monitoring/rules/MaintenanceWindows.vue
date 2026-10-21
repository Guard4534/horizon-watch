<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { PhWrench } from '@phosphor-icons/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import EnvPill from '@/components/nocturne/EnvPill.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useLocale } from '@/composables/useLocale';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatInstant } from '@/lib/datetime';
import { invalidField } from '@/lib/fieldErrors';
import { fromLocalInput, toLocalInput } from '@/lib/maintenance';
import {
    destroy as endWindow,
    store as storeWindow,
} from '@/routes/maintenance-windows';

const { windows, targets, maxDays } = defineProps<{
    windows: App.Data.Monitoring.MaintenanceWindowData[];
    targets: App.Data.Monitoring.MaintenanceTargetData[];
    maxDays: number;
}>();

const slug = useTeamSlug();
const id = useId();
const { locale } = useLocale();

type WindowForm = {
    target: string;
    startsAt: string;
    endsAt: string;
    reason: string;
};

function targetKey(target: App.Data.Monitoring.MaintenanceTargetData): string {
    return `${target.scope}:${target.id ?? ''}`;
}

function targetLabel(
    target: App.Data.Monitoring.MaintenanceTargetData,
): string {
    switch (target.scope) {
        case 'organization':
            return trans('Whole organization');
        case 'application':
            return trans(':application / every environment', {
                application: target.applicationName ?? '',
            });
        case 'environment':
            return `${target.applicationName} / ${target.environmentName}`;
    }
}

function fresh(): WindowForm {
    const start = new Date();
    start.setSeconds(0, 0);

    return {
        target: targets.length === 1 ? targetKey(targets[0]) : '',
        startsAt: toLocalInput(start),
        endsAt: toLocalInput(new Date(start.getTime() + 60 * 60 * 1000)),
        reason: '',
    };
}

const form = useForm<WindowForm>(fresh());

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const targetError = computed(() => errors.value.scope ?? errors.value.target);

function schedule(): void {
    const target = targets.find(
        (candidate) => targetKey(candidate) === form.target,
    );

    if (!target) {
        form.setError('target', trans('Choose what the window covers.'));

        return;
    }

    form.transform((data): App.Data.Alerts.MaintenanceWindowInputData => ({
        scope: target.scope,
        target: target.id,
        startsAt: fromLocalInput(data.startsAt),
        endsAt: fromLocalInput(data.endsAt),
        reason: data.reason.trim() === '' ? null : data.reason.trim(),
    })).post(storeWindow(slug.value).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(fresh());
            form.reset();
        },
    });
}

const busy = ref<number | null>(null);

function end(maintenance: App.Data.Monitoring.MaintenanceWindowData): void {
    router.delete(
        endWindow({ current_team: slug.value, window: maintenance.id }).url,
        {
            preserveScroll: true,
            onStart: () => (busy.value = maintenance.id),
            onFinish: () => (busy.value = null),
        },
    );
}

function period(
    maintenance: App.Data.Monitoring.MaintenanceWindowData,
): string {
    return `${formatInstant(maintenance.startsAt, locale.value)} → ${formatInstant(maintenance.endsAt, locale.value)}`;
}
</script>

<template>
    <SectionCard :title="$t('Maintenance windows')">
        <p class="nc-t-xs nc-tone-muted intro">
            {{
                $t(
                    'Readings go on during a window, but the alerts of what it covers open muted until it ends and nothing is sent. An alert still open afterwards follows the usual rules.',
                )
            }}
        </p>

        <form
            v-if="targets.length"
            class="window-form"
            novalidate
            @submit.prevent="schedule"
        >
            <div class="nc-field wide">
                <label :for="`${id}-target`">{{ $t('Covers') }}</label>
                <select
                    :id="`${id}-target`"
                    v-model="form.target"
                    class="nc-input"
                    v-bind="invalidField(`${id}-target`, targetError)"
                >
                    <option value="" disabled>
                        {{ $t('Choose…') }}
                    </option>
                    <option
                        v-for="target in targets"
                        :key="targetKey(target)"
                        :value="targetKey(target)"
                    >
                        {{ targetLabel(target) }}
                    </option>
                </select>
                <InputError
                    v-if="targetError"
                    size="sm"
                    :field="`${id}-target`"
                    :message="targetError"
                />
            </div>

            <div class="nc-field wide">
                <label :for="`${id}-reason`">{{ $t('Reason') }}</label>
                <input
                    :id="`${id}-reason`"
                    v-model="form.reason"
                    class="nc-input"
                    type="text"
                    maxlength="255"
                    autocomplete="off"
                    :placeholder="$t('Deploy, database upgrade…')"
                    v-bind="invalidField(`${id}-reason`, errors.reason)"
                />
                <InputError
                    v-if="errors.reason"
                    size="sm"
                    :field="`${id}-reason`"
                    :message="errors.reason"
                />
            </div>

            <div class="nc-field">
                <label :for="`${id}-starts`">{{ $t('From') }}</label>
                <input
                    :id="`${id}-starts`"
                    v-model="form.startsAt"
                    class="nc-input nc-num"
                    type="datetime-local"
                    v-bind="invalidField(`${id}-starts`, errors.startsAt)"
                />
                <InputError
                    v-if="errors.startsAt"
                    size="sm"
                    :field="`${id}-starts`"
                    :message="errors.startsAt"
                />
            </div>

            <div class="nc-field">
                <label :for="`${id}-ends`">{{ $t('Until') }}</label>
                <input
                    :id="`${id}-ends`"
                    v-model="form.endsAt"
                    class="nc-input nc-num"
                    type="datetime-local"
                    v-bind="invalidField(`${id}-ends`, errors.endsAt)"
                />
                <InputError
                    v-if="errors.endsAt"
                    size="sm"
                    :field="`${id}-ends`"
                    :message="errors.endsAt"
                />
                <div v-else class="field-hint">
                    {{
                        $t('at most :days days, in your local time', {
                            days: String(maxDays),
                        })
                    }}
                </div>
            </div>

            <div class="nc-actions">
                <button
                    type="submit"
                    class="nc-btn nc-btn-primary"
                    :disabled="form.processing"
                >
                    <PhWrench :size="13" />{{ $t('Schedule') }}
                </button>
            </div>
        </form>

        <div
            v-if="!windows.length"
            class="nc-t-xs nc-tone-muted"
            :style="{ marginTop: targets.length ? 'var(--nc-space-4)' : 0 }"
        >
            {{ $t('No maintenance window is planned.') }}
        </div>

        <div
            v-else
            class="overflow-x-auto"
            :style="{ marginTop: targets.length ? 'var(--nc-space-4)' : 0 }"
        >
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Covers') }}</th>
                        <th>{{ $t('When') }}</th>
                        <th>{{ $t('Reason') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="maintenance in windows"
                        :key="maintenance.id"
                        :class="{ 'is-ended': maintenance.ended }"
                    >
                        <td class="nc-t-sm">
                            <span
                                v-if="maintenance.scope === 'organization'"
                                class="whitespace-nowrap"
                                >{{ $t('Whole organization') }}</span
                            >
                            <span
                                v-else-if="
                                    maintenance.environmentName &&
                                    maintenance.environmentType
                                "
                                class="inline-flex flex-wrap items-center gap-[6px]"
                            >
                                {{ maintenance.applicationName }} /
                                <EnvPill
                                    :name="maintenance.environmentName"
                                    :type="maintenance.environmentType"
                                    :size="11"
                                />
                            </span>
                            <span v-else>{{
                                $t(':application / every environment', {
                                    application:
                                        maintenance.applicationName ?? '',
                                })
                            }}</span>
                        </td>
                        <td class="nc-t-xs nc-num whitespace-nowrap">
                            <span
                                v-if="maintenance.active"
                                class="nc-tag nc-tag-sm nc-tag-accent"
                                >{{ $t('Under way') }}</span
                            >
                            <span
                                v-else-if="!maintenance.ended"
                                class="nc-tag nc-tag-sm nc-tag-neutral"
                                >{{ $t('Planned') }}</span
                            >
                            <span
                                v-else
                                class="nc-tag nc-tag-sm nc-tag-outline"
                                >{{ $t('Ended') }}</span
                            >
                            <span
                                class="nc-tone-soft"
                                style="margin-left: 6px"
                                >{{ period(maintenance) }}</span
                            >
                        </td>
                        <td class="nc-t-xs nc-tone-soft">
                            <span v-if="maintenance.reason">{{
                                maintenance.reason
                            }}</span>
                            <span v-else class="nc-tone-faint">—</span>
                            <span
                                v-if="maintenance.createdBy"
                                class="nc-tone-muted block"
                                >{{
                                    $t('by :name', {
                                        name: maintenance.createdBy.name,
                                    })
                                }}</span
                            >
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button
                                v-if="maintenance.canDelete"
                                type="button"
                                class="nc-btn nc-btn-ghost nc-t-xs"
                                :class="
                                    maintenance.active
                                        ? 'nc-tone-soft'
                                        : 'nc-tone-down'
                                "
                                :disabled="busy === maintenance.id"
                                @click="end(maintenance)"
                            >
                                {{
                                    maintenance.active
                                        ? $t('End now')
                                        : $t('Delete')
                                }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>

<style scoped>
.intro {
    margin-bottom: var(--nc-space-4);
    line-height: 1.5;
}

.window-form {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: var(--nc-space-4);
    align-items: start;
}

.window-form > * {
    grid-column: span 2;
}

.window-form > .wide {
    grid-column: span 3;
}

.window-form > .nc-actions {
    align-self: end;
}

@media (max-width: 899px) {
    .window-form {
        grid-template-columns: minmax(0, 1fr);
    }

    .window-form > *,
    .window-form > .wide {
        grid-column: auto;
    }
}

.is-ended {
    opacity: 0.65;
}

.field-hint {
    margin-top: 4px;
    font-size: 11px;
    color: var(--nc-text-faint);
}
</style>
