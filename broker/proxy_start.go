package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/url"
	"os"
	"strings"
)

// proxyHandle is a started auth proxy: the revoker the broker tells about
// ended runners and the address the listener bound.
type proxyHandle struct {
	Revoker PlaceholderRevoker
	Addr    net.Addr
}

// startProxy listens for sandbox connections and serves the auth proxy until
// ctx is cancelled. An unset or invalid configuration is an error so the
// broker never runs without the proxy.
func startProxy(ctx context.Context, socketPath string, logger *slog.Logger) (proxyHandle, error) {
	address := os.Getenv("YAK_BROKER_PROXY_ADDR")
	if address == "" {
		return proxyHandle{}, errors.New("YAK_BROKER_PROXY_ADDR is not set")
	}
	hostNets, err := parseCIDRs(os.Getenv("YAK_BROKER_PROXY_HOST_CIDRS"))
	if err != nil {
		return proxyHandle{}, err
	}
	rawUpstream := os.Getenv("YAK_BROKER_PROXY_UPSTREAM")
	if rawUpstream == "" {
		rawUpstream = "https://api.anthropic.com"
	}
	upstream, err := url.Parse(rawUpstream)
	if err != nil || upstream.Scheme == "" || upstream.Host == "" {
		return proxyHandle{}, errors.New("YAK_BROKER_PROXY_UPSTREAM is not a valid URL")
	}
	listener, err := net.Listen("tcp", address)
	if err != nil {
		return proxyHandle{}, fmt.Errorf("listen on proxy address: %w", err)
	}
	proxy := NewAuthProxy(SocketCredentialSource{Path: socketPath}, upstream, logger)
	filtered := newPeerFilterListener(listener, hostNets, interfaceSubnets(listener.Addr()))
	go func() {
		if err := proxy.Serve(ctx, filtered); err != nil {
			logger.Error("auth proxy stopped", "error", err)
		}
	}()
	return proxyHandle{Revoker: proxy, Addr: listener.Addr()}, nil
}

// runProxy is what main calls: the credential socket default plus startProxy.
func runProxy(ctx context.Context, logger *slog.Logger) (proxyHandle, error) {
	socket := os.Getenv("YAK_BROKER_CREDENTIAL_SOCKET")
	if socket == "" {
		socket = "/var/lib/yak/credentials/redeem.sock"
	}
	return startProxy(ctx, socket, logger)
}

func parseCIDRs(list string) ([]*net.IPNet, error) {
	var nets []*net.IPNet
	if strings.TrimSpace(list) == "" {
		return nets, nil
	}
	for _, entry := range strings.Split(list, ",") {
		_, network, err := net.ParseCIDR(strings.TrimSpace(entry))
		if err != nil {
			return nil, fmt.Errorf("YAK_BROKER_PROXY_HOST_CIDRS entry %q is not a CIDR", entry)
		}
		nets = append(nets, network)
	}
	return nets, nil
}

// interfaceSubnets returns the subnet of the interface that owns the listen
// IP, or nothing when the listener is bound to a wildcard address.
func interfaceSubnets(listen net.Addr) []*net.IPNet {
	tcp, ok := listen.(*net.TCPAddr)
	if !ok || tcp.IP.IsUnspecified() {
		return nil
	}
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil
	}
	var nets []*net.IPNet
	for _, addr := range addrs {
		if network, ok := addr.(*net.IPNet); ok && network.IP.Equal(tcp.IP) {
			nets = append(nets, &net.IPNet{IP: network.IP.Mask(network.Mask), Mask: network.Mask})
		}
	}
	return nets
}

// peerFilterListener closes connections whose peer is outside every allowed
// network before HTTP sees them.
type peerFilterListener struct {
	net.Listener
	allowed []*net.IPNet
}

func newPeerFilterListener(listener net.Listener, hostNets, interfaceNets []*net.IPNet) *peerFilterListener {
	return &peerFilterListener{Listener: listener, allowed: append(append([]*net.IPNet{}, hostNets...), interfaceNets...)}
}

func (l *peerFilterListener) Accept() (net.Conn, error) {
	for {
		connection, err := l.Listener.Accept()
		if err != nil {
			return nil, err
		}
		if l.permits(connection.RemoteAddr()) {
			return connection, nil
		}
		_ = connection.Close()
	}
}

func (l *peerFilterListener) permits(remote net.Addr) bool {
	tcp, ok := remote.(*net.TCPAddr)
	if !ok {
		return false
	}
	for _, network := range l.allowed {
		if network.Contains(tcp.IP) {
			return true
		}
	}
	return false
}
