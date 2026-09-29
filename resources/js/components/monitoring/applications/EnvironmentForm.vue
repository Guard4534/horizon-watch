<script setup lang="ts">
import FieldError from '@/components/FieldError.vue';
import { PhLockSimple, PhLockSimpleOpen } from '@phosphor-icons/vue';
import ColorPicker from '@/components/monitoring/applications/ColorPicker.vue';

const model = defineModel<App.Data.Applications.EnvironmentFormData>({
    required: true,
});

const {
    errors,
    prefix = '',
    hasPassword = false,
} = defineProps<{
    colors: App.Data.Pages.EnvironmentFormPageData['colors'];
    errors: Record<string, string | undefined>;
    prefix?: string;
    hasPassword?: boolean;
}>();

const fieldId = (field: string) => `${prefix}${field}`.replace(/\./g, '-');
</script>

<template>
    <div class="flex flex-col" style="gap: var(--nc-space-4)">
        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
                gap: var(--nc-space-4);
            "
        >
            <div class="nc-field">
                <label :for="fieldId('name')">{{ $t('Name') }}</label>
                <input
                    :id="fieldId('name')"
                    v-model="model.name"
                    class="nc-input"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="production"
                />
                <FieldError
                    v-if="errors[`${prefix}name`]"
                    :message="errors[`${prefix}name`]"
                />
                <div v-else class="nc-t-2xs nc-tone-faint mt-1">
                    {{
                        $t(
                            'Lowercase letters, digits and dashes: it becomes part of the URL.',
                        )
                    }}
                </div>
            </div>

            <div class="nc-field">
                <label>{{ $t('Color') }}</label>
                <ColorPicker
                    v-model="model.color"
                    :colors="colors"
                    :name="fieldId('color')"
                />
                <FieldError :message="errors[`${prefix}color`]" />
            </div>
        </div>

        <div class="nc-field">
            <label :for="fieldId('horizonUrl')">{{ $t('Horizon URL') }}</label>
            <input
                :id="fieldId('horizonUrl')"
                v-model="model.horizonUrl"
                class="nc-input"
                type="url"
                autocomplete="off"
                spellcheck="false"
                placeholder="https://production.invoicer.example.com/horizon"
            />
            <FieldError :message="errors[`${prefix}horizonUrl`]" />
        </div>

        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                gap: var(--nc-space-4);
            "
        >
            <div class="nc-field">
                <label :for="fieldId('basicAuthUser')">{{
                    $t('Basic-auth username')
                }}</label>
                <input
                    :id="fieldId('basicAuthUser')"
                    v-model="model.basicAuthUser"
                    class="nc-input"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                />
                <FieldError :message="errors[`${prefix}basicAuthUser`]" />
            </div>

            <div class="nc-field">
                <label :for="fieldId('basicAuthPassword')">{{
                    $t('Basic-auth password')
                }}</label>
                <input
                    :id="fieldId('basicAuthPassword')"
                    v-model="model.basicAuthPassword"
                    class="nc-input"
                    type="password"
                    autocomplete="new-password"
                />
                <FieldError
                    v-if="errors[`${prefix}basicAuthPassword`]"
                    :message="errors[`${prefix}basicAuthPassword`]"
                />
                <div
                    v-else
                    class="nc-t-2xs nc-tone-faint mt-1 inline-flex items-center gap-[5px]"
                >
                    <component
                        :is="hasPassword ? PhLockSimple : PhLockSimpleOpen"
                        :size="12"
                    />
                    {{
                        hasPassword
                            ? $t('Set · leave blank to keep it')
                            : $t('Not set · stored encrypted, never shown back')
                    }}
                </div>
            </div>

            <div class="nc-field">
                <label :for="fieldId('pollIntervalSeconds')">{{
                    $t('Poll interval')
                }}</label>
                <div class="flex items-center" style="gap: var(--nc-space-2)">
                    <input
                        :id="fieldId('pollIntervalSeconds')"
                        v-model.number="model.pollIntervalSeconds"
                        class="nc-input"
                        style="max-width: 92px"
                        type="number"
                        min="15"
                        max="300"
                        step="1"
                    />
                    <span class="nc-t-xs nc-tone-muted"
                        >{{ $t('seconds') }}
                    </span>
                </div>
                <FieldError :message="errors[`${prefix}pollIntervalSeconds`]" />
            </div>
        </div>

        <div>
            <label class="nc-radio nc-t-sm">
                <input
                    :id="fieldId('pollingEnabled')"
                    v-model="model.pollingEnabled"
                    type="checkbox"
                    role="switch"
                    :aria-checked="model.pollingEnabled"
                />
                <span class="nc-dot" />
                {{ $t('Collect readings') }}
            </label>
            <FieldError
                v-if="errors[`${prefix}pollingEnabled`]"
                :message="errors[`${prefix}pollingEnabled`]"
            />
            <div v-else class="nc-t-2xs nc-tone-faint mt-1">
                {{
                    $t(
                        'Paused environments keep their last reading, and the scheduler does not contact them.',
                    )
                }}
            </div>
        </div>
    </div>
</template>
