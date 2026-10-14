<script setup lang="ts">
import FieldError from '@/components/FieldError.vue';
import { PhLockSimple, PhLockSimpleOpen, PhWarning } from '@phosphor-icons/vue';
import { createReusableTemplate } from '@vueuse/core';
import { computed, watch } from 'vue';
import ColorPicker from '@/components/monitoring/applications/ColorPicker.vue';
import { invalidField } from '@/lib/fieldErrors';
import { looksLikeProduction } from '@/lib/monitoring';

const model = defineModel<App.Data.Applications.EnvironmentFormData>({
    required: true,
});

const auth = defineModel<boolean>('auth', { default: true });

const productionEdited = defineModel<boolean>('productionEdited', {
    default: false,
});

const {
    errors,
    prefix = '',
    hasPassword = false,
    compact = false,
    suggestProduction = false,
} = defineProps<{
    colors: App.Data.Pages.EnvironmentFormPageData['colors'];
    errors: Record<string, string | undefined>;
    prefix?: string;
    hasPassword?: boolean;
    compact?: boolean;
    suggestProduction?: boolean;
}>();

defineEmits<{
    urlInput: [];
}>();

const fieldId = (field: string) => `${prefix}${field}`.replace(/\./g, '-');

const errorOf = (field: string) => errors[`${prefix}${field}`];

const credentialsInClear = computed(
    () =>
        /^\s*http:\/\//i.test(model.value.horizonUrl ?? '') &&
        !!model.value.basicAuthUser?.trim(),
);

const toggleAuth = () => {
    auth.value = !auth.value;

    if (!auth.value) {
        model.value.basicAuthUser = null;
        model.value.basicAuthPassword = null;
    }
};

watch(
    () => model.value.name,
    (name) => {
        if (productionEdited.value) {
            return;
        }

        if (looksLikeProduction(name)) {
            model.value.isProduction = true;
        } else if (suggestProduction) {
            model.value.isProduction = false;
        }
    },
);

const [DefineName, NameField] = createReusableTemplate();
const [DefineColor, ColorField] = createReusableTemplate();
const [DefineUrl, UrlField] = createReusableTemplate();
const [DefineCredentials, CredentialFields] = createReusableTemplate();
const [DefineInterval, IntervalField] = createReusableTemplate();
const [DefineCollect, CollectField] = createReusableTemplate();
const [DefineProduction, ProductionField] = createReusableTemplate();
</script>

<template>
    <DefineName>
        <div class="nc-field">
            <label :for="fieldId('name')">{{
                compact ? $t('Environment name') : $t('Name')
            }}</label>
            <input
                :id="fieldId('name')"
                v-model="model.name"
                class="nc-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                placeholder="production"
                v-bind="invalidField(fieldId('name'), errorOf('name'))"
            />
            <FieldError :field="fieldId('name')" :message="errorOf('name')" />
            <div
                v-if="!compact && !errorOf('name')"
                class="nc-t-2xs nc-tone-faint mt-1"
            >
                {{
                    $t(
                        'Lowercase letters, digits and dashes: it becomes part of the URL.',
                    )
                }}
            </div>
        </div>
    </DefineName>

    <DefineColor>
        <div class="nc-field">
            <label :id="fieldId('color')">{{ $t('Color') }}</label>
            <ColorPicker
                v-model="model.color"
                :colors="colors"
                :name="fieldId('color')"
                :aria-labelledby="fieldId('color')"
                v-bind="invalidField(fieldId('color'), errorOf('color'))"
            />
            <FieldError :field="fieldId('color')" :message="errorOf('color')" />
        </div>
    </DefineColor>

    <DefineUrl>
        <div class="nc-field">
            <label :for="fieldId('horizonUrl')">{{ $t('Horizon URL') }}</label>
            <input
                :id="fieldId('horizonUrl')"
                v-model="model.horizonUrl"
                class="nc-input"
                :style="compact ? 'letter-spacing: 0.01em' : undefined"
                type="url"
                autocomplete="off"
                spellcheck="false"
                :placeholder="
                    compact
                        ? 'https://invoicer.example.com/horizon'
                        : 'https://production.invoicer.example.com/horizon'
                "
                @input="$emit('urlInput')"
                v-bind="
                    invalidField(fieldId('horizonUrl'), errorOf('horizonUrl'))
                "
            />
            <FieldError
                :field="fieldId('horizonUrl')"
                :message="errorOf('horizonUrl')"
            />
            <div
                v-if="credentialsInClear && !errorOf('horizonUrl')"
                class="nc-t-2xs mt-1 flex items-start"
                style="gap: 5px; color: var(--st-warn)"
            >
                <PhWarning :size="12" class="mt-[1px] flex-none" />
                {{
                    $t(
                        'The basic-auth password travels unencrypted over http://. Use https:// unless the network in between is trusted.',
                    )
                }}
            </div>
        </div>
    </DefineUrl>

    <DefineCredentials>
        <div
            :class="compact ? 'flex flex-1 flex-wrap' : 'contents'"
            :style="
                compact ? 'gap: var(--nc-space-2); min-width: 220px' : undefined
            "
        >
            <div :class="compact ? 'contents' : 'nc-field'">
                <label v-if="!compact" :for="fieldId('basicAuthUser')">{{
                    $t('Basic-auth username')
                }}</label>
                <input
                    :id="fieldId('basicAuthUser')"
                    v-model="model.basicAuthUser"
                    class="nc-input"
                    :class="compact ? 'min-w-0 flex-1' : undefined"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="
                        compact ? $t('Basic-auth username') : undefined
                    "
                    :aria-label="
                        compact ? $t('Basic-auth username') : undefined
                    "
                    v-bind="
                        invalidField(
                            fieldId('basicAuthUser'),
                            errorOf('basicAuthUser'),
                        )
                    "
                />
                <FieldError
                    v-if="!compact"
                    :field="fieldId('basicAuthUser')"
                    :message="errorOf('basicAuthUser')"
                />
            </div>

            <div :class="compact ? 'contents' : 'nc-field'">
                <label v-if="!compact" :for="fieldId('basicAuthPassword')">{{
                    $t('Basic-auth password')
                }}</label>
                <input
                    :id="fieldId('basicAuthPassword')"
                    v-model="model.basicAuthPassword"
                    class="nc-input"
                    :class="compact ? 'min-w-0 flex-1' : undefined"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="
                        compact ? $t('Basic-auth password') : undefined
                    "
                    :aria-label="
                        compact ? $t('Basic-auth password') : undefined
                    "
                    v-bind="
                        invalidField(
                            fieldId('basicAuthPassword'),
                            errorOf('basicAuthPassword'),
                        )
                    "
                />
                <template v-if="!compact">
                    <FieldError
                        :field="fieldId('basicAuthPassword')"
                        :message="errorOf('basicAuthPassword')"
                    />
                    <div
                        v-if="!errorOf('basicAuthPassword')"
                        class="nc-t-2xs nc-tone-faint mt-1 inline-flex items-center gap-[5px]"
                    >
                        <component
                            :is="hasPassword ? PhLockSimple : PhLockSimpleOpen"
                            :size="12"
                        />
                        {{
                            hasPassword
                                ? $t('Set · leave blank to keep it')
                                : $t(
                                      'Not set · stored encrypted, never shown back',
                                  )
                        }}
                    </div>
                </template>
            </div>
        </div>
    </DefineCredentials>

    <DefineInterval>
        <div
            :class="compact ? 'flex items-center' : 'nc-field'"
            :style="compact ? 'gap: var(--nc-space-2)' : undefined"
        >
            <label
                :for="fieldId('pollIntervalSeconds')"
                :class="compact ? 'nc-t-xs nc-tone-soft' : undefined"
                >{{ $t('Poll interval') }}</label
            >
            <div class="flex items-center" style="gap: var(--nc-space-2)">
                <input
                    :id="fieldId('pollIntervalSeconds')"
                    v-model.number="model.pollIntervalSeconds"
                    class="nc-input"
                    :style="
                        compact
                            ? 'max-width: 80px; min-height: 30px'
                            : 'max-width: 92px'
                    "
                    type="number"
                    min="15"
                    max="300"
                    step="15"
                    v-bind="
                        invalidField(
                            fieldId('pollIntervalSeconds'),
                            errorOf('pollIntervalSeconds'),
                        )
                    "
                />
                <span
                    :class="
                        compact
                            ? 'nc-t-xs nc-tone-soft'
                            : 'nc-t-xs nc-tone-muted'
                    "
                    >{{ $t('seconds') }}
                </span>
            </div>
            <FieldError
                v-if="!compact"
                :field="fieldId('pollIntervalSeconds')"
                :message="errorOf('pollIntervalSeconds')"
            />
        </div>
    </DefineInterval>

    <DefineCollect>
        <div :class="compact ? 'contents' : undefined">
            <label
                :class="compact ? 'nc-radio nc-t-xs' : 'nc-radio nc-t-sm'"
                :title="
                    compact
                        ? $t(
                              'Hidden from members who see everything except production.',
                          )
                        : undefined
                "
            >
                <input
                    :id="fieldId('pollingEnabled')"
                    v-model="model.pollingEnabled"
                    type="checkbox"
                    role="switch"
                    :aria-checked="model.pollingEnabled"
                    v-bind="
                        invalidField(
                            fieldId('pollingEnabled'),
                            errorOf('pollingEnabled'),
                        )
                    "
                />
                <span class="nc-dot" />
                {{ $t('Collect readings') }}
            </label>
            <template v-if="!compact">
                <FieldError
                    :field="fieldId('pollingEnabled')"
                    :message="errorOf('pollingEnabled')"
                />
                <div
                    v-if="!errorOf('pollingEnabled')"
                    class="nc-t-2xs nc-tone-faint mt-1"
                >
                    {{
                        $t(
                            'Paused environments keep their last reading, and the scheduler does not contact them.',
                        )
                    }}
                </div>
            </template>
        </div>
    </DefineCollect>

    <DefineProduction>
        <div :class="compact ? 'contents' : undefined">
            <label :class="compact ? 'nc-radio nc-t-xs' : 'nc-radio nc-t-sm'">
                <input
                    :id="fieldId('isProduction')"
                    v-model="model.isProduction"
                    type="checkbox"
                    @change="productionEdited = true"
                    v-bind="
                        invalidField(
                            fieldId('isProduction'),
                            errorOf('isProduction'),
                        )
                    "
                />
                <span class="nc-dot" />
                {{ $t('Production environment') }}
            </label>
            <template v-if="!compact">
                <FieldError
                    :field="fieldId('isProduction')"
                    :message="errorOf('isProduction')"
                />
                <div
                    v-if="!errorOf('isProduction')"
                    class="nc-t-2xs nc-tone-faint mt-1"
                >
                    {{
                        $t(
                            'Hidden from members who see everything except production.',
                        )
                    }}
                </div>
            </template>
        </div>
    </DefineProduction>

    <div v-if="compact">
        <div
            class="grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_auto]"
            style="gap: var(--nc-space-3)"
        >
            <NameField />
            <UrlField />
            <div class="flex items-start sm:pt-[22px]">
                <slot name="actions" />
            </div>
        </div>

        <div class="mt-[var(--nc-space-3)]">
            <ColorField />
        </div>

        <div
            class="mt-[var(--nc-space-3)] flex flex-wrap items-center"
            style="gap: var(--nc-space-3)"
        >
            <label class="nc-radio nc-t-xs">
                <input type="checkbox" :checked="auth" @change="toggleAuth" />
                <span class="nc-dot" />
                {{ $t('Basic auth') }}
            </label>
            <CredentialFields v-if="auth" />
        </div>
        <FieldError
            :field="fieldId('basicAuthUser')"
            :message="errorOf('basicAuthUser')"
        />
        <FieldError
            :field="fieldId('basicAuthPassword')"
            :message="errorOf('basicAuthPassword')"
        />

        <div
            class="mt-[var(--nc-space-3)] flex flex-wrap items-center"
            style="gap: var(--nc-space-4)"
        >
            <IntervalField />
            <CollectField />
            <ProductionField />
        </div>
        <FieldError
            :field="fieldId('pollIntervalSeconds')"
            :message="errorOf('pollIntervalSeconds')"
        />
        <FieldError
            :field="fieldId('pollingEnabled')"
            :message="errorOf('pollingEnabled')"
        />
        <FieldError
            :field="fieldId('isProduction')"
            :message="errorOf('isProduction')"
        />
    </div>

    <div v-else class="flex flex-col" style="gap: var(--nc-space-4)">
        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
                gap: var(--nc-space-4);
            "
        >
            <NameField />
            <ColorField />
        </div>

        <UrlField />

        <div
            class="grid"
            style="
                grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                gap: var(--nc-space-4);
            "
        >
            <CredentialFields />
            <IntervalField />
        </div>

        <CollectField />
        <ProductionField />
    </div>
</template>
