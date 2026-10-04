package main

import (
	"crypto/md5"
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// NativeClient speaks the RouterOS legacy binary API (default TCP 8728).
//
// The protocol is a stream of "sentences". A sentence is a sequence of
// length-prefixed "words" terminated by a zero-length word. Replies come
// back as one or more sentences whose first word is the reply type:
//
//	!re    — a data row (attribute words follow, each "=key=value")
//	!done  — end of the reply for a command
//	!trap  — recoverable error ("=message=...")
//	!fatal — connection-fatal error
//
// NativeClient satisfies the same RouterAPI interface as RouterClient, so
// the rest of the panel is agnostic of the transport in use.
type NativeClient struct {
	host string
	port int
	user string
	pass string
	conn net.Conn
}

// connect dials the router and authenticates. It is called lazily by cmd
// so that constructing a NativeClient never blocks on the network.
func (c *NativeClient) connect() error {
	addr := net.JoinHostPort(c.host, strconv.Itoa(c.port))
	conn, err := net.DialTimeout("tcp", addr, 10*time.Second)
	if err != nil {
		return err
	}
	c.conn = conn
	c.setDeadline()
	return c.login()
}

// setDeadline (re)arms a 15s idle deadline. Refreshed before every
// command so a long-lived client does not time out mid-session.
func (c *NativeClient) setDeadline() {
	if c.conn != nil {
		_ = c.conn.SetDeadline(time.Now().Add(15 * time.Second))
	}
}

// login authenticates. RouterOS >= 6.43 accepts a plaintext /login and
// answers "!done" straight away. Older releases reply "!re" carrying a
// random challenge (=ret=), which we hash with the password using
// MD5(0x00 || password || challenge) and resend.
func (c *NativeClient) login() error {
	if err := c.writeSentence([]string{"/login", "=name=" + c.user, "=password=" + c.pass}); err != nil {
		return err
	}
	words, err := c.readSentence()
	if err != nil {
		return err
	}
	if len(words) == 0 {
		return fmt.Errorf("login: router sent an empty reply")
	}
	switch words[0] {
	case "!done":
		return nil // plaintext login accepted
	case "!trap", "!fatal":
		return fmt.Errorf("login: %s", sentenceMessage(words))
	case "!re":
		var chalHex string
		for _, w := range words {
			if v, ok := attrWord(w, "ret"); ok {
				chalHex = v
			}
		}
		challenge, derr := hex.DecodeString(chalHex)
		if derr != nil {
			return fmt.Errorf("login: bad challenge %q: %w", chalHex, derr)
		}
		h := md5.New()
		h.Write([]byte{0x00})
		h.Write([]byte(c.pass))
		h.Write(challenge)
		response := hex.EncodeToString(h.Sum(nil))
		if err := c.writeSentence([]string{"/login", "=name=" + c.user, "=password=" + response}); err != nil {
			return err
		}
		// Consume sentences until the terminating !done (the first /login
		// may still owe us its own !done before the response resolves).
		for {
			reply, rerr := c.readSentence()
			if rerr != nil {
				return rerr
			}
			if len(reply) == 0 {
				return fmt.Errorf("login: router sent an empty reply")
			}
			switch reply[0] {
			case "!done":
				return nil
			case "!trap", "!fatal":
				return fmt.Errorf("login: %s", sentenceMessage(reply))
			}
		}
	default:
		return fmt.Errorf("login: unexpected reply %q", words[0])
	}
}

// cmd sends one command sentence and collects the "!re" data rows until
// the terminating "!done". It connects lazily on first use.
func (c *NativeClient) cmd(words ...string) ([]map[string]string, error) {
	if c.conn == nil {
		if err := c.connect(); err != nil {
			return nil, err
		}
	}
	c.setDeadline()
	if err := c.writeSentence(words); err != nil {
		return nil, err
	}
	out := []map[string]string{}
	for {
		reply, err := c.readSentence()
		if err != nil {
			return nil, err
		}
		if len(reply) == 0 {
			return nil, fmt.Errorf("router sent an empty reply")
		}
		switch reply[0] {
		case "!re":
			row := map[string]string{}
			for _, w := range reply[1:] {
				if !strings.HasPrefix(w, "=") {
					continue
				}
				kv := w[1:] // drop the leading "="
				if i := strings.Index(kv, "="); i >= 0 {
					row[kv[:i]] = kv[i+1:]
				}
			}
			out = append(out, row)
		case "!done":
			return out, nil
		case "!trap":
			return nil, fmt.Errorf("router error: %s", sentenceMessage(reply))
		case "!fatal":
			return nil, fmt.Errorf("router fatal: %s", sentenceMessage(reply))
		}
	}
}

// Close releases the underlying TCP connection.
func (c *NativeClient) Close() error {
	if c.conn == nil {
		return nil
	}
	err := c.conn.Close()
	c.conn = nil
	return err
}

// ------------------------------------------------------- length codec
//
// RouterOS encodes a word length in a variable number of bytes; the top
// bits of the first byte say how many follow:
//
//	0xxxxxxx                        -> 1 byte  (< 0x80)
//	10xxxxxx xxxxxxxx               -> 2 bytes (< 0x4000)
//	110xxxxx ... (3 bytes total)    -> 3 bytes (< 0x200000)
//	1110xxxx ... (4 bytes total)    -> 4 bytes (< 0x10000000)
//	11110000 + 4 raw bytes          -> otherwise

func (c *NativeClient) writeLen(n int) error {
	var buf []byte
	switch {
	case n < 0x80:
		buf = []byte{byte(n)}
	case n < 0x4000:
		v := uint16(n) | 0x8000
		buf = []byte{byte(v >> 8), byte(v)}
	case n < 0x200000:
		v := uint32(n) | 0xC00000
		buf = []byte{byte(v >> 16), byte(v >> 8), byte(v)}
	case n < 0x10000000:
		v := uint32(n) | 0xE0000000
		buf = []byte{byte(v >> 24), byte(v >> 16), byte(v >> 8), byte(v)}
	default:
		buf = make([]byte, 5)
		buf[0] = 0xF0
		binary.BigEndian.PutUint32(buf[1:], uint32(n))
	}
	_, err := c.conn.Write(buf)
	return err
}

func (c *NativeClient) readLen() (int, error) {
	b0, err := c.readByte()
	if err != nil {
		return 0, err
	}
	switch {
	case b0 < 0x80:
		return int(b0), nil
	case b0 < 0xC0:
		b1, err := c.readByte()
		if err != nil {
			return 0, err
		}
		return int(b0&0x3F)<<8 | int(b1), nil
	case b0 < 0xE0:
		var buf [2]byte
		if _, err := io.ReadFull(c.conn, buf[:]); err != nil {
			return 0, err
		}
		return int(b0&0x1F)<<16 | int(buf[0])<<8 | int(buf[1]), nil
	case b0 < 0xF0:
		var buf [3]byte
		if _, err := io.ReadFull(c.conn, buf[:]); err != nil {
			return 0, err
		}
		return int(b0&0x0F)<<24 | int(buf[0])<<16 | int(buf[1])<<8 | int(buf[2]), nil
	default:
		var buf [4]byte
		if _, err := io.ReadFull(c.conn, buf[:]); err != nil {
			return 0, err
		}
		return int(binary.BigEndian.Uint32(buf[:])), nil
	}
}

func (c *NativeClient) readByte() (byte, error) {
	var b [1]byte
	if _, err := io.ReadFull(c.conn, b[:]); err != nil {
		return 0, err
	}
	return b[0], nil
}

func (c *NativeClient) writeWord(s string) error {
	if err := c.writeLen(len(s)); err != nil {
		return err
	}
	if len(s) == 0 {
		return nil
	}
	_, err := c.conn.Write([]byte(s))
	return err
}

func (c *NativeClient) readWord() (string, error) {
	n, err := c.readLen()
	if err != nil {
		return "", err
	}
	if n == 0 {
		return "", nil
	}
	buf := make([]byte, n)
	if _, err := io.ReadFull(c.conn, buf); err != nil {
		return "", err
	}
	return string(buf), nil
}

func (c *NativeClient) writeSentence(words []string) error {
	for _, w := range words {
		if err := c.writeWord(w); err != nil {
			return err
		}
	}
	return c.writeWord("") // zero-length word terminates the sentence
}

func (c *NativeClient) readSentence() ([]string, error) {
	words := []string{}
	for {
		w, err := c.readWord()
		if err != nil {
			return nil, err
		}
		if w == "" {
			return words, nil
		}
		words = append(words, w)
	}
}

// ----------------------------------------------------------- operations

func (c *NativeClient) listActive() ([]map[string]any, error) {
	rows, err := c.cmd("/ip/hotspot/active/print")
	if err != nil {
		return nil, err
	}
	out := make([]map[string]any, 0, len(rows))
	for _, row := range rows {
		m := make(map[string]any, len(row))
		for k, v := range row {
			m[k] = v
		}
		out = append(out, m)
	}
	return out, nil
}

func (c *NativeClient) kick(id string) error {
	_, err := c.cmd("/ip/hotspot/active/remove", "=.id="+id)
	return err
}

func (c *NativeClient) listFiles() ([]routerFileInfo, error) {
	rows, err := c.cmd("/file/print")
	if err != nil {
		return nil, err
	}
	out := make([]routerFileInfo, 0, len(rows))
	for _, row := range rows {
		f := routerFileInfo{
			ID:   row[".id"],
			Name: row["name"],
		}
		if sz, ok := row["size"]; ok {
			f.Size = parseIntSafe(sz)
		}
		out = append(out, f)
	}
	return out, nil
}

// uploadStub writes one stub into the router hotspot html-directory. The
// legacy binary API cannot transfer file contents, so we delegate to the
// REST endpoint on the same router (served on plain HTTP port 80) — the
// same workaround the old PHP LegacyApiClient used.
func (c *NativeClient) uploadStub(remoteName string, content []byte) error {
	rc := &RouterClient{
		base: "http://" + c.host,
		user: c.user,
		pass: c.pass,
		hc:   &http.Client{Timeout: 15 * time.Second},
	}
	return rc.uploadStub(remoteName, content)
}

// remoteStubMap returns remoteName -> size for the hotspot directory.
func (c *NativeClient) remoteStubMap(dir string) (map[string]int64, error) {
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

// ------------------------------------------------------------- helpers

// attrWord extracts the value of a "=name=value" reply word.
func attrWord(word, name string) (string, bool) {
	prefix := "=" + name + "="
	if strings.HasPrefix(word, prefix) {
		return strings.TrimPrefix(word, prefix), true
	}
	return "", false
}

// sentenceMessage pulls the human-readable "=message=" out of a !trap or
// !fatal sentence, falling back to the joined words.
func sentenceMessage(words []string) string {
	for _, w := range words {
		if v, ok := attrWord(w, "message"); ok && v != "" {
			return v
		}
	}
	return strings.Join(words, " ")
}
