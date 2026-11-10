"""Verify an email's DKIM signature for Yak's email channel.

Usage: python3 dkim_verify.py <sender-domain> < raw-message

Tries the DKIM-Signature headers whose d= domain is exactly the sender's
domain, carry no l= body-length limit, and sign the From header, at most
MAX_ATTEMPTS of them. On the first that verifies, prints the lowercased
names of the headers it signs as JSON and exits 0. Otherwise exits 1.

Every tag is read by dkimpy itself, so the domain checked here is the
domain dkimpy verified against; nothing is re-parsed by the caller.
"""

import json
import sys

import dkim

MAX_ATTEMPTS = 3


def verified_signed_headers(message, sender_domain, dnsfunc=dkim.get_txt):
    """Return the signed header names of the first acceptable signature that verifies, or None."""
    verifier = dkim.DKIM(message)
    signature_count = sum(1 for name, _ in verifier.headers if name.lower() == b"dkim-signature")
    attempts = 0

    for index in range(signature_count):
        try:
            prepared = verifier.verify_headerprep(index)
        except Exception:
            continue

        if not prepared:
            continue

        signature, include_headers, _ = prepared
        signed_headers = [name.lower() for name in include_headers]

        if signature.get(b"d", b"").lower().decode("ascii", "replace") != sender_domain:
            continue

        if b"l" in signature or b"from" not in signed_headers:
            continue

        attempts += 1

        try:
            if verifier.verify(index, dnsfunc=dnsfunc):
                return sorted({name.decode("ascii", "replace") for name in signed_headers})
        except Exception:
            pass

        if attempts >= MAX_ATTEMPTS:
            break

    return None


def main():
    if len(sys.argv) != 2:
        sys.exit(2)

    signed_headers = verified_signed_headers(sys.stdin.buffer.read(), sys.argv[1].lower())

    if signed_headers is None:
        sys.exit(1)

    print(json.dumps(signed_headers))


if __name__ == "__main__":
    main()
