package termbridge

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// NewEphemeralKey generates an ed25519 key pair in a fresh 0700 directory under dir (the system
// temp directory when empty). cleanup removes the directory and is safe to call more than once.
func NewEphemeralKey(dir string) (privatePath, publicLine string, cleanup func(), err error) {
	work, err := os.MkdirTemp(dir, "yak-terminal-")
	if err != nil {
		return "", "", nil, errors.New("cannot create key directory")
	}
	cleanup = func() { os.RemoveAll(work) }

	privatePath = filepath.Join(work, "id_ed25519")
	if err := exec.Command("ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "yak-terminal", "-f", privatePath).Run(); err != nil {
		cleanup()
		return "", "", nil, errors.New("ssh-keygen failed")
	}

	public, err := os.ReadFile(privatePath + ".pub")
	if err != nil {
		cleanup()
		return "", "", nil, errors.New("cannot read public key")
	}

	return privatePath, strings.TrimSpace(string(public)), cleanup, nil
}
