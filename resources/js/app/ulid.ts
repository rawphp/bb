// A new row's id, made on the device so the outbox can queue a create and then
// an edit of the same row (CAP-019). 48 bits of milliseconds, 80 random bits,
// in Crockford base 32.
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function ulid(time = Date.now()): string {
    let head = '';
    for (let i = 0, rest = time; i < 10; i++, rest = Math.floor(rest / 32)) head = ALPHABET[rest % 32] + head;

    const random = crypto.getRandomValues(new Uint8Array(16));
    const tail = Array.from(random, (byte) => ALPHABET[byte % 32]).join('');

    return head + tail;
}
