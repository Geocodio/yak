//go:build linux

package main

import (
	"fmt"
	"os"
	"syscall"
	"unsafe"
)

const (
	tiocgptn   = 0x80045430
	tiocsptlck = 0x40045431
)

// OpenPTY allocates a master/slave pair through /dev/ptmx.
func OpenPTY() (*os.File, string, error) {
	master, err := os.OpenFile("/dev/ptmx", os.O_RDWR|syscall.O_NOCTTY, 0)
	if err != nil {
		return nil, "", err
	}

	unlock := int32(0)
	if err := ioctl(master.Fd(), tiocsptlck, uintptr(unsafe.Pointer(&unlock))); err != nil {
		master.Close()
		return nil, "", err
	}

	var number uint32
	if err := ioctl(master.Fd(), tiocgptn, uintptr(unsafe.Pointer(&number))); err != nil {
		master.Close()
		return nil, "", err
	}

	return master, fmt.Sprintf("/dev/pts/%d", number), nil
}
