import { NestFactory } from '@nestjs/core';
import type { NestExpressApplication } from '@nestjs/platform-express';
import { AppModule } from './app.module.js';

/** Creates the configured application (also used by the live test). Does not listen. */
export async function createApp(): Promise<NestExpressApplication> {
  const app = await NestFactory.create<NestExpressApplication>(AppModule, { abortOnError: false });
  // invoices travel as raw XML
  app.useBodyParser('text', { type: ['application/xml', 'text/xml'], limit: '5mb' });
  app.enableShutdownHooks();
  return app;
}
