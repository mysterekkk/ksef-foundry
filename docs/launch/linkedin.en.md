I have released v0.1.0 of KSeF Foundry, an open-source (MIT) SDK for Poland's National e-Invoice System (KSeF 2.0), for PHP/Laravel and TypeScript/NestJS.

It is not the first KSeF library. The Ministry of Finance publishes official C# and Java clients, and independent PHP and TypeScript clients exist. What I tried to do differently:

- one project with two SDKs that share names, error model and retry rules, plus Laravel and NestJS adapters
- duplicate-safe sending: HTTP 202 is treated as "request received", not "accepted", and after an ambiguous outcome the SDK reconciles by invoice hash instead of resending
- offline FA(3) validation against the official XSD, with exact decimal arithmetic in the builder
- a no-mocks testing policy: cryptography, XSD and the KSeF TEST API are tested for real, and fault injection is labelled as such

Honest status: verified live on the TEST environment only. DEMO and PRODUCTION were never touched, qualified and KSeF-issued certificates are unverified, and batch sessions, offline modes and correcting invoices are not implemented. The npm packages are published on npmjs.com; the Composer packages are not yet on Packagist, so for PHP and Laravel you install from the repository for now.

Repo, scope and findings: https://github.com/mysterekkk/ksef-foundry
Feedback and bug reports are welcome in the issues.

#KSeF #PHP #TypeScript #OpenSource
