// Command streaming-archiver imports an offline edit into the streaming site's segment archive.
//
// The site has no notion of "a video file". A recording is a range of a source's archive
// selected by wall clock, so an edit that never went out live has to become archive first:
// the same ladder, the same segment naming, the same hour indexes the live pipeline
// produces. This encodes on the machine that already holds the master, uploads the
// segments straight to object storage, and asks the site to commit the result as a cut.
//
// What the ladder is, where the segments go and what the indexes look like are all the
// server's to decide - this binary asks on every run. That is deliberate: a copy of the
// ladder baked into a released binary is a copy that goes stale.
//
//	streaming-archiver import "Opening Ceremony.mp4" --title "Opening Ceremony"
//
// Needs ffmpeg and ffprobe on PATH, the site URL (ARCHIVER_API) and the import key
// from /manage > Settings > Imports (ARCHIVER_KEY).
package main

import (
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// version is stamped at build time by .github/workflows/streaming-archiver.yml
// (-ldflags "-X main.version=..."). A binary built by hand says so.
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	switch os.Args[1] {
	case "import":
		if err := runImport(os.Args[2:]); err != nil {
			fmt.Fprintf(os.Stderr, "\nstreaming-archiver: %v\n", err)
			os.Exit(1)
		}
	case "-v", "--version", "version":
		fmt.Printf("streaming-archiver %s\n", version)
	case "-h", "--help", "help":
		usage()
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n", os.Args[1])
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `streaming-archiver - import an edited video into the streaming archive

usage:
  streaming-archiver import <file> --title "Opening Ceremony" [flags]
  streaming-archiver --version

flags:
  --title       recording title (required)
  --slug        url slug (default: derived from the title by the site)
  --description recording description
  --date        recording date, RFC3339 (default: now)
  --prefix      archive prefix to import under (default: the site's, normally "vod")
  --api         site base url (default: $ARCHIVER_API)
  --key         import key, from /manage > Settings > Imports (default: $ARCHIVER_KEY)
  --preset      override the x264 preset on every rung, e.g. faster
  --parallel    concurrent uploads (default 8)
  --work        working directory for the encode (default: a temp dir, removed after)
  --keep        keep the encoded segments instead of removing them

environment:
  ARCHIVER_API, ARCHIVER_KEY
`)
}

func runImport(argv []string) error {
	flags := flag.NewFlagSet("import", flag.ExitOnError)
	title := flags.String("title", "", "recording title")
	slug := flags.String("slug", "", "url slug")
	description := flags.String("description", "", "recording description")
	date := flags.String("date", "", "recording date, RFC3339")
	prefix := flags.String("prefix", "", "archive prefix")
	api := flags.String("api", os.Getenv("ARCHIVER_API"), "site base url")
	key := flags.String("key", os.Getenv("ARCHIVER_KEY"), "import key")
	preset := flags.String("preset", "", "x264 preset override")
	parallel := flags.Int("parallel", 8, "concurrent uploads")
	work := flags.String("work", "", "working directory")
	keep := flags.Bool("keep", false, "keep encoded segments")

	if err := flags.Parse(argv); err != nil {
		return err
	}

	if flags.NArg() != 1 {
		return errors.New("give exactly one input file")
	}
	input := flags.Arg(0)

	if *title == "" {
		return errors.New("--title is required")
	}
	if *api == "" || *key == "" {
		return errors.New("set --api and --key (or ARCHIVER_API and ARCHIVER_KEY)")
	}
	for _, tool := range []string{"ffmpeg", "ffprobe"} {
		if err := haveTool(tool); err != nil {
			return err
		}
	}

	probe, err := ffprobe(input)
	if err != nil {
		return err
	}
	fmt.Printf("Input     %s\n", filepath.Base(input))
	fmt.Printf("          %s, %dp%.4g, %s\n", probe.VideoCode, probe.Height, probe.FPS(), humanDuration(probe.Duration))

	client := NewClient(*api, *key)

	meta := map[string]any{"title": *title}
	if *slug != "" {
		meta["slug"] = *slug
	}
	if *description != "" {
		meta["description"] = *description
	}
	if *date != "" {
		meta["date"] = *date
	}
	if *prefix != "" {
		meta["prefix"] = *prefix
	}

	started, err := client.Start(meta)
	if err != nil {
		return fmt.Errorf("could not open the import: %w", err)
	}

	imp := started.Import
	ladder := ladderFor(started.Recipe, probe)
	if len(ladder) == 0 {
		return fmt.Errorf("the master is %dp, below every rung the site asks for", probe.Height)
	}

	names := make([]string, 0, len(ladder))
	for _, rendition := range ladder {
		names = append(names, rendition.Name)
	}

	fmt.Printf("Import    %s\n", imp.ID)
	fmt.Printf("Archive   %s, starting %s\n", imp.Prefix, imp.StartsAt)
	fmt.Printf("Ladder    %s\n\n", strings.Join(names, ", "))

	dir := *work
	if dir == "" {
		dir, err = os.MkdirTemp("", "streaming-archiver-")
		if err != nil {
			return err
		}
	} else if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	if !*keep {
		defer os.RemoveAll(dir)
	}

	fmt.Printf("Encoding into %s\n", dir)
	encodeStart := time.Now()

	// The ladder is encoded in one pass, so the timeline position ffmpeg reports is the
	// progress of the whole job - three rungs included.
	encoding := NewProgress("  encode ", probe.Duration, "s")
	err = encode(input, dir, imp, started.Recipe, ladder, probe, *preset, func(done, speed float64) {
		encoding.Set(done, speed)
	})
	if err != nil {
		return fmt.Errorf("encode failed: %w", err)
	}
	encoding.Done()

	// Every rung must be cut at identical instants, because one index entry describes all
	// of them. If they disagree the archive is wrong in a way playback only shows later.
	reference, err := durations(dir, imp.Prefix, ladder[0].Name)
	if err != nil {
		return err
	}
	for _, rendition := range ladder[1:] {
		other, err := durations(dir, imp.Prefix, rendition.Name)
		if err != nil {
			return err
		}
		if len(other) != len(reference) {
			return fmt.Errorf(
				"%s produced %d segments and %s produced %d: the rungs are not cut at the same instants",
				rendition.Name, len(other), ladder[0].Name, len(reference),
			)
		}
	}

	fmt.Printf("Encoded   %d segments in %s\n\n", len(reference), humanDuration(time.Since(encodeStart).Seconds()))

	startsAt, err := time.Parse(time.RFC3339, imp.StartsAt)
	if err != nil {
		return fmt.Errorf("the site returned an unreadable start time %q: %w", imp.StartsAt, err)
	}
	startsAt = startsAt.UTC()

	// Which hour bucket a segment belongs to follows from its own start, so it is derived
	// here rather than asked for: the server checks it against the import's window anyway.
	hours := make([]string, len(reference))
	at := startsAt
	for i, seconds := range reference {
		hours[i] = at.Format("20060102/15")
		at = at.Add(time.Duration(seconds * float64(time.Second)))
	}

	total := len(reference) * len(ladder)
	fmt.Printf("Uploading %d objects\n", total)
	uploaded := 0
	uploading := NewProgress("  upload ", float64(total), "objects")

	for start := 0; start < len(reference); start += uploadBatch {
		end := min(start+uploadBatch, len(reference))

		wanted := make([]urlRequest, 0, (end-start)*len(ladder))
		for n := start; n < end; n++ {
			for _, rendition := range ladder {
				wanted = append(wanted, urlRequest{Rendition: rendition.Name, Number: n, Hour: hours[n]})
			}
		}

		signed, err := client.SignURLs(imp.ID, wanted)
		if err != nil {
			return fmt.Errorf("could not get upload urls: %w", err)
		}
		if len(signed) != len(wanted) {
			return fmt.Errorf("asked for %d upload urls and got %d", len(wanted), len(signed))
		}

		jobs := make([]uploadJob, len(signed))
		for i, url := range signed {
			jobs[i] = uploadJob{
				Path:   filepath.Join(dir, fmt.Sprintf("%s_%s_%s_%06d.ts", imp.Prefix, wanted[i].Rendition, imp.Session, wanted[i].Number)),
				Signed: url,
			}
		}

		base := uploaded
		if err := upload(jobs, *parallel, func(done, _ int) {
			uploading.Set(float64(base+done), 0)
		}); err != nil {
			return err
		}
		uploaded += len(jobs)
	}

	uploading.Done()
	fmt.Println()

	segments := make([]commitSegment, len(reference))
	for i, seconds := range reference {
		segments[i] = commitSegment{Number: i, Duration: seconds}
	}

	result, err := client.Commit(imp.ID, segments, names)
	if err != nil {
		return fmt.Errorf("commit failed: %w", err)
	}

	fmt.Printf("Committed as recording %d (%s)\n", result.RecordingID, result.Slug)
	fmt.Printf("  duration   %s across %d segments\n", humanDuration(float64(result.Duration)), result.SegmentCount)
	fmt.Printf("  status     %s, unpublished\n", result.Status)
	fmt.Printf("  edit       %s\n", result.ManageURL)
	fmt.Printf("  watch      %s\n", result.WatchURL)

	return nil
}

func humanDuration(seconds float64) string {
	d := time.Duration(seconds * float64(time.Second)).Round(time.Second)
	if d < time.Hour {
		return fmt.Sprintf("%dm%02ds", int(d.Minutes()), int(d.Seconds())%60)
	}
	return fmt.Sprintf("%dh%02dm%02ds", int(d.Hours()), int(d.Minutes())%60, int(d.Seconds())%60)
}
