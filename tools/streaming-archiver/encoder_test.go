package main

import (
	"errors"
	"runtime"
	"strings"
	"testing"
)

func withAvailability(t *testing.T, available map[string]bool) {
	t.Helper()

	original := probeEncoder
	probeEncoder = func(encoder string) (encoderPlan, error) {
		if !available[encoder] {
			return encoderPlan{}, errors.New("no NVIDIA capable devices were detected")
		}
		return encoderPlan{Name: encoder, Tuning: tunings(encoder)[0]}, nil
	}
	t.Cleanup(func() { probeEncoder = original })
}

func TestAutoPrefersHardware(t *testing.T) {
	// NVIDIA present, Apple's engine not: every platform should reach for nvenc.
	withAvailability(t, map[string]bool{encoderNVENC: true})

	got, notes, err := resolveEncoder("auto")
	if err != nil {
		t.Fatalf("auto: %v", err)
	}
	if got.Name != encoderNVENC {
		t.Fatalf("auto chose %q, expected nvenc", got.Name)
	}
	if len(got.Tuning) == 0 {
		t.Error("the plan should carry the tuning the probe proved")
	}
	_ = notes
}

func TestAutoFallsBackToSoftware(t *testing.T) {
	withAvailability(t, map[string]bool{})

	got, notes, err := resolveEncoder("auto")
	if err != nil {
		t.Fatalf("auto: %v", err)
	}
	if got.Name != encoderX264 {
		t.Fatalf("auto chose %q with no hardware, expected x264", got.Name)
	}

	// Falling back has to be visible: an import running eight times slower than it should
	// is a bad way to discover a driver needs updating.
	if len(notes) == 0 {
		t.Fatal("falling back to software silently; expected a note saying why")
	}
	// On a Mac the Apple engine is tried first, so the nvenc note is not necessarily the
	// first one - but every encoder that was passed over has to account for itself.
	joined := strings.Join(notes, "; ")
	if !strings.Contains(joined, "h264_nvenc unavailable") || !strings.Contains(joined, "NVIDIA capable devices") {
		t.Errorf("notes should name each encoder and its reason, got %q", joined)
	}
}

func TestOnAMacAppleWinsOverNvidia(t *testing.T) {
	if runtime.GOOS != "darwin" {
		t.Skip("platform preference only applies on macOS")
	}
	withAvailability(t, map[string]bool{encoderVideoToolbox: true, encoderNVENC: true})

	got, _, _ := resolveEncoder("auto")
	if got.Name != encoderVideoToolbox {
		t.Fatalf("auto chose %q on a Mac, expected videotoolbox", got.Name)
	}
}

// Asking for hardware that is not there has to fail before the encode, with a reason.
func TestExplicitNvencWithoutACardFails(t *testing.T) {
	withAvailability(t, map[string]bool{})

	_, _, err := resolveEncoder("nvenc")
	if err == nil {
		t.Fatal("expected an error when nvenc is unusable")
	}
	// Both halves matter: what ffmpeg said, and what the operator should check.
	if !strings.Contains(err.Error(), "NVIDIA capable devices") || !strings.Contains(err.Error(), "driver") {
		t.Fatalf("error should carry ffmpeg's reason and what to check, got: %v", err)
	}
}

func TestNvencRungArgs(t *testing.T) {
	recipe := Recipe{SegmentSeconds: 2}
	sd := Rendition{
		Name: "sd", Width: 854, Height: 480,
		VideoBitrate: "1500k", Maxrate: "2000k", Bufsize: "3000k",
		Profile: "baseline", Preset: "veryfast",
	}

	plan := encoderPlan{Name: encoderNVENC, Tuning: []string{"-preset", "p4", "-rc", "vbr"}}
	args := strings.Join(rungArgs(0, sd, recipe, plan, ""), " ")

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

	args := strings.Join(rungArgs(2, fhd, recipe, encoderPlan{Name: encoderVideoToolbox}, ""), " ")

	if strings.Contains(args, "-maxrate") || strings.Contains(args, "-bufsize") {
		t.Errorf("videotoolbox rung must not carry a bitrate ceiling: %s", args)
	}
	if !strings.Contains(args, "-b:v:2 6000k") {
		t.Errorf("videotoolbox rung lost its bitrate: %s", args)
	}
}

// An older NVENC build refuses the p1-p7 preset names. Rather than deciding from a version
// string, each tuning is tried in turn and the plan carries whichever one worked.
func TestNvencFallsBackToLegacyPresetNames(t *testing.T) {
	original := probeEncoder
	t.Cleanup(func() { probeEncoder = original })

	attempts := 0
	probeEncoder = func(encoder string) (encoderPlan, error) {
		// Stand-in for the real probe's loop: p4 refused, medium accepted.
		for _, tuning := range tunings(encoder) {
			attempts++
			if len(tuning) > 1 && tuning[1] == "p4" {
				continue
			}
			return encoderPlan{Name: encoder, Tuning: tuning}, nil
		}
		return encoderPlan{}, errors.New("nothing worked")
	}

	plan, _, err := resolveEncoder("nvenc")
	if err != nil {
		t.Fatalf("nvenc: %v", err)
	}
	if len(plan.Tuning) < 2 || plan.Tuning[1] != "medium" {
		t.Fatalf("expected the legacy preset to be adopted, got %v", plan.Tuning)
	}
	if attempts < 2 {
		t.Errorf("expected more than one tuning to be tried, saw %d", attempts)
	}
}
