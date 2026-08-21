package main

import (
	"encoding/json"
	"fmt"
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
	uploaded  map[string]int
	committed struct {
		Segments   []commitSegment `json:"segments"`
		Renditions []string        `json:"renditions"`
	}
	server *httptest.Server
}

func newStubSite(t *testing.T, startsAt time.Time) *stubSite {
	t.Helper()
	site := &stubSite{uploaded: map[string]int{}}

	mux := http.NewServeMux()

	mux.HandleFunc("POST /api/recording/imports", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 201, map[string]any{"data": map[string]any{
			"import": map[string]any{
				"id":        "test-import",
				"prefix":    "vod",
				"session":   "1787000000000",
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

		urls := make([]SignedURL, 0, len(body.Segments))
		for _, segment := range body.Segments {
			key := fmt.Sprintf("archive/vod/%s/vod_%s_1787000000000_%06d.ts", segment.Hour, segment.Rendition, segment.Number)
			urls = append(urls, SignedURL{Key: key, URL: site.server.URL + "/put/" + key})
		}
		writeJSON(w, 200, map[string]any{"data": urls})
	})

	mux.HandleFunc("PUT /put/", func(w http.ResponseWriter, r *http.Request) {
		site.mu.Lock()
		defer site.mu.Unlock()
		site.uploaded[strings.TrimPrefix(r.URL.Path, "/put/")]++
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
