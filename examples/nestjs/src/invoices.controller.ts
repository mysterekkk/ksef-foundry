import { BadRequestException, Body, Controller, Get, Header, HttpCode, Inject, Param, Post, Query, UseGuards } from '@nestjs/common';
import { Fa3InvoiceBuilder, InvoiceDateType, InvoiceSubjectType, MAX_RANGE_DAYS } from '@luroweb/ksef-foundry';
import { KsefService } from '@luroweb/nestjs-ksef-foundry';
import { ApiTokenGuard } from './api-token.guard.js';
import { KsefNumberPipe, ReferenceNumberPipe } from './pipes.js';

const SUBJECTS: Readonly<Record<string, InvoiceSubjectType>> = {
  seller: InvoiceSubjectType.Seller,
  buyer: InvoiceSubjectType.Buyer,
  subject3: InvoiceSubjectType.Subject3,
  authorized: InvoiceSubjectType.Authorized,
};
const DATE_TYPES: readonly InvoiceDateType[] = [InvoiceDateType.Issue, InvoiceDateType.Invoicing, InvoiceDateType.PermanentStorage];
const DAY_MS = 86_400_000;

function parseDate(name: string, value: string | undefined, fallback: Date): Date {
  if (value === undefined || value === '') return fallback;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) throw new BadRequestException(`"${name}" must be an ISO-8601 date`);
  return d;
}

function parseInteger(name: string, value: string | undefined, min: number, max: number): number | undefined {
  if (value === undefined || value === '') return undefined;
  const n = Number(value);
  if (!Number.isInteger(n) || n < min || n > max) throw new BadRequestException(`"${name}" must be an integer between ${String(min)} and ${String(max)}`);
  return n;
}

/**
 * The invoice arrives either as raw XML (Content-Type: application/xml) or as the language-neutral JSON model
 * (Content-Type: application/json, see fixtures/fa3/README.md) turned into FA(3) XML by Fa3InvoiceBuilder.fromModel.
 * Builder errors become 422 through KsefExceptionFilter.
 */
async function resolveXml(body: unknown): Promise<string> {
  if (typeof body === 'string' && body.trim() !== '') return body;
  if (body !== null && typeof body === 'object' && !Array.isArray(body) && Object.keys(body).length > 0) {
    return Fa3InvoiceBuilder.fromModel(body).build();
  }
  throw new BadRequestException('Send FA(3) XML (Content-Type: application/xml) or the JSON invoice model (Content-Type: application/json)');
}

/**
 * Invoice endpoints. Invoices go out as FA(3) XML; they go in as XML or as the JSON model of `Fa3InvoiceBuilder.fromModel`.
 */
@Controller('invoices')
export class InvoicesController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  /** `GET /invoices?subjectType=seller&dateType=Issue&from=...&to=...&pageSize=50&pageOffset=0&sortOrder=Desc` */
  @Get()
  list(
    @Query('subjectType') subjectType?: string,
    @Query('dateType') dateType?: string,
    @Query('from') from?: string,
    @Query('to') to?: string,
    @Query('pageSize') pageSize?: string,
    @Query('pageOffset') pageOffset?: string,
    @Query('sortOrder') sortOrder?: string,
  ): Promise<unknown> {
    const subject = SUBJECTS[(subjectType ?? 'seller').toLowerCase()];
    if (subject === undefined) throw new BadRequestException(`"subjectType" must be one of: ${Object.keys(SUBJECTS).join(', ')}`);
    const type = DATE_TYPES.find((t) => t === (dateType ?? InvoiceDateType.Issue));
    if (type === undefined) throw new BadRequestException(`"dateType" must be one of: ${DATE_TYPES.join(', ')}`);
    if (sortOrder !== undefined && sortOrder !== 'Asc' && sortOrder !== 'Desc') throw new BadRequestException('"sortOrder" must be Asc or Desc');
    const end = parseDate('to', to, new Date());
    const start = parseDate('from', from, new Date(end.getTime() - 30 * DAY_MS));
    if (end.getTime() - start.getTime() > MAX_RANGE_DAYS * DAY_MS) {
      throw new BadRequestException(`The date range must not exceed ${String(MAX_RANGE_DAYS)} days`);
    }
    const size = parseInteger('pageSize', pageSize, 10, 250);
    const offset = parseInteger('pageOffset', pageOffset, 0, 1_000_000);
    return this.ksef.queryInvoices(
      { subjectType: subject, dateRange: { dateType: type, from: start, to: end } },
      { ...(size !== undefined ? { pageSize: size } : {}), ...(offset !== undefined ? { pageOffset: offset } : {}), ...(sortOrder !== undefined ? { sortOrder } : {}) },
    );
  }

  /** `GET /invoices/status/:sessionRef/:invoiceRef` */
  @Get('status/:sessionRef/:invoiceRef')
  status(@Param('sessionRef', ReferenceNumberPipe) sessionRef: string, @Param('invoiceRef', ReferenceNumberPipe) invoiceRef: string): Promise<unknown> {
    return this.ksef.getInvoiceStatus(sessionRef, invoiceRef);
  }

  /** `POST /invoices/build` (JSON model): returns the FA(3) XML, already XSD self-checked by the builder. */
  @Post('build')
  @HttpCode(200)
  @Header('Content-Type', 'application/xml; charset=utf-8')
  build(@Body() body: unknown): Promise<string> {
    if (typeof body === 'string') throw new BadRequestException('POST the JSON invoice model (Content-Type: application/json)');
    return resolveXml(body);
  }

  /** `POST /invoices/validate` (Content-Type: application/xml): offline XSD + KSeF technical rules. */
  @Post('validate')
  @HttpCode(200)
  async validate(@Body() body: unknown): Promise<{ valid: boolean; issues: unknown[] }> {
    const result = await this.ksef.validateInvoice(await resolveXml(body));
    return { valid: result.isValid(), issues: result.issues.map((i) => i.toJSON()) };
  }

  /** `POST /invoices` (XML or JSON model; bearer-token guarded when KSEF_EXAMPLE_API_TOKEN is set): validate, open session, send, wait for acceptance, close. */
  @Post()
  @UseGuards(ApiTokenGuard)
  async send(@Body() body: unknown): Promise<unknown> {
    const r = await this.ksef.submitInvoice(await resolveXml(body));
    return {
      ksefNumber: r.ksefNumber,
      invoiceReferenceNumber: r.invoiceReferenceNumber,
      sessionReferenceNumber: r.sessionReferenceNumber,
      invoiceHash: r.invoiceHash,
      upoAvailable: r.upoAvailable,
    };
  }

  /** `GET /invoices/:ksefNumber` returns the invoice XML. */
  @Get(':ksefNumber')
  @Header('Content-Type', 'application/xml; charset=utf-8')
  async download(@Param('ksefNumber', KsefNumberPipe) ksefNumber: string): Promise<string> {
    return (await this.ksef.downloadInvoice(ksefNumber)).xml;
  }
}
