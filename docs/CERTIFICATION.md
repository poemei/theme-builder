# Builder Certification Integration

Certification is a per-project identity assertion from `https://chaos-mvc.org/developers/verify`. It is not Builder registration, permission to use the Builder, or a replacement for release signing.

The Builder submits only:

- `developer`
- `domain`
- `type` (`module` or `theme`)
- `key_id`

The private key is never stored, cached, published, or sent. It is supplied only for the local signing operation.

A response produces `certified: "Yes"` only when the response is successful and its developer, lowercased domain, artifact-specific certification, signing algorithm, and key ID exactly match, and the credential is not expired. A verified response may preload the account's base64 public key and OpenPGP fingerprint into that project. Untrusted or malformed public-key data is not imported.

Not verified, mismatched, expired, invalid, and unavailable results produce `certified: "No"`. They do not disable project creation, editing, unsigned builds, or cryptographic signing with locally configured keys.

Successful verification is cached for at most 24 hours. A negative result is cached for at most five minutes. An expired cache is never treated as verified; service failure after expiry is reported as Unavailable.

The default authority is `https://chaos-mvc.org/developers/verify`. An endpoint override must still use HTTPS port 443 and the chaos-mvc.org host. Redirects are not followed.

Release trust remains separate: the local project pins the developer's public key, the developer uploads the private key at signing time, and Core verifies the signed release statement and exact package SHA-256.

