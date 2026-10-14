# Security

## Reporting a vulnerability

Please report security problems privately, not in a public issue.

Use GitHub's private reporting: open the **Security** tab of this repository and choose
**Report a vulnerability**. That opens a draft advisory only the maintainers can read.

Please include what you did, what happened, and the version you were running (shown at the
bottom of the sidebar and by `GET /health`). A
proof of concept is welcome but not required. You will get an acknowledgement within a few
days, and an estimate of when a fix will land. Please give the fix a reasonable window before
publishing anything.

## Supported versions

Horizon Watch follows [Semantic Versioning](https://semver.org/). Security fixes go into a new
patch release of the latest minor version of the current major version; there are no backports
to earlier minor versions, because upgrading within a major version needs no change on your side.

| Version               | Security fixes                |
| --------------------- | ----------------------------- |
| `1.x`, latest minor   | yes                           |
| `1.x`, earlier minors | no: upgrade to the latest 1.x |
| `0.x`                 | no: upgrade to 1.x            |

Pin an image tag in production, as the `compose.prod.yaml` of each release does, and read
[CHANGELOG.md](CHANGELOG.md) before upgrading. A security release says so in its notes.

## Threat model

Horizon Watch is invite-only and has no anonymous surface beyond the login and password reset
pages, the invitation pages, `GET /health` and the `/setup` page, which is open only while no
account exists. Deleting the last account opens it again. Whoever reaches `/setup` first claims
the instance, so a panel reachable by others before its administrator exists should set
`HORIZON_WATCH_SETUP_TOKEN`: `/setup` then refuses anyone who does not type that token, compared
in constant time and rate limited, and refuses everyone while the token is shorter than 16
characters, since a client on a trusted network can spread its attempts over forged addresses. Without it, keep the panel unreachable until setup is done.
What follows is what the project protects, and what it deliberately does not.

### The application key and the stored secrets

An environment may carry the basic-auth user and password of its Horizon dashboard, an
organization may have a webhook signing secret, and an account may use two-factor
authentication. The password, the signing secret and the two-factor secret and recovery codes
are encrypted at rest with Laravel's encrypter, which means they are protected by `APP_KEY` and
nothing else.

Unless you set `APP_KEY` yourself, the entrypoint generates one on first start and writes it
to `/data/app-key` inside the `app-data` volume, owned by `www-data` with mode `600`.
Consequences to take seriously:

- **Anyone who can read that volume, or a backup of it, together with the database, can
  decrypt every stored Horizon password, webhook signing secret and two-factor secret.** Treat
  backups of `app-data` as secret material and keep them encrypted.
- Losing the volume does not expose anything, but it makes those secrets unreadable. The panel
  says so instead of failing: an environment whose password cannot be decrypted shows it as
  its reading error, a webhook whose secret cannot be decrypted is logged as not delivered
  while alert emails keep going out, the two-factor challenge says the secret can no longer be
  read, and `artisan users:reset-two-factor` lets such an account log in and set it up again.
  The README lists the recovery steps.
- Setting `APP_KEY` in the environment keeps the key out of the volume, at the cost of
  putting it wherever your environment file lives.
- To rotate the key, move the old one to `APP_PREVIOUS_KEYS`. Laravel decrypts with every
  previous key but never encrypts with them, and it does not re-encrypt what is stored on its
  own: run `artisan secrets:reencrypt`, then remove the old key. Until then the old key stays
  as sensitive as the new one.

The stored password is never shown again, never logged, never included in an exception and
never sent to the browser. `HorizonTarget` redacts itself when it is serialised or dumped. The
basic-auth user is sent only to the edit forms, which only the people who may manage
applications open; every other page says only that the environment uses basic auth.

Invitation codes are not secrets the panel needs to read back, so they are not encrypted but
hashed: the database keeps the SHA-256 of each code, and the plain code exists only in the
link. The invitation email carries it through the queue encrypted. Resending an invitation or
showing its link issues a new code, and the earlier link stops working.

### Outbound requests (SSRF)

Every URL the panel reads is under the control of a member of an organization, so the reader
is the one place where a user can make the server talk to an address of their choosing.
`App\Externals\Http\SafeUrlGuard` stands in front of every read:

- Only `http` and `https`; no credentials in the URL, no query string, no fragment, no
  non-printable characters.
- Link-local and cloud metadata addresses (`169.254.0.0/16`, `fe80::/10`, the AWS and GCP
  IPv6 metadata addresses, Alibaba's metadata address, Azure's WireServer
  `168.63.129.16`, `metadata.google.internal`), broadcast, multicast and unspecified
  addresses are **always** refused, whatever the settings say.
- With `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS=true`, loopback, RFC 1918, unique-local and
  site-local IPv6, carrier-grade NAT (`100.64.0.0/10`, used by Tailscale and some pod
  networks) and benchmarking (`198.18.0.0/15`) addresses are refused as well. It is `false`
  by default because most people run this panel next to the applications it watches, on a
  private network. Turn it on if the panel is reachable by people you would not trust with an
  internal HTTP client.
- The name is resolved once and the request is pinned to the address that came back, so a
  name that changes between the check and the request cannot send the read somewhere else.
- Reads follow no redirects, ignore any proxy in the environment, time out after a few
  seconds and stop reading at 2 MB. They ask for uncompressed answers and never inflate
  one: a compressed Horizon answer is refused, so a small body cannot expand into gigabytes.
- A failed read says why it failed: the HTTP status, the redirect target (without query
  string, fragment or credentials) and whether the connection timed out, was refused or
  failed TLS. Test connection and the environment page show this to the members who can see
  the environment, so with private networks allowed, someone who can add an environment
  learns which internal ports answer, with what status and where they redirect. That is one
  more reason to turn on `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` on a shared panel.
- What a hostile Horizon sends is bounded too: at most 500 queues, 200 masters, supervisors
  and jobs per list, 4 pages of jobs and 100 runtime requests per reading, all inside the
  read budget.

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
- In production the panel sends mail only over SMTP, whatever `MAIL_MAILER` says, so no mail
  transport writes messages to a log file: the log would then hold recipients and invitation
  links.

### Sessions and transport

Serve the panel over HTTPS. The session cookie is `HttpOnly` and `SameSite=Lax`, and its
`Secure` flag follows the scheme of the request unless you force it with
`SESSION_SECURE_COOKIE`. That scheme is only correct if the panel can trust the forwarded
headers of your reverse proxy: `TRUSTED_PROXIES` defaults to loopback and the private ranges,
and ignores forwarded headers from anywhere else. Only `X-Forwarded-For`, `X-Forwarded-Proto`
and `X-Forwarded-Port` are honoured. `X-Forwarded-Host` never is, and links in emails are built
from `APP_URL`, so a forged host cannot point a password reset link elsewhere.

The default trusts every private address, so a machine on your network that reaches the
published port directly can claim any source address and defeat the per-address rate limits on
the login, password reset and setup pages. Publish the port only to the proxy, or narrow
`TRUSTED_PROXIES` to the addresses your proxy actually uses; `*` lets any client anywhere do
the same. The login also has a per-account limit on failed attempts that ignores the address:
once an account collects 30 failed sign-ins within an hour, it cannot sign in, even with the
right password, until the hour is over. Anyone who knows the address can keep an account locked
this way; that is the price of stopping password guessing from rotating addresses. Reset emails
for one account are likewise capped at five an hour.

nginx sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy` and a Content-Security-Policy that allows scripts only from the panel's
own origin. It does not send `Strict-Transport-Security`: the panel's nginx speaks plain HTTP
behind your proxy, and the header would cover every port of the host name, which only you know
to be HTTPS. Send it from the TLS-terminating proxy, and set `SESSION_SECURE_COOKIE=true` so the
session cookie is never sent over HTTP even when a forwarded scheme is wrong; the README has
Caddy and nginx examples.

### Accounts

- Changing or resetting the password logs out every other session of the account. Security
  settings can also log out the other sessions on demand, with the password.
- Changing the email address needs the current password, and the old address gets a notice.
- The forgot-password form answers the same way whether an account exists or not. The reset
  email goes out through the queue and every request takes at least half a second, which hides
  the time spent preparing the link on a typical server.
- Every form that checks the current password is rate limited per user.

### What is out of scope

- The panel trusts the members of an organization. Anyone who may manage applications can
  make the server read an HTTP address of their choosing, within the limits above. Only
  instance admins can create organizations, unless `HORIZON_WATCH_ORG_CREATION=everyone`, in
  which case every account can become the owner of one and point the reader anywhere.
- It does not audit the Horizon installations it watches: it reads them, and whatever they
  answer is what you see.
- There is no built-in TLS and no protection against a host operator who can read the volumes.
- Rate limits cover these endpoints, not the rest of the panel: sign-in (5 a minute per address
  and account, 30 failures an hour per account), the two-factor challenge (5 a minute),
  password reset requests (5 a minute per address, 5 an hour per account), every form that
  checks the current password and password changes (6 a minute per user), setup (10 a minute),
  invitations and invitation links (6 a minute), test connections (10 a minute), test
  notifications (5 a minute) and language changes (30 a minute). The numbers are in
  `config/horizon-watch.php`.
