import Ajv2020 from 'ajv/dist/2020';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Tests only: what a screen sends must fit the write's schema snapshot
// (tests/fixtures/schemas/<name>.input.json), the same file CAP-032 holds to the DTO.

const ajv = new Ajv2020({ allErrors: true, strict: false });
// Vitest runs from the repository root.
const fixtures = resolve(process.cwd(), 'tests/fixtures');

/** The schema violations of input for the write `name`, as Ajv reports them; empty when it fits. */
export function inputViolations(name: string, input: unknown): string[] {
    const schema = JSON.parse(readFileSync(`${fixtures}/schemas/${name}.input.json`, 'utf8')) as object;
    const validate = ajv.compile(schema);

    return validate(input) ? [] : (validate.errors ?? []).map((error) => `${error.instancePath || '/'} ${error.message ?? ''}`);
}

/** A fixture state from tests/fixtures/screens/, parsed. */
export function screenFixture<T>(name: string): T {
    return JSON.parse(readFileSync(`${fixtures}/screens/${name}.json`, 'utf8')) as T;
}
