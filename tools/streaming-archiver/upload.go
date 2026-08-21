package main

import (
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// uploadBatch is how many presigned URLs are asked for at once. The API caps a request at
// 1000; staying under it keeps one slow batch from holding up the whole queue, and a
// signature only has to outlive the batch that uses it.
const uploadBatch = 250

// How long one object may keep failing before the import gives up on it. Generous on
// purpose: a laptop that loses wifi, a phone tether that drops, an S3 front end having a
// bad minute are all things that come back, and the alternative is throwing away an
// upload that is most of the way done.
const retryBudget = 20 * time.Minute

// uploadClient keeps HTTP/2.
//
// The earlier version of this forced HTTP/1.1, on the theory that Go's h2 transport cannot
// retry a request whose body was already written. That was measured backwards: against the
// archive endpoint every HTTP/1.1 PUT dies with "use of closed network connection" while
// the same request over h2 answers 200. The retry problem is real but is fixed by giving
// the request a GetBody, which is what putOnce does.
func uploadClient() *http.Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.MaxIdleConnsPerHost = 32
	transport.IdleConnTimeout = 60 * time.Second

	return &http.Client{Transport: transport, Timeout: 15 * time.Minute}
}

type uploadJob struct {
	Path   string
	Size   int64
	Signed SignedURL
}

// uploadReport is how the caller is told what is happening, so a stalled network shows up
// as retries on screen rather than as a bar that stopped moving for no stated reason.
type uploadReport struct {
	Done  func(objects int, bytes int64)
	Retry func(name string, attempt int, wait time.Duration, err error)
}

// upload pushes every job, retrying each until it lands or the budget runs out.
//
// Concurrency is modest on purpose: the endpoint sheds connections well before it
// saturates a decent uplink, and a failed PUT costs more than a slightly slower one.
func upload(jobs []uploadJob, parallel int, manifest *manifest, report uploadReport) error {
	client := uploadClient()

	var (
		wg       sync.WaitGroup
		queue    = make(chan uploadJob)
		objects  atomic.Int64
		bytes    atomic.Int64
		failures = make(chan error, len(jobs))
	)

	for i := 0; i < parallel; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for job := range queue {
				if err := putWithRetry(client, job, report.Retry); err != nil {
					failures <- err
					continue
				}

				// Recorded before the counter moves: a key the manifest missed is
				// re-uploaded on a resume, which is wasteful but correct, while a key it
				// claims falsely would leave a hole in the archive.
				if manifest != nil {
					if err := manifest.add(job.Signed.Key); err != nil {
						failures <- err
						continue
					}
				}

				if report.Done != nil {
					report.Done(int(objects.Add(1)), bytes.Add(job.Size))
				}
			}
		}()
	}

	for _, job := range jobs {
		queue <- job
	}
	close(queue)
	wg.Wait()
	close(failures)

	for err := range failures {
		return err
	}
	return nil
}

func putWithRetry(client *http.Client, job uploadJob, onRetry func(string, int, time.Duration, error)) error {
	deadline := time.Now().Add(retryBudget)
	name := filepath.Base(job.Path)

	for attempt := 1; ; attempt++ {
		err := putOnce(client, job)
		if err == nil {
			return nil
		}

		if !worthRetrying(err) {
			return fmt.Errorf("%s: %w", name, err)
		}

		if time.Now().After(deadline) {
			return fmt.Errorf("%s: gave up after %s of retries: %w", name, retryBudget, err)
		}

		wait := backoff(attempt)
		if onRetry != nil {
			onRetry(name, attempt, wait, err)
		}
		time.Sleep(wait)
	}
}

// backoff grows to half a minute and stays there: a network that is down is down, and
// hammering it does not help, but the import should notice within seconds of it returning.
func backoff(attempt int) time.Duration {
	wait := time.Duration(1<<min(attempt-1, 5)) * time.Second
	if wait > 30*time.Second {
		wait = 30 * time.Second
	}
	return wait
}

func putOnce(client *http.Client, job uploadJob) error {
	file, err := os.Open(job.Path)
	if err != nil {
		return err
	}
	defer file.Close()

	req, err := http.NewRequest(http.MethodPut, job.Signed.URL, file)
	if err != nil {
		return err
	}
	req.ContentLength = job.Size

	// What makes the request replayable: without it, a connection that dies after the body
	// started is a lost object rather than a retried one, at the transport layer and in
	// any redirect.
	req.GetBody = func() (io.ReadCloser, error) { return os.Open(job.Path) }

	for header, value := range job.Signed.Headers {
		// Host is not settable through the header map: net/http derives it from the URL,
		// which is the same host the signature was made against anyway.
		if strings.EqualFold(header, "Host") {
			continue
		}
		req.Header.Set(header, value)
	}

	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<10))

	if resp.StatusCode < 300 {
		return nil
	}

	return &httpError{status: resp.StatusCode, body: strings.TrimSpace(string(body))}
}

type httpError struct {
	status int
	body   string
}

func (e *httpError) Error() string {
	if e.body == "" {
		return fmt.Sprintf("HTTP %d", e.status)
	}
	return fmt.Sprintf("HTTP %d: %s", e.status, e.body)
}

// worthRetrying separates "the network had a moment" from "this will never work".
//
// A refused signature or a malformed key fails the same way on the hundredth attempt as on
// the first, and spending twenty minutes discovering that helps nobody. Anything that
// looks like a connection problem, a timeout, or the far end having a bad minute is worth
// waiting out.
func worthRetrying(err error) bool {
	var status *httpError
	if errors.As(err, &status) {
		return status.status == http.StatusRequestTimeout ||
			status.status == http.StatusTooManyRequests ||
			status.status >= 500
	}

	if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
		return true
	}

	var netErr net.Error
	if errors.As(err, &netErr) {
		return true
	}

	// Not every transport failure implements net.Error: a connection closed underneath a
	// write surfaces as a plain error with this text.
	text := err.Error()
	for _, fragment := range []string{
		"use of closed network connection",
		"connection reset",
		"broken pipe",
		"unexpected EOF",
		"server closed",
		"GOAWAY",
		"no such host",
		"network is unreachable",
		"i/o timeout",
	} {
		if strings.Contains(text, fragment) {
			return true
		}
	}

	return false
}
