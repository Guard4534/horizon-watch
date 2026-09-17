<script setup lang="ts">
const model = defineModel<App.Data.Applications.ApplicationFormData>({
    required: true,
});

const { errors, prefix = '' } = defineProps<{
    // Inertia's flat error bag. The wizard nests this form under
    // "application", so the keys are prefixed; the edit page does not.
    errors: Record<string, string | undefined>;
    prefix?: string;
}>();
</script>

<template>
    <div
        class="grid"
        style="
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: var(--nc-space-4);
        "
    >
        <div class="nc-field">
            <label :for="`${prefix}name`">{{ $t('Name') }}</label>
            <input
                :id="`${prefix}name`"
                v-model="model.name"
                class="nc-input"
                type="text"
                autocomplete="off"
                placeholder="Invoicer"
            />
            <div
                v-if="errors[`${prefix}name`]"
                class="mt-1"
                style="font-size: 11px; color: var(--st-down)"
            >
                {{ errors[`${prefix}name`] }}
            </div>
        </div>

        <div class="nc-field">
            <label :for="`${prefix}host`">{{ $t('Host') }}</label>
            <input
                :id="`${prefix}host`"
                v-model="model.host"
                class="nc-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                placeholder="invoicer.example.com"
            />
            <div
                v-if="errors[`${prefix}host`]"
                class="mt-1"
                style="font-size: 11px; color: var(--st-down)"
            >
                {{ errors[`${prefix}host`] }}
            </div>
            <div
                v-else
                class="mt-1"
                style="font-size: 11px; color: var(--nc-neutral-600)"
            >
                {{
                    $t(
                        'No scheme: every environment carries its own Horizon URL.',
                    )
                }}
            </div>
        </div>
    </div>
</template>
