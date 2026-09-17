<script setup lang="ts">
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
    // Inertia's flat error bag. The wizard nests every row under
    // "environments.N.", the two environment pages use no prefix at all.
    errors: Record<string, string | undefined>;
    prefix?: string;
    // Whether a basic-auth password is already on file. The password itself
    // is never sent to the browser (EnvironmentSummaryData has no such
    // property): this only drives the "set / not set" indicator, and an
    // empty field on submit means "leave it as it is".
    hasPassword?: boolean;
}>();

// The prefix carries dots for the wizard's rows; ids and radio group names
// read better without them.
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
                <div
                    v-if="errors[`${prefix}name`]"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ errors[`${prefix}name`] }}
                </div>
                <div
                    v-else
                    class="mt-1"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
                >
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
                <div
                    v-if="errors[`${prefix}color`]"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ errors[`${prefix}color`] }}
                </div>
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
            <div
                v-if="errors[`${prefix}horizonUrl`]"
                class="mt-1"
                style="font-size: 11px; color: var(--st-down)"
            >
                {{ errors[`${prefix}horizonUrl`] }}
            </div>
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
                <div
                    v-if="errors[`${prefix}basicAuthUser`]"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ errors[`${prefix}basicAuthUser`] }}
                </div>
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
                <div
                    v-if="errors[`${prefix}basicAuthPassword`]"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ errors[`${prefix}basicAuthPassword`] }}
                </div>
                <div
                    v-else
                    class="mt-1 inline-flex items-center gap-[5px]"
                    style="font-size: 11px; color: var(--nc-neutral-600)"
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
                        min="5"
                        max="300"
                        step="1"
                    />
                    <span style="font-size: 12px; color: var(--nc-neutral-500)"
                        >{{ $t('seconds') }}
                    </span>
                </div>
                <div
                    v-if="errors[`${prefix}pollIntervalSeconds`]"
                    class="mt-1"
                    style="font-size: 11px; color: var(--st-down)"
                >
                    {{ errors[`${prefix}pollIntervalSeconds`] }}
                </div>
            </div>
        </div>

        <div>
            <label class="nc-radio" style="font-size: 13px">
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
            <div
                v-if="errors[`${prefix}pollingEnabled`]"
                class="mt-1"
                style="font-size: 11px; color: var(--st-down)"
            >
                {{ errors[`${prefix}pollingEnabled`] }}
            </div>
            <div
                v-else
                class="mt-1"
                style="font-size: 11px; color: var(--nc-neutral-600)"
            >
                {{
                    $t(
                        'Paused environments keep their last reading and are not contacted.',
                    )
                }}
            </div>
        </div>
    </div>
</template>
