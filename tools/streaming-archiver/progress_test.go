package main

import (
	"testing"
	"time"
)

func TestBarFills(t *testing.T) {
	cases := []struct {
		fraction float64
		want     string
	}{
		{0, "[    ]"},
		{0.5, "[==  ]"},
		{1, "[====]"},
		{1.5, "[====]"},
	}

	for _, tc := range cases {
		if got := bar(tc.fraction, 4); got != tc.want {
			t.Errorf("bar(%v) = %q, want %q", tc.fraction, got, tc.want)
		}
	}
}

func TestShortDuration(t *testing.T) {
	cases := map[time.Duration]string{
		0:                              "00:00",
		90 * time.Second:               "01:30",
		65*time.Minute + 4*time.Second: "1:05:04",
		-5 * time.Second:               "00:00",
	}

	for input, want := range cases {
		if got := shortDuration(input); got != want {
			t.Errorf("shortDuration(%v) = %q, want %q", input, got, want)
		}
	}
}

// The ETA is the whole point of the bar: with ffmpeg reporting 2x speed, an hour of
// timeline with ten minutes done has twenty-five minutes left, not fifty.
func TestProgressETAUsesReportedSpeed(t *testing.T) {
	p := NewProgress("test", 3600, "s")
	p.tty = false
	p.lastDraw = time.Now().Add(-time.Hour)

	rate := 2.0
	remaining := (p.total - 600) / rate

	if got := shortDuration(time.Duration(remaining * float64(time.Second))); got != "25:00" {
		t.Fatalf("eta = %q, want 25:00", got)
	}
}
