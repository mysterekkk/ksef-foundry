import {
  CertificateCredentials,
  Context,
  Environment,
  KsefClient,
  KsefTokenCredentials,
  TestCertificateFactory,
  generateTestNip,
  generateTestPesel,
} from '@luroweb/ksef-foundry';

export interface Session {
  client: KsefClient;
  nip: string;
  cleanup: () => Promise<void>;
}

/** Retries while KSeF TEST propagates the permissions created by /testdata/person (auth status 415). */
async function eventually<T>(fn: () => Promise<T>, attempts = 8): Promise<T> {
  for (let i = 1; ; i++) {
    try {
      return await fn();
    } catch (e) {
      const status = (e as { authStatusCode?: number }).authStatusCode;
      if (status !== 415 || i >= attempts) throw e;
      await new Promise((r) => setTimeout(r, 2000));
    }
  }
}

/**
 * Returns a ready client for the TEST environment.
 *  - With KSEF_TOKEN + KSEF_CONTEXT_NIP: uses your own KSeF token (a TEST token!).
 *  - Without: creates a SYNTHETIC taxpayer (random NIP/PESEL, self-signed certificate) in TEST.
 *    No real data and no secrets are involved. The environment is always TEST.
 */
export async function connect(): Promise<Session> {
  const token = process.env['KSEF_TOKEN'];
  const ctxNip = process.env['KSEF_CONTEXT_NIP'];
  if (token && ctxNip) {
    const client = KsefClient.create({
      environment: Environment.TEST,
      credentials: new KsefTokenCredentials(token, Context.nip(ctxNip)),
    });
    return { client, nip: ctxNip, cleanup: async () => undefined };
  }

  const nip = generateTestNip();
  const pesel = generateTestPesel();
  const cert = await TestCertificateFactory.createPersonal({
    serialNumberPrefix: 'PNOPL',
    identifier: pesel,
    givenName: 'Jan',
    surname: 'Testowy',
  });
  const client = KsefClient.create({
    environment: Environment.TEST,
    credentials: new CertificateCredentials({
      certificatePem: cert.certificatePem,
      privateKeyPem: cert.privateKeyPem,
      context: Context.nip(nip),
    }),
  });
  await client.testData.createPerson({ nip, pesel, description: 'ksef-foundry example taxpayer' });
  await eventually(() => client.getAccessToken()); // warm-up: XAdES login
  return { client, nip, cleanup: () => client.testData.removePerson(nip).catch(() => undefined) };
}
