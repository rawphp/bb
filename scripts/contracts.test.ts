import { mkdtempSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { generate } from './contracts.mjs';

const schemaDir = 'tests/fixtures/schemas';
const contractsDir = 'resources/js/contracts';

describe('npm run contracts', () => {
    it('CAP-001 turns each schema snapshot into a TS type named after its DTO', async () => {
        const dir = mkdtempSync(join(tmpdir(), 'contracts-'));
        writeFileSync(
            join(dir, 'meal_show.output.json'),
            JSON.stringify({ type: 'object', additionalProperties: false, properties: { title: { type: 'string' }, kcal: { type: ['string', 'null'] } }, required: ['title', 'kcal'] }),
        );
        writeFileSync(join(dir, 'meal_show.input.json'), JSON.stringify({ type: 'object', additionalProperties: false, properties: [] }));

        const files: Map<string, string> = await generate(dir);

        expect([...files.keys()]).toEqual(['meal_show.ts']);
        const source = files.get('meal_show.ts')!;
        expect(source).toContain('export interface MealShowInput {}');
        expect(source).toMatch(/export interface MealShowOutput \{\s+title: string;\s+kcal: string \| null;\s+\}/);
    });

    it('CAP-001 types a bounded list as a list, not a tuple of every length', async () => {
        const dir = mkdtempSync(join(tmpdir(), 'contracts-'));
        writeFileSync(
            join(dir, 'recipe_generate.input.json'),
            JSON.stringify({ type: 'object', additionalProperties: false, properties: { ingredients: { type: 'array', items: { type: 'string' }, maxItems: 20 } }, required: ['ingredients'] }),
        );

        const source = (await generate(dir)).get('recipe_generate.ts')!;

        expect(source).toMatch(/ingredients: string\[\];/);
    });

    it('OFF-003 versions each output schema, so the read cache can tell a changed output shape', async () => {
        const dir = mkdtempSync(join(tmpdir(), 'contracts-'));
        const schema = (properties: object) => JSON.stringify({ type: 'object', additionalProperties: false, properties, required: Object.keys(properties) });
        const version = async () => /outputVersion = '([0-9a-f]{12})'/.exec((await generate(dir)).get('meal_show.ts')!)?.[1];

        writeFileSync(join(dir, 'meal_show.output.json'), schema({ title: { type: 'string' } }));
        const first = await version();
        writeFileSync(join(dir, 'meal_show.input.json'), schema({ id: { type: 'string' } }));
        const sameOutput = await version();
        writeFileSync(join(dir, 'meal_show.output.json'), schema({ title: { type: 'string' }, kcal: { type: 'number' } }));

        expect(first).toBeDefined();
        expect(sameOutput).toBe(first);
        expect(await version()).not.toBe(first);
    });

    it('CAP-001 keeps the committed contracts in step with the schema snapshots', async () => {
        const files: Map<string, string> = await generate(schemaDir);
        const committed = readdirSync(contractsDir).filter((file) => file.endsWith('.ts'));

        expect(committed.sort()).toEqual([...files.keys()].sort());
        for (const [file, source] of files) {
            expect(readFileSync(join(contractsDir, file), 'utf8'), `${file} is stale: run npm run contracts`).toBe(source);
        }
    });
});
