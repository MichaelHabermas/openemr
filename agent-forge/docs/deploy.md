# Deploy Targets

Origin registry for the two-process system. Same targets serve the Early
Submission demo. See [ARCHITECTURE.md §1](ARCHITECTURE.md) for the trust
boundary that depends on these being distinct origins.

## Origins

| Role | Origin | Status |
|---|---|---|
| OpenEMR (PHP shim host) | https://openemr.titleredacted.cc/ | live |
| Python agent service | https://copilot.titleredacted.cc/ | not provisioned |

The two origins **must be distinct** (cross-origin). The PHP shim's iframe
`src` and the agent service's `Content-Security-Policy: frame-ancestors`,
CORS `Allow-Origin`, and TLS cert are all keyed off these values.

## Stack

- VM: single Linux host.
- Container layout: `docker/development-easy` with the agent service added as
  a sibling service in the same compose stack so the Python container can
  reach OpenEMR's MySQL by service name on the internal network.
- TLS termination + virtual hosts: Caddy in front of both containers with
  automatic Let's Encrypt.
- Deploy script: `agent-forge/scripts/deploy.sh` (idempotent; applies
  fixtures after MySQL is up).

## Open

- [ ] Provision `copilot.titleredacted.cc` (DNS A record → VM).
- [ ] Caddyfile committed to repo with both vhosts.
