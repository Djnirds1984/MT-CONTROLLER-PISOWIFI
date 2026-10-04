package main

import (
	"embed"
	"io/fs"
	"sort"
	"strings"
)

// Router-side redirector stubs. These are the ONLY files allowed in the
// MikroTik hotspot html-directory (thin, <1KB each); the full portal is
// served by lighttpd on the SBC. They are embedded here so the admin
// Tools page can verify and re-upload them over RouterOS REST without
// touching the filesystem.
//
//go:embed stubs
var stubFS embed.FS

// stubList returns stub filename -> content for every embedded stub.
func stubList() (map[string][]byte, error) {
	out := map[string][]byte{}
	entries, err := fs.ReadDir(stubFS, "stubs")
	if err != nil {
		return nil, err
	}
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".html") {
			continue
		}
		data, err := stubFS.ReadFile("stubs/" + e.Name())
		if err != nil {
			return nil, err
		}
		out[e.Name()] = data
	}
	return out, nil
}

// stubNames returns sorted stub filenames (stable for UI listing).
func stubNames() ([]string, error) {
	m, err := stubList()
	if err != nil {
		return nil, err
	}
	names := make([]string, 0, len(m))
	for n := range m {
		names = append(names, n)
	}
	sort.Strings(names)
	return names, nil
}
