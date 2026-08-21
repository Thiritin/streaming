package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// A stand-in for the site: the three endpoints the CLI calls, plus somewhere for the
// presigned PUTs to land. Enough to drive a real encode and a real upload without an S3.
type stubSite struct {
	mu        sync.Mutex
	sessions  int
	byImport  map[string]string
	failures  int
	attempts  int
	uploaded  map[string]int
	committed struct {
		Segments   []commitSegment `json:"segments"`
		Renditions []string        `json:"renditions"`
	}
	server *httptest.Server
}

func newStubSite(t *testing.T, startsAt time.Time) *stubSite {
	t.Helper()
	site := &stubSite{uploaded: map[string]int{}, byImport: map[string]string{}}

	mux := http.NewServeMux()

	mux.HandleFunc("POST /api/recording/imports", func(w http.ResponseWriter, r *http.Request) {
		site.mu.Lock()
		site.sessions++
		session := fmt.Sprintf("178700000000%d", site.sessions)
		id := fmt.Sprintf("import-%d", site.sessions)
		site.byImport[id] = session
		site.mu.Unlock()

		writeJSON(w, 201, map[string]any{"data": map[string]any{
			"import": map[string]any{
				"id":        id,
				"prefix":    "vod",
				"session":   session,
				"starts_at": startsAt.UTC().Format(time.RFC3339),
				"title":     "Test",
			},
			"recipe": map[string]any{
				"segment_seconds": 2,
				"sd_fps_ceiling":  30,
				"renditions": map[string]any{
					"sd":  rung(1500000, 854, 480, "1500k", "2000k", "3000k", "baseline", "128k", true),
					"hd":  rung(3500000, 1280, 720, "3500k", "4000k", "8000k", "main", "160k", false),
					"fhd": rung(6000000, 1920, 1080, "6000k", "6500k", "13000k", "main", "192k", false),
				},
			},
			"segment_name": "vod_{rendition}_1787000000000_{number}.ts",
		}})
	})

	mux.HandleFunc("POST /api/recording/imports/{id}/urls", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Segments []urlRequest `json:"segments"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			writeJSON(w, 422, map[string]any{"error": err.Error()})
			return
		}

		// Deliberately the PSR-7 shape the AWS SDK produces - a list per header - because
		// that is what the site actually answered with, and a client that could not read
		// it threw away a finished encode.
		urls := make([]map[string]any, 0, len(body.Segments))
		for _, segment := range body.Segments {
			key := fmt.Sprintf("archive/vod/%s/vod_%s_%s_%06d.ts", segment.Hour, segment.Rendition, site.sessionFor(r.PathValue("id")), segment.Number)
			urls = append(urls, map[string]any{
				"key":     key,
				"url":     site.server.URL + "/put/" + key,
				"headers": map[string]any{"Host": []string{"example.test"}, "X-Amz-Acl": "private"},
			})
		}
		writeJSON(w, 200, map[string]any{"data": urls})
	})

	mux.HandleFunc("PUT /put/", func(w http.ResponseWriter, r *http.Request) {
		site.mu.Lock()
		site.attempts++
		// A run of failures at the front, standing in for a network that drops: the body
		// is drained first so the client sees a real HTTP answer rather than a reset.
		if site.failures > 0 {
			site.failures--
			site.mu.Unlock()
			io.Copy(io.Discard, r.Body)
			w.WriteHeader(503)
			return
		}
		site.uploaded[strings.TrimPrefix(r.URL.Path, "/put/")]++
		site.mu.Unlock()

		io.Copy(io.Discard, r.Body)
		w.WriteHeader(200)
	})

	mux.HandleFunc("POST /api/recording/imports/{id}/commit", func(w http.ResponseWriter, r *http.Request) {
		site.mu.Lock()
		defer site.mu.Unlock()
		if err := json.NewDecoder(r.Body).Decode(&site.committed); err != nil {
			writeJSON(w, 422, map[string]any{"error": err.Error()})
			return
		}
		writeJSON(w, 201, map[string]any{"data": map[string]any{
			"recording_id": 7, "slug": "test", "duration": 6, "segment_count": len(site.committed.Segments),
			"status": "ready", "manage_url": "https://example.test/manage/recordings/7",
			"watch_url": "https://example.test/archive/test",
		}})
	})

	site.server = httptest.NewServer(mux)
	t.Cleanup(site.server.Close)

	return site
}

func (s *stubSite) sessionFor(id string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.byImport[id]
}

func rung(bandwidth, width, height int, bitrate, maxrate, bufsize, profile, audio string, halve bool) map[string]any {
	return map[string]any{
		"bandwidth": bandwidth, "width": width, "height": height,
		"resolution":    fmt.Sprintf("%dx%d", width, height),
		"video_bitrate": bitrate, "maxrate": maxrate, "bufsize": bufsize,
		"profile": profile, "preset": "ultrafast", "audio_bitrate": audio,
		"halve_frame_rate": halve,
	}
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

// A short 50p clip, so the sd rung's frame rate halving is exercised too.
func fixture(t *testing.T) string {
	t.Helper()

	for _, tool := range []string{"ffmpeg", "ffprobe"} {
		if _, err := exec.LookPath(tool); err != nil {
			t.Skipf("%s not on PATH", tool)
		}
	}

	path := filepath.Join(t.TempDir(), "master.mp4")
	cmd := exec.Command("ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
		"-f", "lavfi", "-i", "testsrc2=size=1920x1080:rate=50:duration=6",
		"-f", "lavfi", "-i", "sine=frequency=440:duration=6",
		"-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p",
		"-c:a", "aac", "-shortest", path)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("could not build fixture: %v\n%s", err, out)
	}

	return path
}

func TestImportEncodesUploadsAndCommits(t *testing.T) {
	master := fixture(t)
	startsAt := time.Date(2026, 8, 20, 23, 59, 58, 0, time.UTC)
	site := newStubSite(t, startsAt)

	err := runImport([]string{
		"--api", site.server.URL,
		"--key", "test-key",
		"--title", "Test Import",
		"--work", t.TempDir(),
		"--preset", "ultrafast",
		master,
	})
	if err != nil {
		t.Fatalf("import failed: %v", err)
	}

	site.mu.Lock()
	defer site.mu.Unlock()

	if len(site.committed.Segments) == 0 {
		t.Fatal("nothing was committed")
	}

	// One object per segment per rung, and every one uploaded exactly once.
	want := len(site.committed.Segments) * len(site.committed.Renditions)
	if len(site.uploaded) != want {
		t.Fatalf("uploaded %d objects, expected %d", len(site.uploaded), want)
	}
	for key, count := range site.uploaded {
		if count != 1 {
			t.Fatalf("%s was uploaded %d times", key, count)
		}
	}

	// The clip straddles midnight, so both day buckets must appear: hour bucketing is
	// derived from each segment's own start, not from the import's.
	var sawLate, sawEarly bool
	for key := range site.uploaded {
		sawLate = sawLate || strings.Contains(key, "/20260820/23/")
		sawEarly = sawEarly || strings.Contains(key, "/20260821/00/")
	}
	if !sawLate || !sawEarly {
		t.Fatalf("expected segments in both hour buckets, got keys like %v", firstKey(site.uploaded))
	}

	total := 0.0
	for _, segment := range site.committed.Segments {
		if segment.Duration <= 0 {
			t.Fatalf("segment %d reported duration %v", segment.Number, segment.Duration)
		}
		total += segment.Duration
	}
	if total < 5.5 || total > 6.5 {
		t.Fatalf("committed durations sum to %.3fs, expected about 6s", total)
	}
}

func firstKey(m map[string]int) string {
	for key := range m {
		return key
	}
	return ""
}

// The order people actually type: file first, flags after. Go's flag package stops at the
// first operand, so without permute() this reads --title as a fourth input file.
func TestFlagsAfterTheFilename(t *testing.T) {
	flags := flag.NewFlagSet("import", flag.ContinueOnError)
	title := flags.String("title", "", "")
	keep := flags.Bool("keep", false, "")
	preset := flags.String("preset", "", "")

	argv := []string{"opening.mp4", "--title", "Opening Ceremony", "--keep", "--preset=faster"}

	if err := flags.Parse(permute(flags, argv)); err != nil {
		t.Fatalf("parse: %v", err)
	}

	if flags.NArg() != 1 || flags.Arg(0) != "opening.mp4" {
		t.Fatalf("operands = %v, want [opening.mp4]", flags.Args())
	}
	if *title != "Opening Ceremony" {
		t.Errorf("title = %q", *title)
	}
	if !*keep {
		t.Error("--keep did not survive the reorder")
	}
	if *preset != "faster" {
		t.Errorf("preset = %q", *preset)
	}
}

// A bare -- means what follows is a filename even if it starts with a dash.
func TestDoubleDashEndsFlags(t *testing.T) {
	flags := flag.NewFlagSet("import", flag.ContinueOnError)
	title := flags.String("title", "", "")

	argv := []string{"--title", "T", "--", "-weird-name.mp4"}

	if err := flags.Parse(permute(flags, argv)); err != nil {
		t.Fatalf("parse: %v", err)
	}
	if *title != "T" || flags.NArg() != 1 || flags.Arg(0) != "-weird-name.mp4" {
		t.Fatalf("title=%q operands=%v", *title, flags.Args())
	}
}

// Both header shapes have to parse: a list per header from the AWS SDK, and a flat string
// from a site that normalises them.
func TestSignedURLAcceptsBothHeaderShapes(t *testing.T) {
	var listed SignedURL
	if err := json.Unmarshal([]byte(`{"key":"k","url":"u","headers":{"Host":["a.test"],"X-Two":["a","b"]}}`), &listed); err != nil {
		t.Fatalf("list shape: %v", err)
	}
	if listed.Headers["Host"] != "a.test" || listed.Headers["X-Two"] != "a, b" {
		t.Fatalf("list shape parsed as %v", listed.Headers)
	}

	var flat SignedURL
	if err := json.Unmarshal([]byte(`{"key":"k","url":"u","headers":{"Host":"a.test"}}`), &flat); err != nil {
		t.Fatalf("flat shape: %v", err)
	}
	if flat.Headers["Host"] != "a.test" {
		t.Fatalf("flat shape parsed as %v", flat.Headers)
	}

	var missing SignedURL
	if err := json.Unmarshal([]byte(`{"key":"k","url":"u"}`), &missing); err != nil {
		t.Fatalf("absent headers: %v", err)
	}
}

// An import that dies after the encode - a rejected key, an S3 that stopped answering -
// must not cost the encode. A rerun against the same --work directory adopts it.
func TestReusesAnExistingEncode(t *testing.T) {
	master := fixture(t)
	site := newStubSite(t, time.Date(2026, 8, 20, 12, 0, 0, 0, time.UTC))
	work := t.TempDir()

	run := func() error {
		return runImport([]string{
			"--api", site.server.URL,
			"--key", "test-key",
			"--title", "Test Import",
			"--work", work,
			"--keep",
			"--preset", "ultrafast",
			"--encoder", "x264",
			master,
		})
	}

	if err := run(); err != nil {
		t.Fatalf("first import: %v", err)
	}

	first := time.Now()
	if err := run(); err != nil {
		t.Fatalf("second import: %v", err)
	}
	elapsed := time.Since(first)

	// The fixture takes seconds to encode and milliseconds to adopt, so this separates the
	// two without depending on the exact speed of the machine.
	if elapsed > 3*time.Second {
		t.Errorf("second run took %s: it re-encoded rather than reusing", elapsed)
	}

	site.mu.Lock()
	defer site.mu.Unlock()

	if site.sessions != 2 {
		t.Fatalf("expected two imports, got %d", site.sessions)
	}

	// The work directory remembers its import, so the second run writes the same keys as
	// the first rather than a second copy under a new session.
	if len(site.uploaded) != len(site.committed.Segments)*len(site.committed.Renditions) {
		t.Fatalf("object count %d: the second run wrote a second set of keys", len(site.uploaded))
	}
}

// A network that drops for a while must not end the import. The stub refuses the first
// few PUTs outright; every object still has to arrive.
func TestUploadsSurviveATemporaryOutage(t *testing.T) {
	master := fixture(t)
	site := newStubSite(t, time.Date(2026, 8, 20, 9, 0, 0, 0, time.UTC))

	site.mu.Lock()
	site.failures = 5
	site.mu.Unlock()

	err := runImport([]string{
		"--api", site.server.URL,
		"--key", "test-key",
		"--title", "Flaky Network",
		"--work", t.TempDir(),
		"--preset", "ultrafast",
		"--encoder", "x264",
		master,
	})
	if err != nil {
		t.Fatalf("import failed despite retries being possible: %v", err)
	}

	site.mu.Lock()
	defer site.mu.Unlock()

	want := len(site.committed.Segments) * len(site.committed.Renditions)
	if len(site.uploaded) != want {
		t.Fatalf("uploaded %d objects, expected %d", len(site.uploaded), want)
	}
	if site.attempts <= want {
		t.Errorf("expected retries on top of %d objects, saw %d attempts", want, site.attempts)
	}
}

// A rerun against the same work directory keeps the same import, so the objects that
// already landed are not sent again - and are not orphaned under a second session either.
func TestResumeKeepsTheImportAndSkipsWhatLanded(t *testing.T) {
	master := fixture(t)
	site := newStubSite(t, time.Date(2026, 8, 20, 10, 0, 0, 0, time.UTC))
	work := t.TempDir()

	run := func() error {
		return runImport([]string{
			"--api", site.server.URL,
			"--key", "test-key",
			"--title", "Resumed",
			"--work", work,
			"--keep",
			"--preset", "ultrafast",
			"--encoder", "x264",
			master,
		})
	}

	if err := run(); err != nil {
		t.Fatalf("first import: %v", err)
	}

	site.mu.Lock()
	afterFirst := site.attempts
	uploadedFirst := len(site.uploaded)
	site.mu.Unlock()

	if err := run(); err != nil {
		t.Fatalf("second import: %v", err)
	}

	site.mu.Lock()
	defer site.mu.Unlock()

	if site.attempts != afterFirst {
		t.Errorf("second run re-uploaded %d objects; the manifest should have skipped them", site.attempts-afterFirst)
	}
	if len(site.uploaded) != uploadedFirst {
		t.Errorf("object count changed from %d to %d: keys were rewritten under a new session",
			uploadedFirst, len(site.uploaded))
	}
}
