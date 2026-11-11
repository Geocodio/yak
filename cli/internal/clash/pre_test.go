package clash

import (
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func TestPrecomposeUnicodeConfigStillSeesClash(t *testing.T) {
	t.Parallel()
	r := setup(t, true)
	r.Git("config", "core.precomposeunicode", "true")
	only(t, check(t, r, []testrepo.Entry{{Path: "café.txt"}, {Path: "café.txt"}}), "nfc_clash")
}
