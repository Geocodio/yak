package main

import (
	"context"
	"fmt"
	"net"
	"strconv"
	"sync"
)

var loopbackRange = &net.IPNet{IP: net.IPv4(127, 0, 0, 0), Mask: net.CIDRMask(8, 32)}

// Reconciler keeps one bound listener per address Yak reports.
// OnConn runs in its own goroutine for each accepted connection; the address is the listener's, never the client's.
type Reconciler struct {
	Client YakClient
	Listen func(address string) (net.Listener, error)
	OnConn func(address string, conn net.Conn)

	mutex     sync.Mutex
	listeners map[string]net.Listener
}

// Sync binds new addresses, closes removed ones and keeps unchanged ones.
func (r *Reconciler) Sync(ctx context.Context) error {
	reported, err := r.Client.Listeners(ctx)
	if err != nil {
		return err
	}

	r.mutex.Lock()
	defer r.mutex.Unlock()

	if r.listeners == nil {
		r.listeners = map[string]net.Listener{}
	}

	wanted := map[string]int{}
	for _, entry := range reported {
		ip := net.ParseIP(entry.Address)
		if ip == nil || ip.To4() == nil || !loopbackRange.Contains(ip) {
			continue
		}
		port := entry.Port
		if port == 0 {
			port = 22
		}
		wanted[entry.Address] = port
	}

	for address, listener := range r.listeners {
		if _, keep := wanted[address]; !keep {
			_ = listener.Close()
			delete(r.listeners, address)
		}
	}

	var firstError error
	for address, port := range wanted {
		if _, bound := r.listeners[address]; bound {
			continue
		}

		listener, err := r.Listen(net.JoinHostPort(address, strconv.Itoa(port)))
		if err != nil {
			if firstError == nil {
				firstError = fmt.Errorf("bind %s: %w", address, err)
			}
			continue
		}

		r.listeners[address] = listener
		go r.accept(address, listener)
	}

	return firstError
}

func (r *Reconciler) accept(address string, listener net.Listener) {
	for {
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		if r.OnConn == nil {
			_ = conn.Close()
			continue
		}
		go r.OnConn(address, conn)
	}
}

// Addresses lists the bound addresses.
func (r *Reconciler) Addresses() []string {
	r.mutex.Lock()
	defer r.mutex.Unlock()

	addresses := make([]string, 0, len(r.listeners))
	for address := range r.listeners {
		addresses = append(addresses, address)
	}

	return addresses
}

// Close closes every listener.
func (r *Reconciler) Close() {
	r.mutex.Lock()
	defer r.mutex.Unlock()

	for address, listener := range r.listeners {
		_ = listener.Close()
		delete(r.listeners, address)
	}
}
