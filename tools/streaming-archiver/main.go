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
	"sync/atomic"
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
  --encoder     auto (default), videotoolbox or x264. auto uses Apple's media
                engine when ffmpeg offers it, which is many times faster; x264
                keeps a little more detail per bit
  --preset      override the x264 preset on every rung, e.g. faster (x264 only)
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
	encoderChoice := flags.String("encoder", "auto", "auto, videotoolbox or x264")
	parallel := flags.Int("parallel", 8, "concurrent uploads")
	work := flags.String("work", "", "working directory")
	keep := flags.Bool("keep", false, "keep encoded segments")

	if err := flags.Parse(permute(flags, argv)); err != nil {
		return err
	}

	if flags.NArg() != 1 {
		if flags.NArg() == 0 {
			return errors.New("give an input file")
		}
		return fmt.Errorf("give exactly one input file, got %d: %s", flags.NArg(), strings.Join(flags.Args(), ", "))
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

	// Checked before anything else so a typo in the path is not reported as a decode
	// failure, which is what ffprobe would call it.
	if info, err := os.Stat(input); err != nil {
		return fmt.Errorf("cannot open %s: %w", input, err)
	} else if info.IsDir() {
		return fmt.Errorf("%s is a directory", input)
	}

	encoder, err := resolveEncoder(*encoderChoice)
	if err != nil {
		return err
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
	resumed := false
	ladder := ladderFor(started.Recipe, probe)
	if len(ladder) == 0 {
		return fmt.Errorf("the master is %dp, below every rung the site asks for", probe.Height)
	}

	names := make([]string, 0, len(ladder))
	for _, rendition := range ladder {
		names = append(names, rendition.Name)
	}

	dir := *work
	if dir == "" {
		dir, err = os.MkdirTemp("", "streaming-archiver-")
		if err != nil {
			return err
		}
	} else if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}

	// A directory that already belongs to an import keeps it: same session, so the object
	// keys are the ones the last run was already writing, whatever landed then still
	// counts, and nothing is left orphaned in the bucket under a name no index mentions.
	if state, ok := loadImportState(dir, input); ok {
		imp = state.Import
		resumed = true
	} else if err := saveImportState(dir, input, imp); err != nil {
		return err
	}

	if resumed {
		fmt.Printf("Import    %s (resumed from %s)\n", imp.ID, dir)
	} else {
		fmt.Printf("Import    %s\n", imp.ID)
	}
	fmt.Printf("Archive   %s, starting %s\n", imp.Prefix, imp.StartsAt)
	fmt.Printf("Ladder    %s\n", strings.Join(names, ", "))
	fmt.Printf("Encoder   %s\n\n", encoderLabel(encoder, ladder, *preset))

	// The encode is only thrown away when the import as a whole succeeded. Anything else -
	// a rejected key, an S3 that stopped answering, a closed lid - keeps it, because
	// re-encoding an hour of video to recover from a failed upload is half an hour nobody
	// gets back. See the --work note printed on the way out.
	completed := false
	defer func() {
		if *keep {
			return
		}
		if completed {
			os.RemoveAll(dir)
			return
		}
		fmt.Fprintf(os.Stderr, "\nThe encode is kept at %s\n", dir)
		fmt.Fprintf(os.Stderr, "Rerun with --work %q to carry on without encoding again.\n", dir)
	}()

	if existing, ok, err := reuse(dir, imp, ladder); err != nil {
		return err
	} else if ok {
		fmt.Printf("Reusing the encode in %s (%d segments)\n\n", dir, len(existing))
		return finish(client, imp, ladder, names, existing, dir, *parallel, &completed)
	}

	fmt.Printf("Encoding into %s\n", dir)
	encodeStart := time.Now()

	// The ladder is encoded in one pass, so the timeline position ffmpeg reports is the
	// progress of the whole job - three rungs included.
	encoding := NewProgress("  encode ", probe.Duration, "s")
	err = encode(input, dir, imp, started.Recipe, ladder, probe, encoder, *preset, func(done, speed float64) {
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

	return finish(client, imp, ladder, names, reference, dir, *parallel, &completed)
}

// finish is everything after the media exists: work out which hour each segment belongs to,
// upload them, and commit. Shared by a fresh encode and a reused one, so a resumed import
// cannot drift from a first attempt.
func finish(
	client *Client,
	imp Import,
	ladder []Rendition,
	names []string,
	reference []float64,
	dir string,
	parallel int,
	completed *bool,
) error {
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

	// Keys that already landed in an earlier run of this work directory. Reported rather
	// than silently skipped, because "5859 objects" and "1200 objects" on the same import
	// otherwise looks like something went missing.
	tracker, err := openManifest(dir)
	if err != nil {
		return err
	}
	defer tracker.close()

	if done := tracker.count(); done > 0 {
		fmt.Printf("Uploading %d objects (%d already in the bucket from an earlier run)\n", total, done)
	} else {
		fmt.Printf("Uploading %d objects\n", total)
	}

	uploaded := tracker.count()
	uploading := NewProgress("  upload ", float64(total), "objects")
	uploading.Set(float64(uploaded), 0)

	var sent atomic.Int64
	uploadStarted := time.Now()

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

		jobs := make([]uploadJob, 0, len(signed))
		for i, url := range signed {
			if tracker.has(url.Key) {
				continue
			}

			path := filepath.Join(dir, fmt.Sprintf("%s_%s_%s_%06d.ts", imp.Prefix, wanted[i].Rendition, imp.Session, wanted[i].Number))

			info, err := os.Stat(path)
			if err != nil {
				return fmt.Errorf("segment missing from the encode: %w", err)
			}

			jobs = append(jobs, uploadJob{Path: path, Size: info.Size(), Signed: url})
		}

		if len(jobs) == 0 {
			continue
		}

		base := uploaded
		err = upload(jobs, parallel, tracker, uploadReport{
			Done: func(objects int, bytes int64) {
				uploading.Suffix = throughput(sent.Load()+bytes, uploadStarted)
				uploading.Set(float64(base+objects), 0)
			},
			Retry: func(name string, attempt int, wait time.Duration, cause error) {
				// Printed above the bar rather than swallowed: an upload that is quietly
				// retrying for two minutes and one that has hung look identical otherwise.
				uploading.Interrupt(fmt.Sprintf("  retry %s (attempt %d, waiting %s): %s",
					name, attempt, wait.Round(time.Second), short(cause)))
			},
		})
		if err != nil {
			return err
		}

		sent.Add(batchBytes(jobs))
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

	// Only now is the encode disposable: the archive holds the segments and the site has
	// a recording pointing at them.
	*completed = true

	fmt.Printf("Committed as recording %d (%s)\n", result.RecordingID, result.Slug)
	fmt.Printf("  duration   %s across %d segments\n", humanDuration(float64(result.Duration)), result.SegmentCount)
	fmt.Printf("  status     %s, unpublished\n", result.Status)
	fmt.Printf("  edit       %s\n", result.ManageURL)
	fmt.Printf("  watch      %s\n", result.WatchURL)

	return nil
}

// permute moves operands behind the flags.
//
// Go's flag package stops parsing at the first argument that is not a flag, so
// `import file.mp4 --title X` silently treats --title and its value as operands and the
// import dies claiming it was given four input files. Every other CLI on the machine
// accepts flags after the filename, so this reorders rather than teaching people a rule.
//
// Whether a flag swallows the next argument is asked of the FlagSet, not guessed: a bool
// flag does not, `--flag=value` never does, and anything after a bare `--` is an operand
// whatever it looks like.
func permute(flags *flag.FlagSet, argv []string) []string {
	var options, operands []string

	for i := 0; i < len(argv); i++ {
		arg := argv[i]

		if arg == "--" {
			operands = append(operands, argv[i+1:]...)
			break
		}

		if len(arg) < 2 || arg[0] != '-' {
			operands = append(operands, arg)
			continue
		}

		options = append(options, arg)

		name := strings.TrimLeft(arg, "-")
		if name, _, found := strings.Cut(name, "="); found {
			_ = name
			continue
		}

		flag := flags.Lookup(name)
		if flag == nil {
			// Unknown: let Parse produce its own message rather than guessing at arity.
			continue
		}

		if boolFlag, ok := flag.Value.(interface{ IsBoolFlag() bool }); ok && boolFlag.IsBoolFlag() {
			continue
		}

		if i+1 < len(argv) {
			i++
			options = append(options, argv[i])
		}
	}

	if operands == nil {
		return options
	}

	// The terminator is put back, so an operand that begins with a dash - an oddly named
	// file, or one produced by a shell glob - is still read as a filename.
	return append(append(options, "--"), operands...)
}

// encoderLabel names the encoder and, for x264, the presets in play - "slow on the top
// rung" is the difference between a twenty minute import and an overnight one, so it
// belongs on screen before the encode starts rather than in a process listing.
func encoderLabel(encoder string, ladder []Rendition, presetOverride string) string {
	if encoder == encoderVideoToolbox {
		return "h264_videotoolbox (Apple media engine)"
	}

	presets := make([]string, 0, len(ladder))
	for _, rendition := range ladder {
		preset := rendition.Preset
		if presetOverride != "" {
			preset = presetOverride
		}
		presets = append(presets, fmt.Sprintf("%s:%s", rendition.Name, preset))
	}

	return "libx264 (" + strings.Join(presets, " ") + ")"
}

func batchBytes(jobs []uploadJob) int64 {
	var total int64
	for _, job := range jobs {
		total += job.Size
	}
	return total
}

// throughput is the second half of the upload readout: objects say how far along it is,
// megabytes a second say whether the link is working.
func throughput(bytes int64, since time.Time) string {
	elapsed := time.Since(since).Seconds()
	if elapsed <= 0 || bytes <= 0 {
		return ""
	}

	return fmt.Sprintf("%.1f GB at %.1f MB/s",
		float64(bytes)/(1<<30),
		float64(bytes)/(1<<20)/elapsed,
	)
}

// short trims an error to something that fits beside a progress bar. The full text is a
// signed URL and a stack of context; the last clause is the part that says what happened.
func short(err error) string {
	text := err.Error()
	if index := strings.LastIndex(text, ": "); index != -1 && len(text)-index < 80 {
		return text[index+2:]
	}
	if len(text) > 80 {
		return text[:77] + "..."
	}
	return text
}

func humanDuration(seconds float64) string {
	d := time.Duration(seconds * float64(time.Second)).Round(time.Second)
	if d < time.Hour {
		return fmt.Sprintf("%dm%02ds", int(d.Minutes()), int(d.Seconds())%60)
	}
	return fmt.Sprintf("%dh%02dm%02ds", int(d.Hours()), int(d.Minutes())%60, int(d.Seconds())%60)
}
