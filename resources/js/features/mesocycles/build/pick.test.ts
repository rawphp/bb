import { describe, expect, it } from 'vitest';
import { takePick } from './pick';

function changeOn(value: string): { event: Event; field: HTMLInputElement } {
    const field = document.createElement('input');
    field.value = value;
    const event = new Event('change');
    Object.defineProperty(event, 'target', { value: field });

    return { event, field };
}

describe('takePick', () => {
    it('CAP-012 puts the server\'s value back in the field and returns the pick when it differs', () => {
        const { event, field } = changeOn('7');

        expect(takePick(event, '5')).toBe('7');
        expect(field.value).toBe('5');
    });

    it('CAP-012 returns null when the pick is what the server already holds', () => {
        const { event, field } = changeOn('5');

        expect(takePick(event, '5')).toBeNull();
        expect(field.value).toBe('5');
    });
});
