<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { readFresh } from '@/app/call';
import { failureMessage } from '@/app/errors';
import type { RecipeImportInput, RecipeImportOutput } from '@/contracts/recipe_import';
import RecipeDraftView from './RecipeDraftView.vue';

// Import a recipe from a web page (RCP-003), ported from rp-clone's RecipeImportView: the
// address, then the confirm step. The server checks the address and reads the page; the
// script holds what was typed.
const props = withDefaults(defineProps<{ payload?: RecipeImportOutput }>(), { payload: undefined });

const router = useRouter();

const url = ref('');
const draft = ref<RecipeImportOutput | null>(props.payload ?? null);
const busy = ref(false);
const error = ref<string | null>(null);

watch(url, () => (error.value = null));

async function importRecipe(): Promise<void> {
    const address = url.value.trim();
    if (address === '' || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        draft.value = await readFresh<RecipeImportOutput>('recipe_import', { url: address } satisfies RecipeImportInput);
    } catch (failure) {
        error.value = failureMessage(failure, "Couldn't read that page. Try again.");
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <RecipeDraftView v-if="draft" :draft="draft" />
    <main v-else class="bb-screen-content flex flex-col gap-4">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <button type="button" data-test="cancel" class="min-h-tap justify-self-start text-accent" @click="router.back()">Cancel</button>
            <h1 class="text-center text-base font-semibold">Import from URL</h1>
        </header>

        <section aria-labelledby="import-address">
            <h2 id="import-address" class="pb-1 text-lg font-semibold">Web address</h2>
            <form class="rounded-2xl bg-surface px-4" @submit.prevent="importRecipe">
                <input
                    v-model="url"
                    type="url"
                    inputmode="url"
                    aria-label="Recipe web address"
                    placeholder="https://example.com/recipe"
                    autocapitalize="none"
                    autocomplete="url"
                    spellcheck="false"
                    :disabled="busy"
                    class="min-h-tap w-full bg-transparent placeholder:text-secondary-text focus:outline-none"
                />
            </form>
            <p v-if="error" role="alert" class="pt-1.5 text-sm text-tone-warning">{{ error }}</p>
            <p class="pt-1.5 text-sm text-secondary-text">We read the page, then match each ingredient to a food in the database for you to confirm.</p>
        </section>

        <button
            type="button"
            data-test="import"
            class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent disabled:opacity-40"
            :disabled="url.trim() === '' || busy"
            @click="importRecipe"
        >
            {{ busy ? 'Reading the page…' : 'Import' }}
        </button>
    </main>
</template>
