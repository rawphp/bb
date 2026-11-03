# no-business-rules

The lint half of CAP-001: the browser holds no business rule. Review is the other half; the rule catches the common slips, not every one.

## What it fails

1. **`fetch(` anywhere in `resources/js`**, including `window.fetch`, `globalThis.fetch` and `self.fetch`. Talk to the server through `read`, `write` and `writeNow` from `app/call.ts` (CAP-004). `app/call.ts` is exempt, because it is the transport. `app/chatTransport.ts`, the Coach chat's transport to the capabilities-ai routes, is not: its one `fetch(` to the chat routes carries a disable comment (AGT-021).
2. **Arithmetic between two capability values inside `features/`**: `+ - * / % **` where both sides come from a payload. A value comes from a payload when it is read from:
   - a variable set from `read(...)`, `write(...)` or `writeNow(...)`, awaited or not (`const day = await read(...)`, `meal.value = await read(...)`);
   - a name destructured from one (`const { protein, fat } = await read(...)`);
   - the parameter of `read(...).then((data) => ...)`;
   - the screen prop `payload`, as `payload.x` or `props.payload.x`.

   It checks `<script>` and `<template>`. `Number()`, `parseFloat()`, `parseInt()`, unary `+` and `-`, and TypeScript casts do not hide a value.

## What it allows

The four things CAP-001 lets Vue code do:

- render what a read capability returned (`{{ payload.remaining }}`);
- send what the user typed to a write capability;
- look a value up in a table the server sent (CAP-016);
- show the user's own pending input (OFF-009).

So these pass: one payload value with a literal or a local (`index + 1`, `payload.items.length`), and joining text with a template literal (`` `${payload.first} ${payload.last}` ``). Prefer a template literal to `+` for text, because `+` between two payload strings is reported.

If a screen needs a number that combines two server values, ask the capability to return it.
