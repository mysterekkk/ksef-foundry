import 'reflect-metadata';
import { Logger } from '@nestjs/common';
import { createApp } from './app.js';

const app = await createApp();
const port = Number(process.env['PORT'] ?? 3000);
await app.listen(port, '127.0.0.1');
new Logger('Bootstrap').log(`KSeF Foundry NestJS example listening on http://127.0.0.1:${String(port)} (KSeF TEST)`);
