package main

import (
	"fmt"
	"os"
	"strings"
	"time"
)

// Progress is a one-line bar on stderr, with an ETA.
//
// An import is two long waits - a transcode and an upload - and both have a known total,
// so "how much longer" is answerable rather than something the operator has to guess from
// a scrolling log. Off a terminal it degrades to a periodic line, so a CI log or a `tee`
// does not fill up with carriage returns.
type Progress struct {
	label     string
	total     float64
	unit      string
	started   time.Time
	tty       bool
	lastDraw  time.Time
	lastValue float64
	finished  bool
}

func NewProgress(label string, total float64, unit string) *Progress {
	return &Progress{
		label:   label,
		total:   total,
		unit:    unit,
		started: time.Now(),
		tty:     isTerminal(os.Stderr),
	}
}

func isTerminal(file *os.File) bool {
	info, err := file.Stat()
	if err != nil {
		return false
	}
	return info.Mode()&os.ModeCharDevice != 0
}

// Set reports absolute progress. rate is progress units per wall second when the caller
// knows it better than we can measure - ffmpeg reports its own speed, which is steadier
// than dividing by elapsed time early in a run.
func (p *Progress) Set(value float64, rate float64) {
	if p.finished {
		return
	}

	p.lastValue = value

	// The last frame is Done()'s to draw, so a caller that reports completion before
	// finishing does not leave "100% eta --:--" on the screen.
	if value >= p.total {
		return
	}

	// A redraw every 200ms: often enough to look live, rare enough that the bar is not
	// what the process spends its time on.
	if p.tty && time.Since(p.lastDraw) < 200*time.Millisecond {
		return
	}
	if !p.tty && time.Since(p.lastDraw) < 10*time.Second {
		return
	}
	p.lastDraw = time.Now()

	fraction := 0.0
	if p.total > 0 {
		fraction = value / p.total
	}
	fraction = clamp(fraction, 0, 1)

	if rate <= 0 {
		elapsed := time.Since(p.started).Seconds()
		if elapsed > 0 {
			rate = value / elapsed
		}
	}

	eta := "--:--"
	if rate > 0 && value < p.total {
		eta = shortDuration(time.Duration((p.total - value) / rate * float64(time.Second)))
	}

	line := fmt.Sprintf("%s %s %s %s  eta %s",
		p.label,
		bar(fraction, 24),
		fmt.Sprintf("%3.0f%%", fraction*100),
		p.amount(value),
		eta,
	)

	if p.tty {
		fmt.Fprintf(os.Stderr, "\r\033[K%s", line)
		return
	}
	fmt.Fprintln(os.Stderr, line)
}

// Done leaves the finished line in place and moves off it.
func (p *Progress) Done() {
	if p.finished {
		return
	}
	p.finished = true

	elapsed := shortDuration(time.Since(p.started))
	line := fmt.Sprintf("%s %s 100%% %s  in %s",
		p.label, bar(1, 24), p.amount(p.total), elapsed)

	if p.tty {
		fmt.Fprintf(os.Stderr, "\r\033[K%s\n", line)
		return
	}
	fmt.Fprintln(os.Stderr, line)
}

func (p *Progress) amount(value float64) string {
	if p.unit == "s" {
		return fmt.Sprintf("%s/%s", shortDuration(time.Duration(value*float64(time.Second))), shortDuration(time.Duration(p.total*float64(time.Second))))
	}
	return fmt.Sprintf("%.0f/%.0f %s", value, p.total, p.unit)
}

func bar(fraction float64, width int) string {
	filled := int(fraction*float64(width) + 0.5)
	if filled > width {
		filled = width
	}
	return "[" + strings.Repeat("=", filled) + strings.Repeat(" ", width-filled) + "]"
}

func shortDuration(d time.Duration) string {
	d = d.Round(time.Second)
	if d < 0 {
		d = 0
	}

	hours := int(d.Hours())
	minutes := int(d.Minutes()) % 60
	seconds := int(d.Seconds()) % 60

	if hours > 0 {
		return fmt.Sprintf("%d:%02d:%02d", hours, minutes, seconds)
	}
	return fmt.Sprintf("%02d:%02d", minutes, seconds)
}

func clamp(value, low, high float64) float64 {
	if value < low {
		return low
	}
	if value > high {
		return high
	}
	return value
}
