const DELTA_FIELDS = ['text', 'thinking', 'partial_json'];

export function createRedactor(secrets) {
  // Text withheld from partial deltas because it could be the start of a secret, by block and field.
  const held = new Map();
  const redactText = (text) => secrets.reduce((result, secret) => result.split(secret).join('[redacted]'), text);

  // A secret can be split across streamed deltas, so the longest tail of a delta that is the
  // start of a secret is held back until the next delta shows whether it completes one.
  const heldTail = (text) => {
    const longest = Math.max(0, ...secrets.map((secret) => secret.length - 1));
    for (let length = Math.min(text.length, longest); length > 0; length--) {
      const tail = text.slice(-length);
      if (secrets.some((secret) => secret.startsWith(tail))) return length;
    }
    return 0;
  };

  const withholdDelta = (message) => {
    const { index, delta } = message.event;
    const field = DELTA_FIELDS.find((name) => typeof delta?.[name] === 'string');
    if (!field) return message;
    const key = `${index}:${field}`;
    const text = redactText((held.get(key)?.text ?? '') + delta[field]);
    const keep = heldTail(text);
    held.set(key, { text: text.slice(text.length - keep), type: delta.type });
    return { ...message, event: { ...message.event, delta: { ...delta, [field]: text.slice(0, text.length - keep) } } };
  };

  const flushHeld = (message, index) => {
    const lines = [];
    for (const [key, { text, type }] of [...held]) {
      const [heldIndex, field] = key.split(':');
      if (index !== undefined && Number(heldIndex) !== index) continue;
      held.delete(key);
      if (text) lines.push({ ...message, event: { type: 'content_block_delta', index: Number(heldIndex), delta: { type, [field]: text } } });
    }
    return lines;
  };

  return { redactText, withholdDelta, flushHeld };
}
