import { Controller, Get, Inject } from '@nestjs/common';
import { KsefService } from '@luroweb/nestjs-ksef-foundry';

@Controller('limits')
export class LimitsController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  /** `GET /limits`: context limits (sessions, invoice sizes) and the current API rate limits. */
  @Get()
  async limits(): Promise<unknown> {
    const [context, rate] = await Promise.all([this.ksef.limits.context(), this.ksef.limits.rateLimits()]);
    return { context, rate };
  }

  /** `GET /limits/subject`: subject limits (needs the matching permission in KSeF). */
  @Get('subject')
  subject(): Promise<unknown> {
    return this.ksef.limits.subject();
  }
}
