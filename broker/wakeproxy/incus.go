package main

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"os/exec"
	"regexp"
)

var containerNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// ContainerResolver turns a container name from Yak into its IPv4 address.
type ContainerResolver interface {
	Resolve(ctx context.Context, container string) (string, error)
}

// sandboxInterface is the only NIC whose address is trusted; mirrors broker/runner.go.
const sandboxInterface = "eth0"

// IncusResolver asks the incus CLI for the sandbox NIC address, which must sit inside the Incus bridge subnet.
type IncusResolver struct {
	Bridge  *net.IPNet
	Gateway net.IP
}

func (r IncusResolver) Resolve(ctx context.Context, container string) (string, error) {
	if !containerNamePattern.MatchString(container) {
		return "", errors.New("invalid container name")
	}

	output, err := exec.CommandContext(ctx, "incus", "query", "/1.0/instances/"+container+"/state").Output()
	if err != nil {
		return "", errors.New("incus query failed")
	}

	return parseIncusState(output, r.Bridge, r.Gateway)
}

func parseIncusState(raw []byte, bridge *net.IPNet, gateway net.IP) (string, error) {
	if bridge == nil {
		return "", errors.New("bridge not configured")
	}

	var state struct {
		Network map[string]struct {
			Addresses []struct {
				Family  string `json:"family"`
				Address string `json:"address"`
				Scope   string `json:"scope"`
			} `json:"addresses"`
		} `json:"network"`
	}
	if err := json.Unmarshal(raw, &state); err != nil {
		return "", err
	}

	for _, address := range state.Network[sandboxInterface].Addresses {
		if address.Family != "inet" || address.Scope != "global" {
			continue
		}
		ip := net.ParseIP(address.Address)
		if ip == nil || !bridge.Contains(ip) || ip.Equal(gateway) {
			continue
		}

		return address.Address, nil
	}

	return "", errors.New("container has no address inside the bridge")
}
