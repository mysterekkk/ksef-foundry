import { createHash, timingSafeEqual } from 'node:crypto';
import { Inject, Injectable, UnauthorizedException, type CanActivate, type ExecutionContext } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

const sha256 = (s: string): Buffer => createHash('sha256').update(s, 'utf8').digest();

/**
 * Optional bearer-token guard for the routes that send invoices or generate load.
 *
 * - `KSEF_EXAMPLE_API_TOKEN` set: callers must send `Authorization: Bearer <token>`; both sides are hashed with
 *   SHA-256 and compared in constant time.
 * - not set: the routes are open (acceptable only because the example listens on 127.0.0.1).
 *
 * This protects YOUR API in front of KSeF; it is not a KSeF credential. For real use put proper authentication
 * (OIDC/JWT, mTLS) in front of any route that can submit invoices.
 */
@Injectable()
export class ApiTokenGuard implements CanActivate {
  constructor(@Inject(ConfigService) private readonly config: ConfigService) {}

  canActivate(context: ExecutionContext): boolean {
    const expected = this.config.get<string>('KSEF_EXAMPLE_API_TOKEN');
    if (expected === undefined || expected === '') return true;
    const req = context.switchToHttp().getRequest<{ headers: Record<string, string | string[] | undefined> }>();
    const header = req.headers['authorization'];
    const presented = typeof header === 'string' && header.startsWith('Bearer ') ? header.slice(7) : '';
    if (!timingSafeEqual(sha256(presented), sha256(expected))) throw new UnauthorizedException('Missing or invalid bearer token');
    return true;
  }
}
