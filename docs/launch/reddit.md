# Reddit draft (r/PHP, r/typescript, similar)

> Before posting: read each subreddit's rules on self-promotion and project showcases (some require a flair, a specific weekly thread, or a minimum participation history). Disclose that you are the author. Post once per community, tailor the first line to it, do not cross-post the same text in bulk, and answer questions in the thread. Do not ask for upvotes.

**Title:** KSeF Foundry v0.1.0: PHP and TypeScript SDKs for Poland's e-invoicing API (KSeF 2.0), MIT, early release

**Body:**

I am the author. KSeF is Poland's national e-invoice system. I built PHP (with a Laravel adapter) and TypeScript (with a NestJS adapter) SDKs that behave identically: same names, error model and retry rules.

Not the first KSeF library (official C#/Java clients and other community clients exist). Differences: HTTP 202 is not treated as acceptance, ambiguous outcomes are reconciled by invoice hash instead of resent, and FA(3) invoices are validated offline against the official XSD.

Status: verified live on the TEST environment only. DEMO/PRODUCTION never touched; qualified certificates unverified; no batch sessions or correcting invoices. npm packages are published (npm install @luroweb/ksef-foundry); Composer packages are not yet on Packagist, so install PHP/Laravel from the repo.

https://github.com/mysterekkk/ksef-foundry (feedback via issues)
