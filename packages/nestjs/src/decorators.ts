import { Inject } from '@nestjs/common';
import { KsefService } from './ksef.service.js';
import { getKsefClientToken, getKsefServiceToken } from './constants.js';

/** Injects the `KsefClient` (default, or the one registered under `name`). */
export function InjectKsef(name?: string): ParameterDecorator & PropertyDecorator {
  return Inject(getKsefClientToken(name));
}

/** Injects the `KsefService` of a named registration (the default one is injected by class: `KsefService`). */
export function InjectKsefService(name?: string): ParameterDecorator & PropertyDecorator {
  return Inject(name === undefined ? KsefService : getKsefServiceToken(name));
}
