//go:build darwin

package termbridge

import (
	"os"
	"syscall"
	"unsafe"
)

const (
	tiocptygrant = 0x20007454
	tiocptyunlk  = 0x20007452
	tiocptygname = 0x40807453
)

// OpenPTY allocates a master/slave pair through /dev/ptmx.
func OpenPTY() (*os.File, string, error) {
	master, err := os.OpenFile("/dev/ptmx", os.O_RDWR|syscall.O_NOCTTY, 0)
	if err != nil {
		return nil, "", err
	}

	if err := ioctl(master.Fd(), tiocptygrant, 0); err != nil {
		master.Close()
		return nil, "", err
	}
	if err := ioctl(master.Fd(), tiocptyunlk, 0); err != nil {
		master.Close()
		return nil, "", err
	}

	var name [128]byte
	if err := ioctl(master.Fd(), tiocptygname, uintptr(unsafe.Pointer(&name[0]))); err != nil {
		master.Close()
		return nil, "", err
	}

	end := 0
	for end < len(name) && name[end] != 0 {
		end++
	}

	return master, string(name[:end]), nil
}
