package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
)

// Probe is the little that matters about the master: how tall it is, how fast it runs,
// and how long it lasts.
type Probe struct {
	Height    int
	FPSNum    int
	FPSDen    int
	Duration  float64
	VideoCode string
}

func (p Probe) FPS() float64 {
	if p.FPSDen == 0 {
		return 0
	}
	return float64(p.FPSNum) / float64(p.FPSDen)
}

func ffprobe(path string) (*Probe, error) {
	cmd := exec.Command("ffprobe",
		"-v", "error",
		"-select_streams", "v:0",
		"-show_entries", "stream=height,r_frame_rate,codec_name",
		"-show_entries", "format=duration",
		"-of", "json",
		path,
	)

	var complaint bytes.Buffer
	cmd.Stderr = &complaint

	out, err := cmd.Output()
	if err != nil {
		// ffprobe's own words: "No such file or directory", "Invalid data found when
		// processing input", "moov atom not found". Far more use than an exit status.
		if detail := strings.TrimSpace(complaint.String()); detail != "" {
			return nil, fmt.Errorf("could not read %s: %s", filepath.Base(path), detail)
		}
		return nil, fmt.Errorf("could not read %s: %w", filepath.Base(path), err)
	}

	var parsed struct {
		Streams []struct {
			Height    int    `json:"height"`
			FrameRate string `json:"r_frame_rate"`
			CodecName string `json:"codec_name"`
		} `json:"streams"`
		Format struct {
			Duration string `json:"duration"`
		} `json:"format"`
	}
	if err := json.Unmarshal(out, &parsed); err != nil {
		return nil, err
	}
	if len(parsed.Streams) == 0 {
		return nil, fmt.Errorf("%s has no video stream", filepath.Base(path))
	}

	probe := &Probe{
		Height:    parsed.Streams[0].Height,
		VideoCode: parsed.Streams[0].CodecName,
		FPSNum:    25,
		FPSDen:    1,
	}

	if num, den, ok := strings.Cut(parsed.Streams[0].FrameRate, "/"); ok {
		probe.FPSNum, _ = strconv.Atoi(num)
		probe.FPSDen, _ = strconv.Atoi(den)
	}
	probe.Duration, _ = strconv.ParseFloat(parsed.Format.Duration, 64)

	return probe, nil
}

// ladderFor drops rungs the master cannot fill. Upscaling 720p to 1080p produces a bigger
// file carrying no more picture, and the master playlist would then advertise a resolution
// the archive does not really hold.
func ladderFor(recipe Recipe, probe *Probe) []Rendition {
	var usable []Rendition
	for _, rendition := range recipe.Ladder() {
		if probe.Height >= rendition.Height {
			usable = append(usable, rendition)
		}
	}
	return usable
}

// encode writes the ladder into dir as HLS, with the naming the server handed us.
//
// Mirrors docker/ffmpeg-hls/stream-manager.sh, with the differences an offline encode
// allows: a slower preset on the top rung, and no sliding window because nothing is
// watching this live.
func encode(input, dir string, imp Import, recipe Recipe, ladder []Rendition, probe *Probe, presetOverride string, onProgress func(done, speed float64)) error {
	var (
		filters   []string
		splits    strings.Builder
		maps      []string
		audio     []string
		streamMap []string
	)

	for i, rendition := range ladder {
		fmt.Fprintf(&splits, "[v%d]", i)

		rate := ""
		// A high frame rate master gets the bottom rung halved: 50p inside 1500 kbps
		// spends the budget on temporal resolution nobody watching 480p is short of.
		// Segment boundaries are unaffected, because keyframes are forced on time.
		if rendition.HalveFrameRate && probe.FPS() > float64(recipe.SDFPSCeiling) {
			rate = fmt.Sprintf(",fps=%d/%d", probe.FPSNum, probe.FPSDen*2)
		}

		// format=yuv420p is not cosmetic. A 10-bit master (HEVC Main 10 out of Resolve)
		// otherwise carries its pixel format into libx264, which then contradicts the
		// profile below and dies, or produces High 10 that no browser decodes.
		filters = append(filters, fmt.Sprintf(
			"[v%d]scale=w=%d:h=%d%s,format=yuv420p[v%dout]",
			i, rendition.Width, rendition.Height, rate, i,
		))

		preset := rendition.Preset
		if presetOverride != "" {
			preset = presetOverride
		}

		maps = append(maps,
			"-map", fmt.Sprintf("[v%dout]", i),
			fmt.Sprintf("-c:v:%d", i), "libx264",
			fmt.Sprintf("-b:v:%d", i), rendition.VideoBitrate,
			fmt.Sprintf("-maxrate:v:%d", i), rendition.Maxrate,
			fmt.Sprintf("-bufsize:v:%d", i), rendition.Bufsize,
			fmt.Sprintf("-preset:v:%d", i), preset,
			fmt.Sprintf("-profile:v:%d", i), rendition.Profile,
			fmt.Sprintf("-sc_threshold:v:%d", i), "0",
			fmt.Sprintf("-force_key_frames:v:%d", i),
			fmt.Sprintf("expr:gte(t,n_forced*%d)", recipe.SegmentSeconds),
		)

		audio = append(audio,
			"-map", "0:a:0",
			fmt.Sprintf("-c:a:%d", i), "aac",
			fmt.Sprintf("-b:a:%d", i), rendition.AudioBitrate,
			"-ac", "2", "-ar", "48000",
		)

		streamMap = append(streamMap, fmt.Sprintf("v:%d,a:%d,name:%s", i, i, rendition.Name))
	}

	filterComplex := fmt.Sprintf("[0:v]split=%d%s; %s", len(ladder), splits.String(), strings.Join(filters, "; "))

	args := []string{"-hide_banner", "-y", "-i", input, "-filter_complex", filterComplex}
	args = append(args, maps...)
	args = append(args, audio...)
	args = append(args,
		"-f", "hls",
		"-hls_time", strconv.Itoa(recipe.SegmentSeconds),
		"-hls_playlist_type", "vod",
		"-hls_list_size", "0",
		"-hls_flags", "independent_segments",
		"-hls_segment_type", "mpegts",
		"-start_number", "0",
		"-hls_segment_filename", filepath.Join(dir, fmt.Sprintf("%s_%%v_%s_%%06d.ts", imp.Prefix, imp.Session)),
		"-master_pl_name", "master.m3u8",
		"-var_stream_map", strings.Join(streamMap, " "),
		filepath.Join(dir, fmt.Sprintf("%s_%%v.m3u8", imp.Prefix)),
	)

	// -progress on a pipe is the machine-readable version of the status line ffmpeg
	// normally scribbles over stderr: key=value blocks, one per update, including how far
	// into the timeline it is and how fast it is running. That is what makes an honest ETA
	// possible instead of a spinner.
	args = append([]string{"-progress", "pipe:1", "-nostats", "-loglevel", "error"}, args...)

	cmd := exec.Command("ffmpeg", args...)

	pipe, err := cmd.StdoutPipe()
	if err != nil {
		return err
	}

	// stderr stays attached: with -loglevel error it only carries real problems, and those
	// belong in front of whoever is running the import.
	cmd.Stderr = os.Stderr

	if err := cmd.Start(); err != nil {
		return err
	}

	watchProgress(pipe, probe.Duration, onProgress)

	return cmd.Wait()
}

// watchProgress reads ffmpeg's -progress stream until it ends, reporting how many seconds
// of the timeline are done and the speed multiplier ffmpeg believes it is running at.
func watchProgress(pipe io.Reader, total float64, report func(done, speed float64)) {
	if report == nil {
		io.Copy(io.Discard, pipe)
		return
	}

	scanner := bufio.NewScanner(pipe)
	speed := 0.0

	for scanner.Scan() {
		key, value, ok := strings.Cut(strings.TrimSpace(scanner.Text()), "=")
		if !ok {
			continue
		}

		switch key {
		case "speed":
			// "1.85x", or "N/A" before the first frames are through.
			if parsed, err := strconv.ParseFloat(strings.TrimSuffix(value, "x"), 64); err == nil {
				speed = parsed
			}
		case "out_time_us", "out_time_ms":
			// out_time_ms is microseconds too, despite the name; ffmpeg has reported it
			// that way for years and changing it would break every parser in existence.
			if micros, err := strconv.ParseFloat(value, 64); err == nil {
				done := micros / 1_000_000
				if done > total {
					done = total
				}
				report(done, speed)
			}
		}
	}
}

// durations reads what the encoder itself said each segment lasts. Assuming a flat two
// seconds would be wrong at both ends: the last segment is short, and a keyframe can land
// late enough to stretch one.
func durations(dir, prefix, rendition string) ([]float64, error) {
	file, err := os.Open(filepath.Join(dir, fmt.Sprintf("%s_%s.m3u8", prefix, rendition)))
	if err != nil {
		return nil, err
	}
	defer file.Close()

	var out []float64
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if !strings.HasPrefix(line, "#EXTINF:") {
			continue
		}
		value := strings.TrimSuffix(strings.TrimPrefix(line, "#EXTINF:"), ",")
		seconds, err := strconv.ParseFloat(value, 64)
		if err != nil {
			return nil, fmt.Errorf("unreadable EXTINF %q: %w", line, err)
		}
		out = append(out, seconds)
	}

	return out, scanner.Err()
}
