import { Fa3Address, Fa3InvoiceBuilder, InvoiceValidationException, XmlValidator, generateTestNip } from '@luroweb/ksef-foundry';

const sellerNip = generateTestNip();

// 1) Build an FA(3) invoice. Amounts are exact decimals (strings), never floats.
const builder = Fa3InvoiceBuilder.create()
  .seller(sellerNip, 'Przykładowa Sp. z o.o.', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
  .buyerNoId('Jan Kowalski', new Fa3Address('ul. Próbna 2', '00-002 Warszawa'))
  .number('FV/2026/11/1')
  .issueDate(new Date().toISOString().slice(0, 10))
  .createdAt(new Date())
  .addLine('Usługa konsultingowa', 3, '33.33', '23', 'szt.')
  .addLine('Książka', 1, '49.90', '5', 'szt.')
  .paymentMethod(6);

console.log('Totals:', JSON.stringify(builder.totals()));
const xml = await builder.build(); // self-validates against the official FA(3) XSD
console.log(xml.slice(0, 160), '...');

// 2) Validate any existing XML without the builder.
const validator = new XmlValidator();
console.log('valid:', (await validator.validateFa3(xml)).isValid());

// 3) Invalid XML produces precise issues.
const broken = xml.replace(/<P_2>[^<]*<\/P_2>/, '');
const result = await validator.validateFa3(broken);
console.log('broken valid:', result.isValid());
for (const issue of result.errors()) console.log(` line ${String(issue.line)}: [${issue.code}] ${issue.message}`);

try {
  await validator.assertValid(broken);
} catch (e) {
  if (e instanceof InvoiceValidationException) console.log('assertValid threw with', e.issues.length, 'issue(s)');
  else throw e;
}
