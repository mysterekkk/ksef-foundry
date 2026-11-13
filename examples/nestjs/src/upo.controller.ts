import { Controller, Get, Header, Inject, Param } from '@nestjs/common';
import { KsefService } from '@luroweb/nestjs-ksef-foundry';
import { KsefNumberPipe, ReferenceNumberPipe } from './pipes.js';

/** UPO (official confirmation of receipt) downloads; each endpoint answers with the signed UPO XML. */
@Controller('upo')
export class UpoController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  /** `GET /upo/:sessionRef/invoices/:invoiceRef` */
  @Header('Content-Type', 'application/xml; charset=utf-8')
  @Get(':sessionRef/invoices/:invoiceRef')
  byInvoiceReference(@Param('sessionRef', ReferenceNumberPipe) sessionReferenceNumber: string, @Param('invoiceRef', ReferenceNumberPipe) invoiceReferenceNumber: string): Promise<string> {
    return this.ksef.getUpo({ sessionReferenceNumber, invoiceReferenceNumber });
  }

  /** `GET /upo/:sessionRef/ksef/:ksefNumber` */
  @Header('Content-Type', 'application/xml; charset=utf-8')
  @Get(':sessionRef/ksef/:ksefNumber')
  byKsefNumber(@Param('sessionRef', ReferenceNumberPipe) sessionReferenceNumber: string, @Param('ksefNumber', KsefNumberPipe) ksefNumber: string): Promise<string> {
    return this.ksef.getUpo({ sessionReferenceNumber, ksefNumber });
  }

  /** `GET /upo/:sessionRef/session/:upoRef` */
  @Header('Content-Type', 'application/xml; charset=utf-8')
  @Get(':sessionRef/session/:upoRef')
  bySession(@Param('sessionRef', ReferenceNumberPipe) sessionReferenceNumber: string, @Param('upoRef', ReferenceNumberPipe) upoReferenceNumber: string): Promise<string> {
    return this.ksef.getUpo({ sessionReferenceNumber, upoReferenceNumber });
  }
}
