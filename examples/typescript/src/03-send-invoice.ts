import { Fa3InvoiceBuilder, InvoiceRejectedException, UnknownOutcomeException } from '@luroweb/ksef-foundry';
import { connect } from './provision.js';

const { client, nip, cleanup } = await connect();
try {
  const number = `FV/EX/${String(Date.now())}`; // must be unique per seller (KSeF rejects duplicates with 440)
  const xml = await Fa3InvoiceBuilder.sampleXml(nip, number);

  try {
    // open session -> encrypt -> send -> close -> poll until a FINAL status. HTTP 202 is NOT acceptance.
    const result = await client.sender.submit(xml);
    console.log('Accepted. KSeF number:', result.ksefNumber);

    if (result.ksefNumber !== undefined) {
      const upo = await client.upo.getInvoiceUpoByKsefNumber(result.sessionReferenceNumber, result.ksefNumber);
      console.log('UPO size (bytes/chars):', upo.length);

      const downloaded = await client.invoices.getByKsefNumber(result.ksefNumber);
      console.log('Downloaded XML hash matches:', downloaded.hashMatches);
    }
  } catch (e) {
    if (e instanceof InvoiceRejectedException) {
      console.error('KSeF rejected the invoice:', e.diagnostics());
    } else if (e instanceof UnknownOutcomeException) {
      // The request may or may not have been processed. NEVER resend blindly:
      // reconcile first (client.sessions.reconcile(sessionRef, e.invoiceHash)).
      console.error('Unknown outcome; reconcile before resending. hash =', e.invoiceHash);
    } else throw e;
  }
} finally {
  await cleanup();
}
