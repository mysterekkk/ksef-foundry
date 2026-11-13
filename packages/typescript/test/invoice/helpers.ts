import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const FIXTURES_DIR = fileURLToPath(new URL('../../../../fixtures/fa3', import.meta.url));
export const REPO_ROOT = fileURLToPath(new URL('../../../..', import.meta.url));

export function validCases(): string[] {
  return readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith('.input.json'))
    .map((f) => f.replace('.input.json', ''))
    .sort();
}

export function invalidCases(): string[] {
  return readdirSync(`${FIXTURES_DIR}/invalid`)
    .filter((f) => f.endsWith('.xml'))
    .map((f) => f.replace('.xml', ''))
    .sort();
}

export function readBytes(path: string): Buffer {
  return readFileSync(path);
}

export function readText(path: string): string {
  return readFileSync(path, 'utf8');
}

export function validXml(): string {
  return readText(`${FIXTURES_DIR}/01-single-line-23.expected.xml`);
}
