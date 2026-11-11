package main

import (
	"fmt"
	"net"
	"strings"
	"testing"
)

func stateJSON(networks map[string][][2]string) []byte {
	var parts []string
	for name, addresses := range networks {
		var entries []string
		for _, pair := range addresses {
			family := "inet"
			if strings.Contains(pair[0], ":") {
				family = "inet6"
			}
			entries = append(entries, fmt.Sprintf(`{"family":%q,"address":%q,"scope":%q}`, family, pair[0], pair[1]))
		}
		parts = append(parts, fmt.Sprintf(`%q:{"addresses":[%s]}`, name, strings.Join(entries, ",")))
	}

	return []byte(`{"network":{` + strings.Join(parts, ",") + `}}`)
}

func TestParseIncusState(t *testing.T) {
	gateway, bridge, _ := net.ParseCIDR("10.100.0.1/24")

	cases := []struct {
		name     string
		networks map[string][][2]string
		want     string
	}{
		{"eth0 inside bridge", map[string][][2]string{"eth0": {{"10.100.0.50", "global"}}}, "10.100.0.50"},
		{"eth0 outside bridge", map[string][][2]string{"eth0": {{"192.168.5.4", "global"}}}, ""},
		{"eth0 claims the gateway", map[string][][2]string{"eth0": {{"10.100.0.1", "global"}}}, ""},
		{"other NIC only", map[string][][2]string{"docker0": {{"10.100.0.60", "global"}}}, ""},
		{"eth0 wins over other NIC", map[string][][2]string{"eth0": {{"10.100.0.7", "global"}}, "docker0": {{"10.100.0.9", "global"}}}, "10.100.0.7"},
		{"eth0 link scope only", map[string][][2]string{"eth0": {{"10.100.0.50", "link"}}}, ""},
		{"first address inside the subnet", map[string][][2]string{"eth0": {{"192.168.5.4", "global"}, {"10.100.0.8", "global"}}}, "10.100.0.8"},
		{"ipv6 ignored", map[string][][2]string{"eth0": {{"fd00::5", "global"}}}, ""},
	}

	for _, c := range cases {
		got, err := parseIncusState(stateJSON(c.networks), bridge, gateway)
		if c.want == "" {
			if err == nil {
				t.Fatalf("%s: expected error, got %q", c.name, got)
			}

			continue
		}
		if err != nil || got != c.want {
			t.Fatalf("%s: got %q err=%v", c.name, got, err)
		}
	}
}

func TestParseIncusStateWithoutBridgeFails(t *testing.T) {
	raw := stateJSON(map[string][][2]string{"eth0": {{"10.100.0.50", "global"}}})
	if _, err := parseIncusState(raw, nil, nil); err == nil {
		t.Fatal("expected bridge not configured error")
	}
}
