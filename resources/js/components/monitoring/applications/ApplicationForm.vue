<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { invalidField } from '@/lib/fieldErrors';

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
            v-bind="invalidField(`${prefix}name`, errors[`${prefix}name`])"
        />
        <InputError
            size="sm"
            :field="`${prefix}name`"
            :message="errors[`${prefix}name`]"
        />
    </div>
</template>
