// no-business-rules: the CAP-001 boundary. See README.md for what it allows.

const ARITHMETIC = new Set(['+', '-', '*', '/', '%', '**']);
const CAPABILITY_CALLS = new Set(['read', 'write']);
const NUMBER_CASTS = new Set(['Number', 'parseFloat', 'parseInt']);
const GLOBALS = new Set(['window', 'globalThis', 'self']);

/** @param {any} node */
function unwrap(node) {
    while (node) {
        if (node.type === 'TSAsExpression' || node.type === 'TSNonNullExpression' || node.type === 'TSTypeAssertion' || node.type === 'TSSatisfiesExpression') {
            node = node.expression;
        } else if (node.type === 'AwaitExpression') {
            node = node.argument;
        } else if (node.type === 'UnaryExpression' && (node.operator === '+' || node.operator === '-')) {
            node = node.argument;
        } else if (node.type === 'CallExpression' && node.callee.type === 'Identifier' && NUMBER_CASTS.has(node.callee.name)) {
            node = node.arguments[0];
        } else {
            return node;
        }
    }
    return node;
}

/** @param {any} node true for read(...) or write(...), awaited or not */
function isCapabilityCall(node) {
    node = unwrap(node);
    return node?.type === 'CallExpression' && node.callee.type === 'Identifier' && CAPABILITY_CALLS.has(node.callee.name);
}

/** @param {any} node the identifier at the root of a.b.c */
function rootOf(node) {
    while (node?.type === 'MemberExpression') node = unwrap(node.object);
    return node?.type === 'Identifier' ? node.name : null;
}

/** @param {any} pattern */
function boundNames(pattern, out = []) {
    if (!pattern) return out;
    if (pattern.type === 'Identifier') out.push(pattern.name);
    else if (pattern.type === 'ObjectPattern') pattern.properties.forEach((p) => boundNames(p.type === 'RestElement' ? p.argument : p.value, out));
    else if (pattern.type === 'ArrayPattern') pattern.elements.forEach((e) => boundNames(e, out));
    else if (pattern.type === 'AssignmentPattern') boundNames(pattern.left, out);
    else if (pattern.type === 'RestElement') boundNames(pattern.argument, out);
    return out;
}

/** @type {import('eslint').Rule.RuleModule} */
export default {
    meta: {
        type: 'problem',
        docs: { description: 'Keep business rules on the server (CAP-001)' },
        schema: [
            {
                type: 'object',
                properties: { payloadArithmetic: { type: 'boolean' } },
                additionalProperties: false,
            },
        ],
        messages: {
            fetch: 'Call the server through read() or write() in app/call.ts, never fetch (CAP-004).',
            arithmetic:
                'Do not compute a value from two server values in the browser. Ask the capability to return it (CAP-001).',
        },
    },

    create(context) {
        const payloadArithmetic = context.options[0]?.payloadArithmetic === true;

        // Names holding a capability payload. `payload` is the screen prop convention.
        const roots = new Set(['payload']);
        // Names bound to a single value taken out of a payload by destructuring.
        const values = new Set();
        const pending = [];

        const isPayloadValue = (node) => {
            node = unwrap(node);
            if (node?.type === 'Identifier') return values.has(node.name);
            if (node?.type !== 'MemberExpression') return false;
            const root = rootOf(node);
            if (root === null) return false;
            if (roots.has(root) || values.has(root)) return true;
            // props.payload.x
            let inner = node;
            while (inner.type === 'MemberExpression' && unwrap(inner.object).type === 'MemberExpression') inner = unwrap(inner.object);
            return root === 'props' && inner !== node && !inner.computed && inner.property.name === 'payload';
        };

        const check = (node) => {
            if (ARITHMETIC.has(node.operator) && isPayloadValue(node.left) && isPayloadValue(node.right)) {
                context.report({ node, messageId: 'arithmetic' });
            }
        };

        const script = {
            CallExpression(node) {
                const callee = node.callee;
                const isFetch =
                    (callee.type === 'Identifier' && callee.name === 'fetch') ||
                    (callee.type === 'MemberExpression' &&
                        !callee.computed &&
                        callee.property.name === 'fetch' &&
                        callee.object.type === 'Identifier' &&
                        GLOBALS.has(callee.object.name));
                if (isFetch) context.report({ node, messageId: 'fetch' });

                // read(...).then((data) => ...)
                if (
                    payloadArithmetic &&
                    callee.type === 'MemberExpression' &&
                    !callee.computed &&
                    callee.property.name === 'then' &&
                    isCapabilityCall(callee.object)
                ) {
                    const handler = node.arguments[0];
                    if (handler && 'params' in handler && handler.params[0]?.type === 'Identifier') roots.add(handler.params[0].name);
                }
            },
        };

        if (!payloadArithmetic) return script;

        Object.assign(script, {
            VariableDeclarator(node) {
                if (!isCapabilityCall(node.init)) return;
                if (node.id.type === 'Identifier') roots.add(node.id.name);
                else boundNames(node.id).forEach((name) => values.add(name));
            },
            AssignmentExpression(node) {
                if (node.operator !== '=' || !isCapabilityCall(node.right)) return;
                const target = node.left.type === 'MemberExpression' ? rootOf(node.left) : node.left.type === 'Identifier' ? node.left.name : null;
                if (target) roots.add(target);
            },
            BinaryExpression(node) {
                pending.push(node);
            },
            'Program:exit'() {
                pending.forEach(check);
            },
        });

        // Template expressions run after the script is read, so every root is known.
        const services = context.sourceCode.parserServices;
        if (services?.defineTemplateBodyVisitor) {
            return services.defineTemplateBodyVisitor({ BinaryExpression: check }, script);
        }

        return script;
    },
};
