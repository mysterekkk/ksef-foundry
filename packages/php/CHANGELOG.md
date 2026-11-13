# Changelog

## 0.1.0 (unreleased)
- Initial core SDK: environments, transport with retry policy, error hierarchy, crypto,
  token and XAdES authentication, online sessions, queries, UPO, limits, test data.
- Security hardening: `#[\SensitiveParameter]` on all secret-bearing parameters, sanitised network exceptions, hardened Guzzle adapter, negative login caching, pagination guards, safety checks even with validation disabled, strict context/challenge validation.
