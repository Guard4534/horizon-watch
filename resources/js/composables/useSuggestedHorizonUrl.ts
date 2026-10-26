import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watchEffect } from 'vue';
import {
    followSuggestion,
    leavesSuggestion,
    suggestHorizonUrl,
} from '@/lib/environments';

export type HorizonUrlBase = { horizonUrl: string; name: string };

export type SuggestedHorizonUrl = {
    suggestedUrl: ComputedRef<string>;
    urlEdited: Ref<boolean>;
    onUrlInput: () => void;
};

/**
 * A Horizon URL field prefilled with the URL suggested from a base environment for the name
 * being typed and the type chosen (the wizard's rule, through `suggestHorizonUrl`). It follows
 * the suggestion until the user types another URL; `onUrlInput` runs on every input of the field.
 */
export function useSuggestedHorizonUrl(
    url: Ref<string>,
    base: () => HorizonUrlBase | null,
    name: () => string,
    type: () => App.Enums.EnvironmentType,
): SuggestedHorizonUrl {
    const urlEdited = ref(false);

    const suggestedUrl = computed(() => {
        const from = base();

        return from === null
            ? ''
            : suggestHorizonUrl(from.horizonUrl, from.name, name(), type());
    });

    watchEffect(() => {
        url.value = followSuggestion(
            url.value,
            suggestedUrl.value,
            urlEdited.value,
        );
    });

    const onUrlInput = (): void => {
        urlEdited.value = leavesSuggestion(url.value, suggestedUrl.value);
    };

    return { suggestedUrl, urlEdited, onUrlInput };
}
