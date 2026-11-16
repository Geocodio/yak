package teleport

import (
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
)

func TestFriendlyExpiryUsesServerMinutes(t *testing.T) {
	cases := map[string]string{
		"45": "That teleport expired after 45 minutes without activity. Nothing changed. Run yak teleport again.",
		"":   "That teleport expired after a while without activity. Nothing changed. Run yak teleport again.",
	}
	for minutes, want := range cases {
		details := map[string]any{}
		if minutes != "" {
			details["minutes"] = minutes
		}
		got := friendly(&api.Error{Status: 409, Code: "teleport_expired", Details: details})
		if got.Error() != want {
			t.Errorf("minutes=%q: got %q, want %q", minutes, got.Error(), want)
		}
	}
}
