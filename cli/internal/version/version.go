// Package version holds the build version and the wire protocol number.
package version

import "fmt"

// Version is overridden at release time with -ldflags -X.
var Version = "0.1.0"

// ProtocolVersion is sent as X-Yak-Protocol on every request.
const ProtocolVersion = 1

// UserAgent returns the contract User-Agent; amd64 is reported as x86_64.
func UserAgent(goos, goarch string) string {
	if goarch == "amd64" {
		goarch = "x86_64"
	}
	return fmt.Sprintf("yak-cli/%s (%s; %s)", Version, goos, goarch)
}
