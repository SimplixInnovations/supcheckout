# Edge / CDN Certification

## Already verified (bounded)

- Local reverse proxy: forged Host/X-Forwarded-* fail-closed
- callback no-cache
- public status no-cache
- canonical WC-API callback URL

## Real edge (Cloudflare / other)

**Status:** EXTERNAL REQUIRED — real staging edge environment

Test when available:

- HTTPS/origin
- callback URL
- public status endpoint
- cache bypass/no-cache
- redirect
- Host/X-Forwarded-* behavior
- Rocket Loader / script delay if used
- APO/page caching if used
- webhook reachability
- duplicate delivery
- WAF false positives
- rate limiting

Do not relax trusted-origin rules merely to satisfy a proxy.

Do not advertise blanket Cloudflare compatibility.
