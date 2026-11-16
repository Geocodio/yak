package main

import (
	"os"
	"os/exec"
	"syscall"
	"unsafe"
)

type winsize struct {
	Rows, Cols, X, Y uint16
}

func ioctl(fd uintptr, request uintptr, arg uintptr) error {
	if _, _, errno := syscall.Syscall(syscall.SYS_IOCTL, fd, request, arg); errno != 0 {
		return errno
	}

	return nil
}

// SetSize applies a window size to a PTY; the child's ssh sees SIGWINCH and sends a window-change.
func SetSize(pty *os.File, cols, rows uint16) error {
	size := winsize{Rows: rows, Cols: cols}

	return ioctl(pty.Fd(), syscall.TIOCSWINSZ, uintptr(unsafe.Pointer(&size)))
}

// StartWithPTY runs cmd with a fresh PTY as its controlling terminal and returns the master side.
// The child is a session leader, so closing the master hangs it up. The slave is opened with
// O_NOCTTY so the bridge itself never acquires it as a controlling terminal.
func StartWithPTY(cmd *exec.Cmd, cols, rows uint16) (*os.File, error) {
	master, slavePath, err := OpenPTY()
	if err != nil {
		return nil, err
	}

	slave, err := os.OpenFile(slavePath, os.O_RDWR|syscall.O_NOCTTY, 0)
	if err != nil {
		master.Close()
		return nil, err
	}
	defer slave.Close()

	if err := SetSize(slave, cols, rows); err != nil {
		master.Close()
		return nil, err
	}

	cmd.Stdin, cmd.Stdout, cmd.Stderr = slave, slave, slave
	if cmd.SysProcAttr == nil {
		cmd.SysProcAttr = &syscall.SysProcAttr{}
	}
	cmd.SysProcAttr.Setsid = true
	cmd.SysProcAttr.Setctty = true
	cmd.SysProcAttr.Ctty = 0

	if err := cmd.Start(); err != nil {
		master.Close()
		return nil, err
	}

	return master, nil
}
