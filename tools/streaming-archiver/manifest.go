package main

import (
	"bufio"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// manifest is the list of objects this work directory has already put in the bucket.
//
// An import is thousands of small uploads, and the reasons one run stops - a dropped
// network, a closed lid, a signature that expired while the machine was asleep - have
// nothing to do with the objects that already landed. Recording each key as it lands means
// a rerun against the same --work directory continues instead of starting over.
//
// Append-only, one key per line, written as each object succeeds rather than batched, so a
// process that dies without warning still leaves an accurate list.
type manifest struct {
	mu   sync.Mutex
	file *os.File
	seen map[string]bool
}

const manifestName = "uploaded.keys"

func openManifest(dir string) (*manifest, error) {
	path := filepath.Join(dir, manifestName)

	seen := map[string]bool{}
	if existing, err := os.Open(path); err == nil {
		scanner := bufio.NewScanner(existing)
		for scanner.Scan() {
			if key := strings.TrimSpace(scanner.Text()); key != "" {
				seen[key] = true
			}
		}
		existing.Close()
	}

	file, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return nil, err
	}

	return &manifest{file: file, seen: seen}, nil
}

func (m *manifest) has(key string) bool {
	if m == nil {
		return false
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	return m.seen[key]
}

func (m *manifest) add(key string) error {
	if m == nil {
		return nil
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	if m.seen[key] {
		return nil
	}

	if _, err := m.file.WriteString(key + "\n"); err != nil {
		return err
	}

	m.seen[key] = true
	return nil
}

func (m *manifest) count() int {
	if m == nil {
		return 0
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	return len(m.seen)
}

func (m *manifest) close() {
	if m == nil || m.file == nil {
		return
	}
	m.file.Close()
}
