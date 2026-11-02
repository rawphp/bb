import { describe, expect, it } from 'vitest';
import { ulid } from './ulid';

describe('ulid', () => {
    it('CAP-019 makes a 26-character Crockford ULID', () => {
        expect(ulid()).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
    });

    it('CAP-019 starts with the time, so ids sort by when they were made', () => {
        expect(ulid(0).slice(0, 10)).toBe('0000000000');
        expect(ulid(1_469_918_176_385).slice(0, 10)).toBe('01ARYZ6S41');
        expect(ulid(1).slice(0, 10) < ulid(2).slice(0, 10)).toBe(true);
    });

    it('CAP-019 never repeats', () => {
        expect(new Set(Array.from({ length: 100 }, () => ulid(5))).size).toBe(100);
    });
});
