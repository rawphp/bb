import { describe, expect, it } from 'vitest';
import { inputViolations, screenFixture } from './schemas';

describe('inputViolations', () => {
    it('CAP-032 passes an input that fits the write\'s schema snapshot', () => {
        expect(inputViolations('account_delete', { confirmation: 'DELETE' })).toEqual([]);
    });

    it('CAP-032 reports an input the schema snapshot refuses', () => {
        expect(inputViolations('account_delete', { confirmation: 'delete' })).not.toEqual([]);
        expect(inputViolations('token_create', { id: 'x' })).not.toEqual([]);
    });

    it('CAP-002 loads a screen fixture state', () => {
        expect(screenFixture<{ enabled: boolean }>('agent_status_show.empty').enabled).toBe(false);
    });
});
