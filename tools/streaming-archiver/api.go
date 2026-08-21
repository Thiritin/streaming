package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strings"
	"time"
)

// Rendition is one rung of the ladder, exactly as the server describes it. Nothing here
// is a constant in this binary: a client that shipped months ago encodes to whatever the
// server is serving today, so the archive cannot end up holding two different ladders.
type Rendition struct {
	Name           string
	Bandwidth      int    `json:"bandwidth"`
	Width          int    `json:"width"`
	Height         int    `json:"height"`
	VideoBitrate   string `json:"video_bitrate"`
	Maxrate        string `json:"maxrate"`
	Bufsize        string `json:"bufsize"`
	Profile        string `json:"profile"`
	Preset         string `json:"preset"`
	AudioBitrate   string `json:"audio_bitrate"`
	HalveFrameRate bool   `json:"halve_frame_rate"`
}

type Recipe struct {
	SegmentSeconds int                  `json:"segment_seconds"`
	SDFPSCeiling   int                  `json:"sd_fps_ceiling"`
	Renditions     map[string]Rendition `json:"renditions"`
}

// Ladder returns the rungs in ascending quality. JSON objects have no order, so the order
// is derived from bandwidth rather than trusted from the wire; ffmpeg's var_stream_map
// and the master playlist both depend on it.
func (r Recipe) Ladder() []Rendition {
	out := make([]Rendition, 0, len(r.Renditions))
	for name, rendition := range r.Renditions {
		rendition.Name = name
		out = append(out, rendition)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Bandwidth < out[j].Bandwidth })
	return out
}

type Import struct {
	ID       string `json:"id"`
	Prefix   string `json:"prefix"`
	Session  string `json:"session"`
	StartsAt string `json:"starts_at"`
	Title    string `json:"title"`
}

type StartResponse struct {
	Import      Import `json:"import"`
	Recipe      Recipe `json:"recipe"`
	SegmentName string `json:"segment_name"`
	ExpiresAt   string `json:"expires_at"`
}

type SignedURL struct {
	Key     string    `json:"key"`
	URL     string    `json:"url"`
	Headers headerMap `json:"headers"`
}

// headerMap accepts either shape the API can answer with.
//
// A presigned upload's headers come out of the AWS SDK as PSR-7 style lists -
// {"Host": ["bucket.example"]} - and older builds of the site pass them through
// untouched, while newer ones flatten to {"Host": "bucket.example"}. A client that
// insisted on one of those failed the whole import after the encode was already done,
// which is an expensive way to disagree about JSON.
type headerMap map[string]string

func (h *headerMap) UnmarshalJSON(data []byte) error {
	var raw map[string]any
	if err := json.Unmarshal(data, &raw); err != nil {
		return err
	}

	out := make(headerMap, len(raw))
	for name, value := range raw {
		switch typed := value.(type) {
		case string:
			out[name] = typed
		case []any:
			parts := make([]string, 0, len(typed))
			for _, item := range typed {
				parts = append(parts, fmt.Sprint(item))
			}
			out[name] = strings.Join(parts, ", ")
		case nil:
			// Nothing to send.
		default:
			out[name] = fmt.Sprint(typed)
		}
	}

	*h = out
	return nil
}

type CommitResult struct {
	RecordingID  int    `json:"recording_id"`
	Slug         string `json:"slug"`
	Duration     int    `json:"duration"`
	SegmentCount int    `json:"segment_count"`
	Status       string `json:"status"`
	ManageURL    string `json:"manage_url"`
	WatchURL     string `json:"watch_url"`
}

type Client struct {
	BaseURL string
	Key     string
	HTTP    *http.Client
}

func NewClient(baseURL, key string) *Client {
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"),
		Key:     key,
		HTTP:    &http.Client{Timeout: 5 * time.Minute},
	}
}

func (c *Client) post(path string, body any, out any) error {
	payload, err := json.Marshal(body)
	if err != nil {
		return err
	}

	req, err := http.NewRequest(http.MethodPost, c.BaseURL+path, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Import-Key", c.Key)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	answer, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if err != nil {
		return err
	}

	if resp.StatusCode >= 400 {
		// The API answers errors in its own vocabulary ("3 segment(s) of hour ... are not
		// in the bucket"), which is more useful to a person than the status code.
		var wrapped struct {
			Error   string              `json:"error"`
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		if json.Unmarshal(answer, &wrapped) == nil {
			if wrapped.Error != "" {
				return fmt.Errorf("%s (HTTP %d)", wrapped.Error, resp.StatusCode)
			}
			if wrapped.Message != "" {
				detail := ""
				for field, messages := range wrapped.Errors {
					detail += fmt.Sprintf("\n  %s: %s", field, strings.Join(messages, "; "))
				}
				return fmt.Errorf("%s (HTTP %d)%s", wrapped.Message, resp.StatusCode, detail)
			}
		}
		return fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(answer)))
	}

	if out == nil {
		return nil
	}

	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(answer, &envelope); err != nil {
		return fmt.Errorf("unreadable response: %w", err)
	}
	return json.Unmarshal(envelope.Data, out)
}

func (c *Client) Start(meta map[string]any) (*StartResponse, error) {
	var out StartResponse
	if err := c.post("/api/recording/imports", meta, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

type urlRequest struct {
	Rendition string `json:"rendition"`
	Number    int    `json:"number"`
	Hour      string `json:"hour"`
}

func (c *Client) SignURLs(importID string, wanted []urlRequest) ([]SignedURL, error) {
	var out []SignedURL
	err := c.post("/api/recording/imports/"+importID+"/urls", map[string]any{"segments": wanted}, &out)
	return out, err
}

type commitSegment struct {
	Number   int     `json:"number"`
	Duration float64 `json:"duration"`
}

func (c *Client) Commit(importID string, segments []commitSegment, renditions []string) (*CommitResult, error) {
	var out CommitResult
	err := c.post("/api/recording/imports/"+importID+"/commit", map[string]any{
		"segments":   segments,
		"renditions": renditions,
	}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}
