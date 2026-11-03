// @vitest-environment node
import { ESLint } from 'eslint';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// The repo's own eslint.config.js, not the rule alone: what `npx eslint resources/js` lets through.
const CHAT = 'resources/js/app/chatTransport.ts';

async function businessRuleMessages(code: string) {
    const [result] = await new ESLint().lintText(code, { filePath: CHAT });

    return result!.messages.filter((message) => message.ruleId === 'bb/no-business-rules');
}

describe('eslint.config.js', () => {
    const source = readFileSync(CHAT, 'utf8');

    it('CAP-001 lets the chat transport\'s one fetch through', async () => {
        expect(await businessRuleMessages(source)).toEqual([]);
    });

    it('CAP-001 reports any other fetch in the chat transport', async () => {
        const code = `${source.replace(/\n*$/, '\n')}fetch('/x');\n`;

        const messages = await businessRuleMessages(code);

        expect(messages).toHaveLength(1);
        expect(messages[0]!.line).toBe(code.split('\n').length - 1);
    });
});
