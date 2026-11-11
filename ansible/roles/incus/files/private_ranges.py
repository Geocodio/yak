#!/usr/bin/env python3
"""Print the private CIDRs the sandbox ACL drops, with the bridge host address carved out."""
import ipaddress
import sys

RANGES = ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "169.254.0.0/16", "100.64.0.0/10"]


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: private_ranges.py <host-ip>", file=sys.stderr)
        return 2
    try:
        host = ipaddress.ip_network(ipaddress.IPv4Address(sys.argv[1]).compressed + "/32")
    except ValueError:
        print("invalid IPv4 address: " + sys.argv[1], file=sys.stderr)
        return 2
    out = []
    for cidr in RANGES:
        network = ipaddress.ip_network(cidr)
        out += [str(x) for x in network.address_exclude(host)] if host.subnet_of(network) else [cidr]
    print(",".join(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
