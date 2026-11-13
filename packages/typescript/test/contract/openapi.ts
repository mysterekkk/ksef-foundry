import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

type Json = Record<string, unknown>;

export const spec = JSON.parse(
  readFileSync(fileURLToPath(new URL('../../../../schemas/openapi/test.json', import.meta.url)), 'utf8'),
) as { paths: Record<string, Record<string, Json>>; components: { schemas: Record<string, Json> } };

function resolve(schema: Json): Json {
  const ref = schema['$ref'];
  if (typeof ref === 'string') {
    const name = ref.split('/').pop() ?? '';
    const target = spec.components.schemas[name];
    if (target === undefined) throw new Error(`unresolved ref ${ref}`);
    return resolve(target);
  }
  return schema;
}

/** Example payload of the 2xx JSON response of an operation (`example` or first of `examples`). */
export function responseExample(path: string, method: string, status: string): unknown {
  const op = spec.paths[path]?.[method] as { responses: Record<string, { content?: Record<string, Json> }> } | undefined;
  const media = op?.responses[status]?.content?.['application/json'];
  if (media === undefined) throw new Error(`no ${status} json response for ${method} ${path}`);
  if (media['example'] !== undefined) return media['example'];
  const examples = media['examples'] as Record<string, { value: unknown }> | undefined;
  const first = examples === undefined ? undefined : Object.values(examples)[0];
  if (first === undefined) throw new Error(`no example for ${method} ${path}`);
  return first.value;
}

export function requestExample(path: string, method: string): unknown {
  const op = spec.paths[path]?.[method] as { requestBody?: { content: Record<string, Json> } } | undefined;
  const media = op?.requestBody?.content['application/json'];
  if (media === undefined) throw new Error(`no json request for ${method} ${path}`);
  if (media['example'] !== undefined) return media['example'];
  const examples = media['examples'] as Record<string, { value: unknown }> | undefined;
  const first = examples === undefined ? undefined : Object.values(examples)[0];
  if (first === undefined) throw new Error(`no request example for ${method} ${path}`);
  return first.value;
}

export function requestSchema(path: string, method: string): Json {
  const op = spec.paths[path]?.[method] as { requestBody?: { content: Record<string, { schema: Json }> } } | undefined;
  const s = op?.requestBody?.content['application/json']?.schema;
  if (s === undefined) throw new Error(`no request schema for ${method} ${path}`);
  return s;
}

/** Minimal OpenAPI 3.0 schema validator: type, required, enum, pattern, length, range, arrays, allOf, nullable. */
export function validate(value: unknown, schema: Json, at = '$'): string[] {
  const s = resolve(schema);
  const errors: string[] = [];
  if (Array.isArray(s['allOf'])) {
    for (const sub of s['allOf'] as Json[]) errors.push(...validate(value, sub, at));
    return errors;
  }
  if (value === null || value === undefined) {
    if (s['nullable'] === true) return errors;
    if (value === undefined) return [`${at}: missing`];
    return [`${at}: null not allowed`];
  }
  const type = s['type'];
  if (Array.isArray(s['enum']) && !(s['enum'] as unknown[]).includes(value)) errors.push(`${at}: ${JSON.stringify(value)} not in enum`);
  if (type === 'string') {
    if (typeof value !== 'string') return [`${at}: expected string`];
    if (typeof s['pattern'] === 'string' && !new RegExp(s['pattern']).test(value)) errors.push(`${at}: pattern mismatch`);
    if (typeof s['minLength'] === 'number' && value.length < s['minLength']) errors.push(`${at}: too short`);
    if (typeof s['maxLength'] === 'number' && value.length > s['maxLength']) errors.push(`${at}: too long`);
    if (s['format'] === 'date-time' && Number.isNaN(Date.parse(value))) errors.push(`${at}: bad date-time`);
  } else if (type === 'integer' || type === 'number') {
    if (typeof value !== 'number') return [`${at}: expected number`];
    if (type === 'integer' && !Number.isInteger(value)) errors.push(`${at}: expected integer`);
    if (typeof s['minimum'] === 'number' && value < s['minimum']) errors.push(`${at}: below minimum`);
    if (typeof s['maximum'] === 'number' && value > s['maximum']) errors.push(`${at}: above maximum`);
  } else if (type === 'boolean') {
    if (typeof value !== 'boolean') errors.push(`${at}: expected boolean`);
  } else if (type === 'array') {
    if (!Array.isArray(value)) return [`${at}: expected array`];
    value.forEach((v, i) => errors.push(...validate(v, (s['items'] as Json | undefined) ?? {}, `${at}[${String(i)}]`)));
    if (typeof s['maxItems'] === 'number' && value.length > s['maxItems']) errors.push(`${at}: too many items`);
  } else if (type === 'object' || s['properties'] !== undefined) {
    if (typeof value !== 'object' || Array.isArray(value)) return [`${at}: expected object`];
    const obj = value as Json;
    for (const req of (s['required'] as string[] | undefined) ?? []) {
      if (obj[req] === undefined) errors.push(`${at}.${req}: required but missing`);
    }
    const props = (s['properties'] as Record<string, Json> | undefined) ?? {};
    for (const [k, v] of Object.entries(obj)) {
      const ps = props[k];
      if (ps !== undefined) errors.push(...validate(v, ps, `${at}.${k}`));
    }
  }
  return errors;
}

export function schemaOf(name: string): Json {
  const s = spec.components.schemas[name];
  if (s === undefined) throw new Error(`unknown schema ${name}`);
  return s;
}
