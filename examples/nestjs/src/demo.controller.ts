import { BadRequestException, Controller, Get, Inject, Query, UseGuards } from '@nestjs/common';
import { KsefService } from '@luroweb/nestjs-ksef-foundry';
import { ApiTokenGuard } from './api-token.guard.js';

/** The TEST environment is shared by every integrator: keep demo traffic tiny. */
const MAX_BURST = 5;

/**
 * Demonstrates how SDK exceptions surface through `KsefExceptionFilter` (registered in `AppModule`).
 * Nothing here is faked: both endpoints send real requests to the configured KSeF environment.
 * Bearer-token guarded when `KSEF_EXAMPLE_API_TOKEN` is set.
 */
@Controller('demo')
@UseGuards(ApiTokenGuard)
export class DemoController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  /**
   * `GET /demo/forbidden`: lists KSeF tokens, which requires the credential-management permission.
   * With a taxpayer/token that lacks it KSeF answers 403 and the filter returns
   * `403 { code: "KSEF_FORBIDDEN", ksef: { category: "authorization", traceId } }` (permission details only with
   * `exposeUpstreamDetails`). With full owner permissions it simply returns the token list.
   */
  @Get('forbidden')
  forbidden(): Promise<unknown> {
    return this.ksef.tokens.list({ pageSize: 10 });
  }

  /**
   * `GET /demo/rate-limit?burst=3`: reads the rate limits `burst` times, one after another (max 5). The TEST
   * environment is shared by all integrators, so the demo must not hammer it: it cannot provoke a real 429 on
   * purpose. If KSeF does answer 429, the SDK honours `Retry-After` itself and, once its retries are exhausted, the
   * filter answers `429 + Retry-After`. The mapping itself is proven against a local fault-injection server in
   * the package tests.
   */
  @Get('rate-limit')
  async rateLimit(@Query('burst') burst = '3'): Promise<{ requested: number; succeeded: number }> {
    const n = Number(burst);
    if (!Number.isInteger(n) || n < 1 || n > MAX_BURST) throw new BadRequestException(`"burst" must be an integer between 1 and ${String(MAX_BURST)}`);
    for (let i = 0; i < n; i++) await this.ksef.limits.rateLimits();
    return { requested: n, succeeded: n };
  }
}
