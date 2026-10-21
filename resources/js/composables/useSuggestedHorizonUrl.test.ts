import { describe, expect, it } from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import type { HorizonUrlBase } from '@/composables/useSuggestedHorizonUrl';
import { useSuggestedHorizonUrl } from '@/composables/useSuggestedHorizonUrl';
import { typeForName } from '@/lib/environments';

const PRODUCTION: HorizonUrlBase = {
    horizonUrl: 'https://shop.example.com/horizon',
    name: 'production',
};

function addEnvironment(base: HorizonUrlBase | null = PRODUCTION) {
    const url = ref('');
    const name = ref('');
    const type = ref<App.Enums.EnvironmentType | null>(null);

    return {
        url,
        name,
        type,
        ...useSuggestedHorizonUrl(
            url,
            () => base,
            () => name.value,
            () => type.value ?? typeForName(name.value),
        ),
    };
}

describe('useSuggestedHorizonUrl', () => {
    it('prefills the URL from the base environment and follows the name', async () => {
        const { url, name, suggestedUrl } = addEnvironment();

        expect(url.value).toBe('');

        name.value = 'staging';
        await nextTick();

        expect(url.value).toBe('https://staging.shop.example.com/horizon');
        expect(suggestedUrl.value).toBe(url.value);

        name.value = 'prod-eu';
        await nextTick();

        expect(url.value).toBe('https://shop.example.com/horizon');
    });

    it('follows the type chosen, not the one the name suggests', async () => {
        const { url, name, type } = addEnvironment();

        name.value = 'live-preview';
        type.value = 'staging';
        await nextTick();

        expect(url.value).toBe('https://live-preview.shop.example.com/horizon');

        name.value = 'eu';
        type.value = 'production';
        await nextTick();

        expect(url.value).toBe('https://shop.example.com/horizon');
    });

    it('stops following once the user types another URL', async () => {
        const { url, name, urlEdited, onUrlInput } = addEnvironment();

        name.value = 'staging';
        await nextTick();

        url.value = 'https://horizon.example.net';
        onUrlInput();
        name.value = 'qa';
        await nextTick();

        expect(urlEdited.value).toBe(true);
        expect(url.value).toBe('https://horizon.example.net');
    });

    it('follows again once the user types the suggestion back', async () => {
        const { url, name, urlEdited, onUrlInput } = addEnvironment();

        name.value = 'staging';
        await nextTick();

        url.value = 'https://horizon.example.net';
        onUrlInput();
        url.value = 'https://staging.shop.example.com/horizon';
        onUrlInput();

        expect(urlEdited.value).toBe(false);

        name.value = 'qa';
        await nextTick();

        expect(url.value).toBe('https://qa.shop.example.com/horizon');
    });

    it('suggests nothing for an application without environments, and keeps what is typed', async () => {
        const { url, name, urlEdited, onUrlInput } = addEnvironment(null);

        name.value = 'staging';
        await nextTick();

        expect(url.value).toBe('');

        url.value = 'https://staging.billing.example.com/horizon';
        onUrlInput();
        name.value = 'qa';
        await nextTick();

        expect(urlEdited.value).toBe(true);
        expect(url.value).toBe('https://staging.billing.example.com/horizon');
    });
});
