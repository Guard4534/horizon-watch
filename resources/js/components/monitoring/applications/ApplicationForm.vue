<script setup lang="ts">
import FieldError from '@/components/FieldError.vue';

const model = defineModel<App.Data.Applications.ApplicationFormData>({
    required: true,
});

const {
    errors,
    prefix = '',
    wizard = false,
} = defineProps<{
    errors: Record<string, string | undefined>;
    prefix?: string;
    wizard?: boolean;
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
            <label :for="`${prefix}name`">{{
                wizard ? $t('Application name') : $t('Name')
            }}</label>
            <input
                :id="`${prefix}name`"
                v-model="model.name"
                class="nc-input"
                type="text"
                autocomplete="off"
                placeholder="Invoicer"
            />
            <FieldError :message="errors[`${prefix}name`]" />
        </div>

        <div class="nc-field">
            <label :for="`${prefix}host`">{{
                wizard ? $t('Primary domain') : $t('Host')
            }}</label>
            <input
                :id="`${prefix}host`"
                v-model="model.host"
                class="nc-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                placeholder="invoicer.example.com"
            />
            <FieldError
                v-if="errors[`${prefix}host`]"
                :message="errors[`${prefix}host`]"
            />
            <div v-else-if="wizard" class="nc-t-2xs nc-tone-faint mt-1">
                {{
                    $t(
                        'No scheme. It also suggests the URL of each environment.',
                    )
                }}
            </div>
            <div v-else class="nc-t-2xs nc-tone-faint mt-1">
                {{
                    $t(
                        'No scheme: every environment carries its own Horizon URL.',
                    )
                }}
            </div>
        </div>

        <slot />
    </div>
</template>
