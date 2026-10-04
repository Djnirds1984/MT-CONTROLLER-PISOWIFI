package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// RouterClient talks RouterOS v7 REST (HTTP Basic + JSON).
// All JSON values come back as strings; object ids look like "*12".
type RouterClient struct {
	base string
	user string
	pass string
	hc   *http.Client
}

// routerFromSettings builds a client from stored settings, or nil when
// no router is configured (every caller must treat nil as "feature off").
func routerFromSettings(s *Store) *RouterClient {
	base := s.getSetting("router_url", "")
	if base == "" {
		return nil
	}
	return &RouterClient{
		base: strings.TrimRight(base, "/"),
		user: s.getSetting("router_user", ""),
		pass: s.getSetting("router_pass", ""),
		hc:   &http.Client{Timeout: 15 * time.Second},
	}
}

func (c *RouterClient) do(method, path string, body []byte, contentType string) (int, []byte, error) {
	req, err := http.NewRequest(method, c.base+path, bytes.NewReader(body))
	if err != nil {
		return 0, nil, err
	}
	req.SetBasicAuth(c.user, c.pass)
	if contentType == "" {
		contentType = "application/json"
	}
	req.Header.Set("Content-Type", contentType)
	req.Header.Set("Accept", "application/json")
	// RouterOS REST never answers an interim 100; make sure no proxy or
	// client layer injects "Expect: 100-continue" (known request stall).
	req.Header.Set("Expect", "")
	resp, err := c.hc.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	return resp.StatusCode, data, err
}

// json performs a JSON request/response round trip.
func (c *RouterClient) json(method, path string, in, out any) error {
	var body []byte
	if in != nil {
		var err error
		body, err = json.Marshal(in)
		if err != nil {
			return err
		}
	}
	code, data, err := c.do(method, path, body, "application/json")
	if err != nil {
		return err
	}
	if code >= 300 {
		return fmt.Errorf("%s %s: HTTP %d: %s", method, path, code, snippet(data))
	}
	if out != nil && len(data) > 0 {
		if err := json.Unmarshal(data, out); err != nil {
			return fmt.Errorf("%s %s: bad JSON: %w", method, path, err)
		}
	}
	return nil
}

func (c *RouterClient) listActive() ([]map[string]any, error) {
	var arr []map[string]any
	if err := c.json(http.MethodGet, "/rest/ip/hotspot/active", nil, &arr); err != nil {
		return nil, err
	}
	return arr, nil
}

func (c *RouterClient) kick(id string) error {
	// .id is appended raw: RouterOS REST paths keep the literal "*N"
	// and percent-encoding it has not proven reliable on live routers.
	return c.json(http.MethodDelete, "/rest/ip/hotspot/active/"+id, nil, nil)
}

// routerFileInfo is the subset of /rest/file we care about.
type routerFileInfo struct {
	ID   string
	Name string
	Size int64
}

func (c *RouterClient) listFiles() ([]routerFileInfo, error) {
	var arr []map[string]any
	if err := c.json(http.MethodGet, "/rest/file", nil, &arr); err != nil {
		return nil, err
	}
	out := make([]routerFileInfo, 0, len(arr))
	for _, item := range arr {
		f := routerFileInfo{
			ID:   fmt.Sprint(item[".id"]),
			Name: fmt.Sprint(item["name"]),
		}
		if n, ok := item["size"].(string); ok {
			f.Size = parseIntSafe(n)
		}
		out = append(out, f)
	}
	return out, nil
}

// uploadStub writes one stub into the router hotspot html-directory.
// Live-router lesson (RouterOS 7.24): PUT is create-only, and PATCH on
// an existing file can come back "file already exists", so the only
// overwrite that proved reliable is resolve-.id -> DELETE -> PUT fresh.
func (c *RouterClient) uploadStub(remoteName string, content []byte) error {
	files, err := c.listFiles()
	if err != nil {
		return err
	}
	for i := range files {
		if files[i].Name == remoteName {
			code, data, err := c.do(http.MethodDelete,
				"/rest/file/"+files[i].ID, nil, "")
			if err != nil {
				return err
			}
			if code >= 300 {
				return fmt.Errorf("DELETE %s: HTTP %d: %s", remoteName, code, snippet(data))
			}
			break
		}
	}
	code, data, err := c.do(http.MethodPut,
		"/rest/file?name="+url.QueryEscape(remoteName), content, "application/octet-stream")
	if err != nil {
		return err
	}
	if code >= 300 {
		return fmt.Errorf("PUT %s: HTTP %d: %s", remoteName, code, snippet(data))
	}
	return nil
}

// remoteStubMap returns remoteName -> size for the hotspot directory.
func (c *RouterClient) remoteStubMap(dir string) (map[string]int64, error) {
	files, err := c.listFiles()
	if err != nil {
		return nil, err
	}
	out := map[string]int64{}
	prefix := strings.TrimSuffix(dir, "/") + "/"
	for _, f := range files {
		if strings.HasPrefix(f.Name, prefix) {
			out[strings.TrimPrefix(f.Name, prefix)] = f.Size
		}
	}
	return out, nil
}

func snippet(b []byte) string {
	s := strings.TrimSpace(string(b))
	if len(s) > 200 {
		s = s[:200] + "..."
	}
	return s
}

func parseIntSafe(s string) int64 {
	var n int64
	neg := false
	for i := 0; i < len(s); i++ {
		ch := s[i]
		if ch >= '0' && ch <= '9' {
			n = n*10 + int64(ch-'0')
			continue
		}
		if i == 0 && ch == '-' {
			neg = true
			continue
		}
		break
	}
	if neg {
		return -n
	}
	return n
}
