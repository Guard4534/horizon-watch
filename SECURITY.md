# Security

## Reporting a vulnerability

Please report security problems privately, not in a public issue.

Use GitHub's private reporting: open the **Security** tab of this repository and choose
**Report a vulnerability**. That opens a draft advisory only the maintainers can read.

Please include what you did, what happened, and the version or image tag you were running. A
proof of concept is welcome but not required. You will get an acknowledgement within a few
days, and an estimate of when a fix will land. Please give the fix a reasonable window before
publishing anything.

## Supported versions

This project is at `0.x`. Only the latest release is supported: fixes go into a new patch
release of the current minor version, and there are no backports. Pin an image tag in
production and read [CHANGELOG.md](CHANGELOG.md) before upgrading.

## Threat model

Horizon Watch is invite-only and has no anonymous surface beyond the login page and the
one-time `/setup` page, which closes for good once the first user exists. What follows is
what the project protects, and what it deliberately does not.

### The application key and the stored Horizon credentials

An environment may carry the basic-auth user and password of its Horizon dashboard. The
password is encrypted at rest with Laravel's encrypter, which means it is protected by
`APP_KEY` and nothing else.

Unless you set `APP_KEY` yourself, the entrypoint generates one on first start and writes it
to `/data/app-key` inside the `app-data` volume, owned by `www-data` with mode `600`.
Consequences to take seriously:

- **Anyone who can read that volume, or a backup of it, together with the database, can
  decrypt every stored Horizon password.** Treat backups of `app-data` as secret material and
  keep them encrypted.
- Losing the volume does not expose anything, but it makes the stored passwords unreadable;
  they have to be entered again.
- Setting `APP_KEY` in the environment keeps the key out of the volume, at the cost of
  putting it wherever your environment file lives.

The stored password is never shown again, never logged, never included in an exception and
never sent to the browser. `HorizonTarget` redacts itself when it is serialised or dumped.

### Outbound requests (SSRF)

Every URL the panel reads is under the control of a member of an organization, so the reader
is the one place where a user can make the server talk to an address of their choosing.
`App\Externals\Horizon\SafeUrlGuard` stands in front of every read:

- Only `http` and `https`; no credentials in the URL, no query string, no fragment, no
  non-printable characters.
- Link-local and cloud metadata addresses (`169.254.0.0/16`, `fe80::/10`, the AWS, GCP and
  Alibaba metadata addresses, `metadata.google.internal`), broadcast, multicast and
  unspecified addresses are **always** refused, whatever the settings say.
- With `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS=true`, loopback and RFC 1918 addresses are
  refused as well. It is `false` by default because most people run this panel next to the
  applications it watches, on a private network. Turn it on if the panel is reachable by
  people you would not trust with an internal HTTP client.
- The name is resolved once and the request is pinned to the address that came back, so a
  name that changes between the check and the request cannot send the read somewhere else.
- Reads follow no redirects, ignore any proxy in the environment, time out after a few
  seconds and stop reading at 2 MB.

The same guard runs on webhook URLs, which additionally must not carry credentials, a query
string or a fragment: put any token in the path.

### Outgoing webhooks

Every webhook delivery is signed. `X-Horizon-Watch-Signature` is `sha256=` followed by the
hex HMAC-SHA256 of `<timestamp>.<raw body>`, keyed with the organization's webhook secret,
and `X-Horizon-Watch-Timestamp` carries the time of the send. Receivers must verify the
signature with a constant-time comparison and reject old timestamps; the README has a worked
example.

The secret is shown once, when the first URL is saved or when it is regenerated. Serve the
panel over HTTPS so that the page showing it can ask the browser to encrypt that history
entry — browsers only honour that in a secure context.

### Credentials in logs and errors

The project's standing rule is that nothing which could carry a credential is ever logged,
attached to an exception, or rendered:

- An unexpected exception inside the Horizon reader is reported by class, file and line only,
  never chained, because its message could hold a URL with credentials or a slice of a
  response body.
- Laravel's HTTP client events carry the `Authorization` header and the full URL. No
  listener, debugging package or error tracker may be added that records them without
  redaction.
- Exception reports flash no input for the fields that carry URLs, secrets, recipients or
  passwords.
- There is no mail transport that writes messages to a log file, because the log would then
  hold recipients and invitation links.

### Sessions and transport

Serve the panel over HTTPS. The session cookie is `HttpOnly` and `SameSite=Lax`, and its
`Secure` flag follows the scheme of the request unless you force it with
`SESSION_SECURE_COOKIE`. That scheme is only correct if the panel can trust the forwarded
headers of your reverse proxy: `TRUSTED_PROXIES` defaults to loopback and the private ranges,
and ignores forwarded headers from anywhere else. Widen it only to the addresses your proxy
actually uses; `*` lets any client claim any scheme and any source address, which also means
any client can defeat the per-address rate limits on the login and setup pages.

nginx sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy` and a Content-Security-Policy that allows scripts only from the panel's
own origin.

### What is out of scope

- The panel trusts the members of an organization. Anyone who may manage applications can
  make the server read an HTTP address of their choosing, within the limits above.
- It does not audit the Horizon installations it watches: it reads them, and whatever they
  answer is what you see.
- There is no built-in TLS, no rate limiting beyond the authentication and setup endpoints,
  and no protection against a host operator who can read the volumes.
