package main

import (
	"fmt"
	"os/exec"
)

// haveTool fails early and by name. ffmpeg missing halfway through a 4 GB import is a
// worse way to learn the same thing.
func haveTool(name string) error {
	if _, err := exec.LookPath(name); err != nil {
		return fmt.Errorf("%s is not on PATH (macOS: brew install ffmpeg, Debian: apt install ffmpeg)", name)
	}
	return nil
}
