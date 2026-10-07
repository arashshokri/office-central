package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

const version = "1.4.0-rc.1"
const contextPrefix = "office-agent/v2\n"

var b64 = base64.RawURLEncoding

type Hardware struct {
	UUID    string `json:"product_uuid"`
	Machine string `json:"machine_id"`
}
type Envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
	Algorithm string `json:"algorithm"`
}
type Image struct {
	Role    string `json:"role"`
	Archive string `json:"archive"`
	SHA     string `json:"sha256"`
	ID      string `json:"image_id"`
	Ref     string `json:"ref"`
}
type Manifest struct {
	Format       string  `json:"format"`
	Product      string  `json:"product"`
	Protection   string  `json:"source_protection"`
	Architecture string  `json:"architecture"`
	Version      string  `json:"version"`
	Images       []Image `json:"images"`
}
type Package struct {
	Release  string   `json:"release_id"`
	Version  string   `json:"version"`
	SHA      string   `json:"sha256"`
	Size     int64    `json:"size"`
	Manifest Manifest `json:"manifest"`
}
type Deployment struct {
	URL   string `json:"app_url"`
	Email string `json:"admin_email"`
	Name  string `json:"admin_name"`
	Bind  string `json:"bind_ip"`
	Port  int    `json:"port"`
	Proxy string `json:"proxy_network"`
}
type State struct {
	Kind         string     `json:"kind"`
	Protocol     int        `json:"protocol"`
	Installation string     `json:"installation_id"`
	Sequence     uint64     `json:"sequence"`
	Hardware     string     `json:"hardware_fingerprint"`
	Presented    string     `json:"presented_fingerprint"`
	Access       string     `json:"access"`
	Completed    bool       `json:"completed"`
	Message      string     `json:"message"`
	Deployment   Deployment `json:"deployment"`
	Package      Package    `json:"package"`
}
type Identity struct {
	Endpoint       string
	Trust          string
	Key            string
	RequestID      string
	Installation   string
	Credential     string
	Control        string
	Sequence       uint64
	ReactivationID string
}
type Client struct {
	Root     string
	Identity Identity
	HTTP     *http.Client
}

func randomHex(n int) string {
	raw := make([]byte, n)
	if _, err := rand.Read(raw); err != nil {
		panic(err)
	}
	return hex.EncodeToString(raw)
}
func requestUUID() string {
	s := randomHex(16)
	return s[:8] + "-" + s[8:12] + "-4" + s[13:16] + "-a" + s[17:20] + "-" + s[20:]
}
func digest(raw []byte) string { d := sha256.Sum256(raw); return hex.EncodeToString(d[:]) }
func hardware() (Hardware, error) {
	a, e := os.ReadFile("/sys/class/dmi/id/product_uuid")
	if e != nil {
		return Hardware{}, fmt.Errorf("host DMI UUID unavailable: %w", e)
	}
	b, e := os.ReadFile("/etc/machine-id")
	if e != nil {
		return Hardware{}, e
	}
	h := Hardware{strings.ToLower(strings.TrimSpace(string(a))), strings.ToLower(strings.TrimSpace(string(b)))}
	_, e = h.fingerprint()
	return h, e
}
func (h Hardware) fingerprint() (string, error) {
	uuid := regexp.MustCompile(`^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$`)
	machine := regexp.MustCompile(`^[a-f0-9]{32}$`)
	flat := strings.ReplaceAll(h.UUID, "-", "")
	if !uuid.MatchString(h.UUID) || !machine.MatchString(h.Machine) || flat == strings.Repeat("0", 32) || flat == strings.Repeat("f", 32) || h.Machine == strings.Repeat("0", 32) {
		return "", errors.New("valid host UUID and machine-id required")
	}
	return digest([]byte("office-hardware-v2\n" + h.UUID + "\n" + h.Machine)), nil
}
func atomicJSON(path string, value any, mode os.FileMode) error {
	raw, e := json.MarshalIndent(value, "", "  ")
	if e != nil {
		return e
	}
	return atomicWrite(path, raw, mode)
}
func atomicWrite(path string, raw []byte, mode os.FileMode) error {
	dir := filepath.Dir(path)
	if e := os.MkdirAll(dir, 0755); e != nil {
		return e
	}
	file, e := os.CreateTemp(dir, ".pending-")
	if e != nil {
		return e
	}
	defer os.Remove(file.Name())
	if e = file.Chmod(mode); e != nil {
		file.Close()
		return e
	}
	if _, e = file.Write(raw); e != nil {
		file.Close()
		return e
	}
	if e = file.Sync(); e != nil {
		file.Close()
		return e
	}
	if e = file.Close(); e != nil {
		return e
	}
	if e = os.Rename(file.Name(), path); e != nil {
		return e
	}
	d, e := os.Open(dir)
	if e == nil {
		defer d.Close()
		return d.Sync()
	}
	return e
}
func openClient(root, endpoint string) (*Client, error) {
	c := &Client{Root: root, HTTP: &http.Client{Timeout: 45 * time.Second}}
	c.HTTP.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 3 {
			return errors.New("too many redirects")
		}
		if req.URL.Scheme != via[0].URL.Scheme || req.URL.Host != via[0].URL.Host {
			return errors.New("cross-origin redirect refused")
		}
		return nil
	}
	private := filepath.Join(root, "agent", "private")
	if e := os.MkdirAll(private, 0700); e != nil {
		return nil, e
	}
	if e := os.Chmod(private, 0700); e != nil {
		return nil, e
	}
	file := filepath.Join(private, "identity.json")
	raw, e := os.ReadFile(file)
	if e == nil {
		if e = json.Unmarshal(raw, &c.Identity); e != nil {
			return nil, e
		}
		return c, nil
	}
	if !os.IsNotExist(e) {
		return nil, e
	}
	u, e := url.Parse(endpoint)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.Path != "" || u.RawQuery != "" {
		return nil, errors.New("endpoint must be an HTTPS origin")
	}
	res, e := c.HTTP.Get(endpoint + "/agent/bootstrap.json")
	if e != nil {
		return nil, e
	}
	defer res.Body.Close()
	if res.StatusCode != 200 {
		return nil, fmt.Errorf("bootstrap HTTP %d", res.StatusCode)
	}
	var bootstrap struct {
		Public string `json:"public_key"`
	}
	if e = json.NewDecoder(io.LimitReader(res.Body, 65536)).Decode(&bootstrap); e != nil {
		return nil, e
	}
	key, e := b64.DecodeString(bootstrap.Public)
	if e != nil || len(key) != ed25519.PublicKeySize {
		return nil, errors.New("invalid bootstrap signing key")
	}
	_, secret, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		return nil, e
	}
	c.Identity = Identity{Endpoint: endpoint, Trust: bootstrap.Public, Key: b64.EncodeToString(secret), RequestID: requestUUID(), Control: randomHex(32)}
	if e = c.save(); e != nil {
		return nil, e
	}
	return c, nil
}
func (c *Client) save() error {
	return atomicJSON(filepath.Join(c.Root, "agent/private/identity.json"), c.Identity, 0600)
}
func (c *Client) post(action string, data any, out any) error {
	raw, e := json.Marshal(data)
	if e != nil {
		return e
	}
	path := "/api/v2/installer/" + action
	req, e := http.NewRequest("POST", c.Identity.Endpoint+path, bytes.NewReader(raw))
	if e != nil {
		return e
	}
	stamp := fmt.Sprint(time.Now().Unix())
	nonce := randomHex(16)
	key, e := b64.DecodeString(c.Identity.Key)
	if e != nil || len(key) != ed25519.PrivateKeySize {
		return errors.New("invalid private device key")
	}
	signature := ed25519.Sign(key, []byte("POST\n"+path+"\n"+stamp+"\n"+nonce+"\n"+digest(raw)))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Request-Timestamp", stamp)
	req.Header.Set("X-Request-Nonce", nonce)
	req.Header.Set("X-Device-Signature", b64.EncodeToString(signature))
	if c.Identity.Credential != "" {
		req.Header.Set("Authorization", "Bearer "+c.Identity.Credential)
	}
	res, e := c.HTTP.Do(req)
	if e != nil {
		return e
	}
	defer res.Body.Close()
	var response struct {
		Success bool            `json:"success"`
		Data    json.RawMessage `json:"data"`
		Code    string          `json:"code"`
		Message string          `json:"message"`
	}
	if e = json.NewDecoder(io.LimitReader(res.Body, 1024*1024)).Decode(&response); e != nil {
		return fmt.Errorf("invalid API response (HTTP %d)", res.StatusCode)
	}
	if res.StatusCode < 200 || res.StatusCode > 299 || !response.Success {
		return fmt.Errorf("%s: %s (HTTP %d)", response.Code, response.Message, res.StatusCode)
	}
	return json.Unmarshal(response.Data, out)
}
func verify(trust string, envelope Envelope, out any) error {
	key, e := b64.DecodeString(trust)
	if e != nil || len(key) != 32 {
		return errors.New("invalid pinned key")
	}
	raw, e := b64.DecodeString(envelope.Payload)
	if e != nil || len(raw) > 65536 {
		return errors.New("invalid signed payload")
	}
	sig, e := b64.DecodeString(envelope.Signature)
	if e != nil || envelope.Algorithm != "Ed25519" || !ed25519.Verify(key, append([]byte(contextPrefix), raw...), sig) {
		return errors.New("state signature rejected")
	}
	return json.Unmarshal(raw, out)
}
func (c *Client) accept(envelope Envelope, h Hardware) (State, error) {
	var s State
	if e := verify(c.Identity.Trust, envelope, &s); e != nil {
		return s, e
	}
	fp, e := h.fingerprint()
	if e != nil {
		return s, e
	}
	if s.Kind != "state" || s.Protocol != 2 || s.Installation != c.Identity.Installation || s.Presented != fp || s.Sequence <= c.Identity.Sequence {
		return s, errors.New("state identity or sequence rejected")
	}
	if s.Hardware != fp && s.Access != "locked" {
		return s, errors.New("unsigned hardware authorization refused")
	}
	if s.Access != "allowed" && s.Access != "locked" && s.Access != "provisioning" {
		return s, errors.New("invalid state decision")
	}
	// Persist the envelope first. A crash must never advance the cursor without
	// retaining the corresponding authoritative state.
	public := filepath.Join(c.Root, "agent/public")
	if e = os.MkdirAll(public, 0755); e != nil {
		return s, e
	}
	if e = os.Chmod(public, 0755); e != nil {
		return s, e
	}
	if e = atomicJSON(filepath.Join(public, "trust.json"), map[string]string{"public_key": c.Identity.Trust, "installation_id": s.Installation}, 0644); e != nil {
		return s, e
	}
	if e = atomicJSON(filepath.Join(public, "state.json"), envelope, 0644); e != nil {
		return s, e
	}
	c.Identity.Sequence = s.Sequence
	return s, c.save()
}
func (c *Client) poll(h Hardware) (State, error) {
	var data struct {
		Signed Envelope `json:"signed_state"`
	}
	if e := c.post("state", map[string]any{"hardware": h}, &data); e != nil {
		return State{}, e
	}
	return c.accept(data.Signed, h)
}
func (c *Client) activate(code, action string, h Hardware) (State, error) {
	key, _ := b64.DecodeString(c.Identity.Key)
	host, _ := os.Hostname()
	id := c.Identity.RequestID
	if action == "reactivate" {
		if c.Identity.ReactivationID == "" {
			c.Identity.ReactivationID = requestUUID()
			if e := c.save(); e != nil {
				return State{}, e
			}
		}
		id = c.Identity.ReactivationID
	}
	body := map[string]any{"hardware": h, "license_key": code, "hostname": host, "client_request_id": id, "device_public_key": b64.EncodeToString(ed25519.PrivateKey(key).Public().(ed25519.PublicKey)), "agent_version": version}
	if action == "reactivate" {
		body["health_ok"] = true
	}
	var data struct {
		ID         string   `json:"installation_id"`
		Credential string   `json:"credential"`
		Signed     Envelope `json:"signed_state"`
	}
	if e := c.post(action, body, &data); e != nil {
		return State{}, e
	}
	var verified State
	if e := verify(c.Identity.Trust, data.Signed, &verified); e != nil {
		return State{}, e
	}
	fp, _ := h.fingerprint()
	if data.ID != verified.Installation || verified.Hardware != fp || verified.Presented != fp || verified.Protocol != 2 || verified.Kind != "state" || data.Credential == "" {
		return State{}, errors.New("activation binding rejected")
	}
	old := c.Identity
	c.Identity.Installation = data.ID
	c.Identity.Credential = data.Credential
	c.Identity.Sequence = 0
	if e := c.save(); e != nil {
		c.Identity = old
		return State{}, e
	}
	s, e := c.accept(data.Signed, h)
	if e == nil && action == "reactivate" {
		c.Identity.ReactivationID = ""
		e = c.save()
	}
	return s, e
}
