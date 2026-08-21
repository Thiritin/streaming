package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
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
// rungArgs is how one rendition is encoded, which is where the two encoders differ.
//
// x264 is the reference: the live transcoder uses it, and at a fixed bitrate it keeps more
// detail than the hardware block does, particularly on the high-motion material a stage
// camera produces.
//
// VideoToolbox hands the work to the media engine on Apple silicon instead. It is an order
// of magnitude faster and barely touches the CPU, and it ignores -preset entirely: rate
// control is the bitrate and nothing else. The quality cost at these bitrates is real but
// small, and it is the difference between an import finishing over lunch and finishing
// overnight.
func rungArgs(i int, rendition Rendition, recipe Recipe, encoder string, presetOverride string) []string {
	args := []string{"-map", fmt.Sprintf("[v%dout]", i)}

	if encoder == encoderVideoToolbox {
		// No -maxrate/-bufsize, unlike the x264 rung. VideoToolbox's rate control reacts to
		// a ceiling by spending far less than the target rather than by trimming peaks:
		// measured on 30s of high-motion 1080p50 at a 6000k target, it delivered 3.7 Mbps
		// and VMAF 68.5 with the ladder's 6500k cap, 4.8 Mbps and 74.9 with a loose 8400k
		// one, and 6.1 Mbps and 79.1 with no cap at all - the last of those slightly ahead
		// of x264 veryfast on the same clip. The cap was the whole quality gap.
		//
		// What it costs is a softer ceiling: easy content lands nearer the target than
		// x264 would (4.0 Mbps against 3.3 on a static slice), so an import is a little
		// larger. Worth it to keep the hard passages intact.
		return append(args,
			fmt.Sprintf("-c:v:%d", i), "h264_videotoolbox",
			fmt.Sprintf("-b:v:%d", i), rendition.VideoBitrate,
			fmt.Sprintf("-profile:v:%d", i), rendition.Profile,
			// The encoder is allowed to fall back to software rather than fail outright,
			// e.g. for a rung whose dimensions the media engine will not take.
			fmt.Sprintf("-allow_sw:v:%d", i), "1",
			fmt.Sprintf("-force_key_frames:v:%d", i),
			fmt.Sprintf("expr:gte(t,n_forced*%d)", recipe.SegmentSeconds),
		)
	}

	if encoder == encoderNVENC {
		// Unlike VideoToolbox, NVENC's VBR honours a ceiling properly, so the ladder's
		// maxrate and bufsize are passed through and a rung stays inside the bandwidth its
		// master playlist advertises.
		//
		// Baseline becomes main: NVENC does not encode baseline, and main is what every
		// device made this decade decodes anyway.
		profile := rendition.Profile
		if profile == "baseline" {
			profile = "main"
		}

		args = append(args,
			fmt.Sprintf("-c:v:%d", i), "h264_nvenc",
			fmt.Sprintf("-b:v:%d", i), rendition.VideoBitrate,
			fmt.Sprintf("-maxrate:v:%d", i), rendition.Maxrate,
			fmt.Sprintf("-bufsize:v:%d", i), rendition.Bufsize,
			fmt.Sprintf("-profile:v:%d", i), profile,
		)

		for _, tuning := range encoderTuning(encoderNVENC) {
			args = append(args, tuning)
		}

		return append(args,
			fmt.Sprintf("-force_key_frames:v:%d", i),
			fmt.Sprintf("expr:gte(t,n_forced*%d)", recipe.SegmentSeconds),
		)
	}

	preset := rendition.Preset
	if presetOverride != "" {
		preset = presetOverride
	}

	return append(args,
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
}

const (
	encoderX264         = "x264"
	encoderVideoToolbox = "videotoolbox"
	encoderNVENC        = "nvenc"
)

// encoderAvailable is swapped out in tests: whether a machine can encode with NVIDIA
// hardware is not something a test on any other machine can answer.
var encoderAvailable = usable

// resolveEncoder answers what to encode with, and says so out loud: which encoder ran is
// the first thing anyone asks when comparing two imports.
//
// auto follows the hardware: Apple's media engine on a Mac, NVIDIA's on a machine with a
// usable GeForce or Quadro, software everywhere else. Both hardware paths are within a
// point of VMAF of x264 at these bitrates and many times faster, so the default is speed.
func resolveEncoder(choice string) (string, error) {
	switch choice {
	case "", "auto":
		if runtime.GOOS == "darwin" && encoderAvailable(encoderVideoToolbox) {
			return encoderVideoToolbox, nil
		}
		if encoderAvailable(encoderNVENC) {
			return encoderNVENC, nil
		}
		return encoderX264, nil

	case encoderX264, "libx264", "software":
		return encoderX264, nil

	case encoderVideoToolbox, "apple":
		if !encoderAvailable(encoderVideoToolbox) {
			return "", errors.New("this machine cannot encode with h264_videotoolbox")
		}
		return encoderVideoToolbox, nil

	case encoderNVENC, "nvidia", "cuda":
		if !encoderAvailable(encoderNVENC) {
			return "", errors.New("this machine cannot encode with h264_nvenc: check the NVIDIA driver, and that ffmpeg was built with nvenc")
		}
		return encoderNVENC, nil

	case "hw", "hardware":
		if runtime.GOOS == "darwin" && encoderAvailable(encoderVideoToolbox) {
			return encoderVideoToolbox, nil
		}
		if encoderAvailable(encoderNVENC) {
			return encoderNVENC, nil
		}
		return "", errors.New("no hardware encoder is usable on this machine")

	default:
		return "", fmt.Errorf("unknown encoder %q: use auto, x264, videotoolbox or nvenc", choice)
	}
}

// ffmpegEncoder is the encoder name each choice actually passes to ffmpeg.
func ffmpegEncoder(encoder string) string {
	switch encoder {
	case encoderVideoToolbox:
		return "h264_videotoolbox"
	case encoderNVENC:
		return "h264_nvenc"
	default:
		return "libx264"
	}
}

// usable answers whether this machine can really encode with something, which is not the
// same question as whether ffmpeg was built with it.
//
// A Windows ffmpeg almost always lists h264_nvenc whether or not there is an NVIDIA card
// in the machine or a driver new enough to drive it, and the failure without this check
// lands after the ladder has been set up, several thousand frames into an import. Two
// tenths of a second of nullsrc up front is a much better way to find out.
func usable(encoder string) bool {
	name := ffmpegEncoder(encoder)

	listed, err := exec.Command("ffmpeg", "-hide_banner", "-encoders").Output()
	if err != nil || !strings.Contains(string(listed), name) {
		return false
	}

	args := []string{"-hide_banner", "-loglevel", "error", "-f", "lavfi", "-i", "nullsrc=s=256x256:d=0.1", "-c:v", name}
	args = append(args, encoderTuning(encoder)...)
	args = append(args, "-f", "null", "-")

	return exec.Command("ffmpeg", args...).Run() == nil
}

// encoderTuning is the quality/speed knob each hardware encoder takes, kept in one place
// because the capability probe has to use the same flags the real encode will: a preset an
// older NVENC build does not know is exactly the kind of failure the probe exists to catch.
func encoderTuning(encoder string) []string {
	if encoder == encoderNVENC {
		// p4 is NVENC's balanced preset in the modern naming (p1 fastest, p7 slowest), and
		// vbr is what actually honours a bitrate target rather than a quality one.
		return []string{"-preset", "p4", "-rc", "vbr"}
	}
	return nil
}

func encode(input, dir string, imp Import, recipe Recipe, ladder []Rendition, probe *Probe, encoder string, presetOverride string, onProgress func(done, speed float64)) error {
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

		maps = append(maps, rungArgs(i, rendition, recipe, encoder, presetOverride)...)

		audio = append(audio,
			"-map", "0:a:0",
			fmt.Sprintf("-c:a:%d", i), "aac",
			fmt.Sprintf("-b:a:%d", i), rendition.AudioBitrate,
			"-ac", "2", "-ar", "48000",
		)

		streamMap = append(streamMap, fmt.Sprintf("v:%d,a:%d,name:%s", i, i, rendition.Name))
	}

	filterComplex := fmt.Sprintf("[0:v]split=%d%s; %s", len(ladder), splits.String(), strings.Join(filters, "; "))

	args := []string{"-hide_banner", "-y"}

	// Decoding is its own cost: a 10-bit HEVC master at 50p is heavy in software, and the
	// same media engine that encodes can decode. Frames come back to system memory for the
	// scale filters either way, so this is a straight saving.
	switch encoder {
	case encoderVideoToolbox:
		args = append(args, "-hwaccel", "videotoolbox")
	case encoderNVENC:
		// Frames come back to system memory for the scale filters, which is why this is
		// plain -hwaccel rather than a CUDA filter chain: the decode is the expensive part
		// on a 10-bit HEVC master, and scaling three rungs on the CPU is not.
		args = append(args, "-hwaccel", "cuda")
	}

	args = append(args, "-i", input, "-filter_complex", filterComplex)
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

// reuse looks for a finished encode in dir and adopts it for this import.
//
// An encode is the expensive half of an import - half an hour for a feature-length master -
// and everything after it can fail for reasons that have nothing to do with the media: an
// expired key, a bucket that stopped answering, a laptop lid. Re-encoding to recover from
// that is pure waste, so a work directory that already holds a complete ladder is reused.
//
// Segments carry the session id of the import they were encoded for, and this is a new
// import with a new one, so the files are renamed. That is a few thousand renames, which
// costs milliseconds and keeps the naming rule in one place.
func reuse(dir string, imp Import, ladder []Rendition) ([]float64, bool, error) {
	reference, err := durations(dir, imp.Prefix, ladder[0].Name)
	if err != nil || len(reference) == 0 {
		return nil, false, nil
	}

	for _, rendition := range ladder[1:] {
		other, err := durations(dir, imp.Prefix, rendition.Name)
		if err != nil || len(other) != len(reference) {
			return nil, false, nil
		}
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, false, err
	}

	// The session is whatever the segments already say, taken from any one of them.
	session := ""
	for _, entry := range entries {
		name := entry.Name()
		if !strings.HasSuffix(name, ".ts") {
			continue
		}
		parts := strings.Split(strings.TrimSuffix(name, ".ts"), "_")
		if len(parts) >= 4 {
			session = parts[len(parts)-2]
			break
		}
	}

	if session == "" {
		return nil, false, nil
	}

	if session != imp.Session {
		for _, entry := range entries {
			name := entry.Name()
			if !strings.HasSuffix(name, ".ts") || !strings.Contains(name, "_"+session+"_") {
				continue
			}
			renamed := strings.Replace(name, "_"+session+"_", "_"+imp.Session+"_", 1)
			if err := os.Rename(filepath.Join(dir, name), filepath.Join(dir, renamed)); err != nil {
				return nil, false, fmt.Errorf("could not adopt %s: %w", name, err)
			}
		}
	}

	// Every segment the playlists promise has to be on disk, or the upload would carry a
	// gap into the archive.
	for n := range reference {
		for _, rendition := range ladder {
			path := filepath.Join(dir, fmt.Sprintf("%s_%s_%s_%06d.ts", imp.Prefix, rendition.Name, imp.Session, n))
			if _, err := os.Stat(path); err != nil {
				return nil, false, nil
			}
		}
	}

	return reference, true, nil
}
