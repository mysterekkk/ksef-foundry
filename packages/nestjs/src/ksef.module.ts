import { ConfigurableModuleBuilder, Global, Module, type DynamicModule, type Provider } from '@nestjs/common';
import { DEFAULT_KSEF_NAME, getKsefClientToken, getKsefRuntimeToken, getKsefServiceToken } from './constants.js';
import { KsefHealthIndicator } from './health.js';
import { KsefService } from './ksef.service.js';
import type { KsefModuleExtras, KsefModuleOptions } from './options.js';
import { KsefClientRegistry } from './registry.js';
import { KsefRuntime } from './runtime.js';

/** Global home of the registry: one instance for the whole application. */
@Global()
@Module({ providers: [KsefClientRegistry], exports: [KsefClientRegistry] })
// eslint-disable-next-line @typescript-eslint/no-extraneous-class -- Nest module marker class
export class KsefCoreModule {}

function clientProviders(name: string, optionsToken: string | symbol): { providers: Provider[]; exports: (string | symbol | typeof KsefService | typeof KsefHealthIndicator)[] } {
  const runtimeToken = getKsefRuntimeToken(name);
  const isDefault = name === DEFAULT_KSEF_NAME;
  const clientToken = getKsefClientToken(name);
  const serviceToken = isDefault ? KsefService : getKsefServiceToken(name);
  const providers: Provider[] = [
    {
      provide: runtimeToken,
      inject: [optionsToken, KsefClientRegistry],
      useFactory: (options: KsefModuleOptions, registry: KsefClientRegistry): KsefRuntime => {
        const runtime = new KsefRuntime(name, options);
        registry.add(runtime);
        return runtime;
      },
    },
    { provide: clientToken, inject: [runtimeToken], useFactory: (rt: KsefRuntime) => rt.client },
    {
      provide: serviceToken,
      inject: [runtimeToken, KsefClientRegistry],
      useFactory: (rt: KsefRuntime, registry: KsefClientRegistry): KsefService => registry.service(rt.name),
    },
  ];
  const exports: (string | symbol | typeof KsefService | typeof KsefHealthIndicator)[] = [clientToken, serviceToken];
  if (isDefault) {
    providers.push({
      provide: KsefHealthIndicator,
      inject: [KsefService],
      useFactory: (s: KsefService): KsefHealthIndicator => new KsefHealthIndicator(s),
    });
    exports.push(KsefHealthIndicator);
  }
  return { providers, exports };
}

export const { ConfigurableModuleClass, MODULE_OPTIONS_TOKEN } = new ConfigurableModuleBuilder<KsefModuleOptions>({
  moduleName: 'Ksef',
})
  .setClassMethodName('forRoot')
  .setFactoryMethodName('createKsefOptions')
  .setExtras<KsefModuleExtras>({}, (definition, extras) => {
    const name = extras.name ?? DEFAULT_KSEF_NAME;
    const extra = clientProviders(name, MODULE_OPTIONS_TOKEN);
    return {
      ...definition,
      global: extras.isGlobal === true,
      imports: [...(definition.imports ?? []), KsefCoreModule],
      providers: [...(definition.providers ?? []), ...extra.providers],
      exports: [...(definition.exports ?? []), ...extra.exports],
    };
  })
  .build();

/**
 * KSeF integration module.
 *
 * - `forRoot(options & { name?, isGlobal? })`, `forRootAsync({ name?, isGlobal?, imports, inject, useFactory | useClass | useExisting })`
 * - several named clients (tenants): call `forRoot`/`forRootAsync` once per `name`
 * - `forFeature(names)`: make already registered named clients injectable in a feature module
 */
@Module({})
export class KsefModule extends ConfigurableModuleClass {
  /** Re-exports named, already registered clients/services for injection in a feature module. */
  static forFeature(names: readonly string[]): DynamicModule {
    const providers: Provider[] = [];
    const exports: symbol[] = [];
    for (const n of names) {
      const ct = getKsefClientToken(n);
      const st = getKsefServiceToken(n);
      providers.push(
        { provide: ct, inject: [KsefClientRegistry], useFactory: (r: KsefClientRegistry) => r.get(n) },
        { provide: st, inject: [KsefClientRegistry], useFactory: (r: KsefClientRegistry) => r.service(n) },
      );
      exports.push(ct, st);
    }
    return { module: KsefModule, imports: [KsefCoreModule], providers, exports };
  }
}
