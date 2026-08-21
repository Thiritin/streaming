package main

import (
	"runtime"
	"strings"
	"testing"
)

func withAvailability(t *testing.T, available map[string]bool) {
	t.Helper()

	original := encoderAvailable
	encoderAvailable = func(encoder string) bool { return available[encoder] }
	t.Cleanup(func() { encoderAvailable = original })
}

func TestAutoPrefersHardware(t *testing.T) {
	// NVIDIA present, Apple's engine not: every platform should reach for nvenc.
	withAvailability(t, map[string]bool{encoderNVENC: true})

	got, err := resolveEncoder("auto")
	if err != nil {
		t.Fatalf("auto: %v", err)
	}
	if got != encoderNVENC {
		t.Fatalf("auto chose %q, expected nvenc", got)
	}
}

func TestAutoFallsBackToSoftware(t *testing.T) {
	withAvailability(t, map[string]bool{})

	got, err := resolveEncoder("auto")
	if err != nil {
		t.Fatalf("auto: %v", err)
	}
	if got != encoderX264 {
		t.Fatalf("auto chose %q with no hardware, expected x264", got)
	}
}

func TestOnAMacAppleWinsOverNvidia(t *testing.T) {
	if runtime.GOOS != "darwin" {
		t.Skip("platform preference only applies on macOS")
	}
	withAvailability(t, map[string]bool{encoderVideoToolbox: true, encoderNVENC: true})

	got, _ := resolveEncoder("auto")
	if got != encoderVideoToolbox {
		t.Fatalf("auto chose %q on a Mac, expected videotoolbox", got)
	}
}

// Asking for hardware that is not there has to fail before the encode, with a reason.
func TestExplicitNvencWithoutACardFails(t *testing.T) {
	withAvailability(t, map[string]bool{})

	if _, err := resolveEncoder("nvenc"); err == nil {
		t.Fatal("expected an error when nvenc is unusable")
	} else if !strings.Contains(err.Error(), "NVIDIA driver") {
		t.Fatalf("error should say what to check, got: %v", err)
	}
}

func TestNvencRungArgs(t *testing.T) {
	recipe := Recipe{SegmentSeconds: 2}
	sd := Rendition{
		Name: "sd", Width: 854, Height: 480,
		VideoBitrate: "1500k", Maxrate: "2000k", Bufsize: "3000k",
		Profile: "baseline", Preset: "veryfast",
	}

	args := strings.Join(rungArgs(0, sd, recipe, encoderNVENC, ""), " ")

	for _, want := range []string{"h264_nvenc", "-b:v:0 1500k", "-maxrate:v:0 2000k", "-preset p4", "-rc vbr", "expr:gte(t,n_forced*2)"} {
		if !strings.Contains(args, want) {
			t.Errorf("nvenc args missing %q: %s", want, args)
		}
	}

	// NVENC has no baseline; main is what it would silently give anyway.
	if !strings.Contains(args, "-profile:v:0 main") {
		t.Errorf("baseline should map to main for nvenc: %s", args)
	}
}

// The lesson from VideoToolbox: a ceiling made it spend far below the target. NVENC's VBR
// handles one properly, so the rungs keep theirs.
func TestVideoToolboxRungHasNoCeiling(t *testing.T) {
	recipe := Recipe{SegmentSeconds: 2}
	fhd := Rendition{
		Name: "fhd", Width: 1920, Height: 1080,
		VideoBitrate: "6000k", Maxrate: "6500k", Bufsize: "13000k",
		Profile: "main", Preset: "veryfast",
	}

	args := strings.Join(rungArgs(2, fhd, recipe, encoderVideoToolbox, ""), " ")

	if strings.Contains(args, "-maxrate") || strings.Contains(args, "-bufsize") {
		t.Errorf("videotoolbox rung must not carry a bitrate ceiling: %s", args)
	}
	if !strings.Contains(args, "-b:v:2 6000k") {
		t.Errorf("videotoolbox rung lost its bitrate: %s", args)
	}
}
