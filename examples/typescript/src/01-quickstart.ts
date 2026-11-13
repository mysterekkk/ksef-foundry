import { connect } from './provision.js';

const { client, nip, cleanup } = await connect();
try {
  console.log('Context NIP (synthetic):', nip);

  const limits = await client.limits.context();
  console.log('Context limits:', JSON.stringify(limits).slice(0, 200), '...');

  // Metadata of invoices where we are the seller (empty for a fresh taxpayer).
  let n = 0;
  for await (const inv of client.invoiceQuery.iterateMetadata({
    subjectType: 'Subject1',
    dateRange: { dateType: 'Issue', from: new Date(Date.now() - 30 * 86_400_000) },
  })) {
    n++;
    console.log(inv.ksefNumber);
  }
  console.log(`Found ${String(n)} invoice(s).`);
} finally {
  await cleanup();
}
