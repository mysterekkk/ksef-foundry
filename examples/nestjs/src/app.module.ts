import { Module } from '@nestjs/common';
import { ConfigModule, ConfigService } from '@nestjs/config';
import { APP_FILTER } from '@nestjs/core';
import { KsefExceptionFilter, KsefModule } from '@luroweb/nestjs-ksef-foundry';
import { DemoController } from './demo.controller.js';
import { HealthController } from './health.controller.js';
import { InvoicesController } from './invoices.controller.js';
import { ksefOptionsFrom } from './ksef.config.js';
import { LimitsController } from './limits.controller.js';
import { UpoController } from './upo.controller.js';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true, envFilePath: ['.env'] }),
    KsefModule.forRootAsync({
      imports: [ConfigModule],
      inject: [ConfigService],
      useFactory: (config: ConfigService) => ksefOptionsFrom({ get: (k) => config.get<string>(k) }),
    }),
  ],
  controllers: [InvoicesController, UpoController, LimitsController, HealthController, DemoController],
  // Every SDK exception thrown in a controller becomes a clean HTTP response (429 + Retry-After, 403, 409, ...).
  providers: [{ provide: APP_FILTER, useClass: KsefExceptionFilter }],
})
export class AppModule {}
