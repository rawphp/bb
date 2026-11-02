import { RuleTester } from 'eslint';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import { describe, it } from 'vitest';
import rule from './index.js';

RuleTester.describe = describe;
RuleTester.it = it;

const tester = new RuleTester({
    languageOptions: {
        parser: vueParser,
        parserOptions: { parser: tseslint.parser, ecmaVersion: 2022, sourceType: 'module' },
    },
});

const feature = 'resources/js/features/meal/MealScreen.ts';
const sfc = 'resources/js/features/meal/MealScreen.vue';
const inFeatures = { filename: feature, options: [{ payloadArithmetic: true }] };
const fetchError = [{ messageId: 'fetch' }];
const mathError = [{ messageId: 'arithmetic' }];

describe('no-business-rules', () => {
    tester.run('CAP-001 no-business-rules', rule, {
        valid: [
            { code: "import { read } from '@/app/call'; read('account_show');", filename: 'resources/js/app/x.ts' },
            { ...inFeatures, code: "const data = await read('meal_show'); const left = data.remaining; const next = index + 1;" },
            { ...inFeatures, code: "const data = await read('meal_show'); const n = data.items.length; const t = data.target - 1;" },
            { ...inFeatures, code: "const data = await read('meal_show'); const label = `${data.first} ${data.last}`;" },
            { filename: 'resources/js/design/Bar.ts', code: 'const w = payload.done / payload.total;' },
            {
                filename: sfc,
                options: [{ payloadArithmetic: true }],
                code: '<template><p>{{ payload.remaining }} of {{ payload.target }}</p><p>{{ index + 1 }}</p></template>',
            },
        ],
        invalid: [
            { filename: 'resources/js/app/x.ts', code: "fetch('/capabilities/account_show');", errors: fetchError },
            { filename: 'resources/js/features/a/x.ts', code: "window.fetch('/x'); globalThis.fetch('/y');", errors: [...fetchError, ...fetchError] },
            { ...inFeatures, code: "const data = await read('meal_show'); const left = data.target - data.eaten;", errors: mathError },
            { ...inFeatures, code: "const { protein, fat } = await read('meal_show'); const r = protein * fat;", errors: mathError },
            { ...inFeatures, code: "let meal; meal.value = await read('meal_show'); const s = meal.value.a + meal.value.b;", errors: mathError },
            { ...inFeatures, code: "function f() { return data.kcal % data.step; } const data = await write('meal_log', {});", errors: mathError },
            { ...inFeatures, code: "read('meal_show').then((d) => d.x - d.y);", errors: mathError },
            { ...inFeatures, code: "const made = await writeNow('token_create', {}); const left = made.days - made.used;", errors: mathError },
            { ...inFeatures, code: "const fresh = await readFresh('mesocycles_show'); const n = fresh.a * fresh.b;", errors: mathError },
            { ...inFeatures, code: 'const p = props.payload.done / props.payload.total;', errors: mathError },
            { ...inFeatures, code: 'const p = Number(payload.a) - parseFloat(payload.b as string);', errors: mathError },
            {
                filename: sfc,
                options: [{ payloadArithmetic: true }],
                code: '<template><p>{{ payload.target - payload.eaten }}</p></template>',
                errors: mathError,
            },
            {
                filename: sfc,
                options: [{ payloadArithmetic: true }],
                code: "<script setup lang=\"ts\">\nconst meal = ref(); meal.value = await read('meal_show');\n</script>\n<template><p>{{ meal.target - meal.eaten }}</p></template>",
                errors: mathError,
            },
        ],
    });
});
