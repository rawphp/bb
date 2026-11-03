import { expect } from 'vitest';

// Checks a read's or write's input against its schema snapshot: every key is in
// the schema with the right type and enum value, and every required key is there.
type Property = { type?: string | string[]; enum?: unknown[] };
type Schema = { properties: Record<string, Property>; required?: string[]; additionalProperties?: boolean };

export function expectMatchesInputSchema(schema: Schema, input: Record<string, unknown>): void {
    for (const [key, value] of Object.entries(input)) {
        if (schema.additionalProperties === false) expect(schema.properties, `${key} is not in the schema`).toHaveProperty(key);
        if (!(key in schema.properties)) continue;
        const property = schema.properties[key]!;
        // A nullable field's type is a list, such as ["string", "null"].
        if (Array.isArray(property.type)) expect(property.type).toContain(value === null ? 'null' : typeof value);
        else if (property.type) expect(typeof value).toBe(property.type);
        if (property.enum) expect(property.enum).toContain(value);
    }
    for (const key of schema.required ?? []) expect(input).toHaveProperty(key);
}
