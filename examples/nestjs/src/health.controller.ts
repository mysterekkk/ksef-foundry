import { Controller, Get, Inject } from '@nestjs/common';
import { KsefHealthIndicator, type KsefHealthResult } from '@luroweb/nestjs-ksef-foundry';

@Controller('health')
export class HealthController {
  constructor(@Inject(KsefHealthIndicator) private readonly health: KsefHealthIndicator) {}

  /** `GET /health`: reachability of the KSeF API (unauthenticated public-key endpoint). */
  @Get()
  check(): Promise<KsefHealthResult> {
    return this.health.check();
  }
}
