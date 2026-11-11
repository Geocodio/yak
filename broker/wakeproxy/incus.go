package main

import (
	"context"
	"encoding/json"
	"errors"
	"os/exec"
	"regexp"
)

var containerNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// ContainerResolver turns a container name from Yak into its IPv4 address.
type ContainerResolver interface {
	Resolve(ctx context.Context, container string) (string, error)
}

// IncusResolver asks the incus CLI for the container's global IPv4 address.
type IncusResolver struct{}

func (IncusResolver) Resolve(ctx context.Context, container string) (string, error) {
	if !containerNamePattern.MatchString(container) {
		return "", errors.New("invalid container name")
	}

	output, err := exec.CommandContext(ctx, "incus", "query", "/1.0/instances/"+container+"/state").Output()
	if err != nil {
		return "", errors.New("incus query failed")
	}

	return parseIncusState(output)
}

func parseIncusState(raw []byte) (string, error) {
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

	for name, network := range state.Network {
		if name == "lo" {
			continue
		}
		for _, address := range network.Addresses {
			if address.Family == "inet" && address.Scope == "global" {
				return address.Address, nil
			}
		}
	}

	return "", errors.New("container has no address yet")
}
