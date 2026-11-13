/** Name used when `KsefModule.forRoot` is called without `name`. */
export const DEFAULT_KSEF_NAME = 'default';

/** Injection token of the default `KsefClient` (single long-lived instance per module). */
export const KSEF_CLIENT = Symbol.for('@luroweb/nestjs-ksef-foundry:KSEF_CLIENT');

const clientTokens = new Map<string, symbol>();
const serviceTokens = new Map<string, symbol>();
const runtimeTokens = new Map<string, symbol>();

function tokenFor(cache: Map<string, symbol>, prefix: string, name: string): symbol {
  let t = cache.get(name);
  if (t === undefined) {
    t = Symbol.for(`@luroweb/nestjs-ksef-foundry:${prefix}:${name}`);
    cache.set(name, t);
  }
  return t;
}

/** Token of the `KsefClient` registered under `name` (`undefined`/default name -> {@link KSEF_CLIENT}). */
export function getKsefClientToken(name?: string): symbol {
  return name === undefined || name === DEFAULT_KSEF_NAME ? KSEF_CLIENT : tokenFor(clientTokens, 'CLIENT', name);
}

/** Token of the `KsefService` registered under `name` (default name -> the `KsefService` class itself). */
export function getKsefServiceToken(name: string): symbol {
  return tokenFor(serviceTokens, 'SERVICE', name);
}

/** @internal */
export function getKsefRuntimeToken(name: string): symbol {
  return tokenFor(runtimeTokens, 'RUNTIME', name);
}
