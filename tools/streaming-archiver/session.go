package main

import (
	"encoding/json"
	"os"
	"path/filepath"
)

// The import a work directory belongs to, kept beside its segments.
//
// Object keys are built from the import's session id, so a rerun that opened a *new*
// import would upload the same media again under different names and leave the first
// attempt's objects in the bucket as garbage nobody indexes. Remembering which import this
// directory is for keeps a resumed run pointed at the same keys, which is also what makes
// the uploaded-key manifest meaningful.
//
// The site holds an import for 48 hours; past that a resume has to start a new one, and
// the segments are adopted into it by renaming.
const importStateName = "import.json"

type importState struct {
	Import Import `json:"import"`
	Master string `json:"master"`
}

func loadImportState(dir, master string) (*importState, bool) {
	body, err := os.ReadFile(filepath.Join(dir, importStateName))
	if err != nil {
		return nil, false
	}

	var state importState
	if err := json.Unmarshal(body, &state); err != nil {
		return nil, false
	}

	// A work directory holds one master's encode. Pointing a second file at it would
	// upload one master's segments under another's import.
	if state.Master != filepath.Base(master) || state.Import.ID == "" {
		return nil, false
	}

	return &state, true
}

func saveImportState(dir, master string, imp Import) error {
	body, err := json.MarshalIndent(importState{Import: imp, Master: filepath.Base(master)}, "", "  ")
	if err != nil {
		return err
	}

	return os.WriteFile(filepath.Join(dir, importStateName), body, 0o644)
}
