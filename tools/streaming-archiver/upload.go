package main

import (
	"crypto/tls"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"
)

// uploadBatch is how many presigned URLs are asked for at once. The API caps a request at
// 1000; staying under it keeps one slow batch from holding up the whole queue, and a
// signature only has to outlive the batch that uses it.
const uploadBatch = 250

// uploadClient forces HTTP/1.1.
//
// Not a preference: Go's HTTP/2 transport cannot retry a request whose body was already
// written, and the archive endpoint sends a graceful-shutdown GOAWAY under sustained
// parallel PUTs. Over h2 that surfaces as "cannot retry err ... after Request.Body was
// written" and the object is simply lost; over HTTP/1.1 the retry below just works.
func uploadClient() *http.Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.ForceAttemptHTTP2 = false
	transport.TLSNextProto = map[string]func(string, *tls.Conn) http.RoundTripper{}
	transport.MaxIdleConnsPerHost = 32

	return &http.Client{Transport: transport, Timeout: 10 * time.Minute}
}

type uploadJob struct {
	Path   string
	Signed SignedURL
}

// upload pushes every job, retrying each a few times with backoff. Concurrency is modest
// on purpose: the endpoint starts shedding connections well before it saturates a decent
// uplink, and a failed PUT costs more than a slightly slower one.
func upload(jobs []uploadJob, parallel int, progress func(done, total int)) error {
	client := uploadClient()

	var (
		wg       sync.WaitGroup
		queue    = make(chan uploadJob)
		done     atomic.Int64
		failures = make(chan error, len(jobs))
	)

	for i := 0; i < parallel; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for job := range queue {
				if err := putWithRetry(client, job); err != nil {
					failures <- err
					continue
				}
				if progress != nil {
					progress(int(done.Add(1)), len(jobs))
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

func putWithRetry(client *http.Client, job uploadJob) error {
	var last error

	for attempt := 1; attempt <= 5; attempt++ {
		if attempt > 1 {
			time.Sleep(time.Duration(attempt*attempt) * 250 * time.Millisecond)
		}

		// Reopened per attempt: a retry needs the body from the start, and a file handle
		// consumed by a failed PUT is at EOF.
		file, err := os.Open(job.Path)
		if err != nil {
			return err
		}

		info, err := file.Stat()
		if err != nil {
			file.Close()
			return err
		}

		req, err := http.NewRequest(http.MethodPut, job.Signed.URL, file)
		if err != nil {
			file.Close()
			return err
		}
		req.ContentLength = info.Size()
		for name, value := range job.Signed.Headers {
			req.Header.Set(name, value)
		}

		resp, err := client.Do(req)
		file.Close()

		if err != nil {
			last = err
			continue
		}

		body, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<10))
		resp.Body.Close()

		if resp.StatusCode < 300 {
			return nil
		}

		last = fmt.Errorf("PUT %s: HTTP %d: %s", filepath.Base(job.Path), resp.StatusCode, string(body))
	}

	return fmt.Errorf("giving up on %s: %w", filepath.Base(job.Path), last)
}
