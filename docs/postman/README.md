# Medcon public registration APIs — Postman

Import these files into Postman:

1. `Medcon_Public_Registration_APIs.postman_collection.json`
2. `Medcon_Local_XAMPP.postman_environment.json` for local testing

Select **Medcon Local XAMPP** before sending local requests. For production, use the collection-level `base_url` value or create an environment containing:

```text
base_url=https://api-database.medcon.ae/api
source_domain=<the domain hosting the registration form>
```

The collection contains:

- WhatsApp opt-in with consent evidence
- WhatsApp opt-out
- Pending contact submission
- Automated status and response-contract tests
- Saved success-response examples

Both endpoints are public and rate-limited. They do not use an administrator JWT. Contact submissions remain isolated in `contact_submissions` until a FULL administrator migrates them.

Use a stable registration identifier for `evidence_reference` and `external_reference` in a real integration. The collection uses `{{$guid}}` so manual Postman tests do not collide with previous test data.
