package main

import (
	"archive/zip"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestConcurrentSetupPinsOnlyOneDeviceIdentity(t *testing.T) {
	public, _, _ := ed25519.GenerateKey(rand.Reader)
	var requests atomic.Int32
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		time.Sleep(30 * time.Millisecond)
		json.NewEncoder(w).Encode(map[string]string{"public_key": b64.EncodeToString(public)})
	}))
	defer server.Close()
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = server.Client().Transport.(*http.Transport).TLSClientConfig.Clone()
	transport.TLSClientConfig.RootCAs = x509.NewCertPool()
	transport.TLSClientConfig.RootCAs.AddCert(server.Certificate())
	previous := http.DefaultTransport
	http.DefaultTransport = transport
	defer func() { http.DefaultTransport = previous; transport.CloseIdleConnections() }()
	root := t.TempDir()
	clients := make(chan *Client, 2)
	errors := make(chan error, 2)
	for i := 0; i < 2; i++ {
		go func() { c, err := openClient(root, server.URL); clients <- c; errors <- err }()
	}
	a, b := <-clients, <-clients
	for i := 0; i < 2; i++ {
		if err := <-errors; err != nil {
			t.Fatal(err)
		}
	}
	if requests.Load() != 1 || a.Identity.Key != b.Identity.Key || a.Identity.RequestID != b.Identity.RequestID {
		t.Fatal("simultaneous setup replaced the device identity")
	}
}

func fixtureState(t *testing.T) (*Client, Hardware, ed25519.PrivateKey, State) {
	t.Helper()
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "agent/private"), 0700)
	h := Hardware{"20b612fc-d40b-43aa-a660-00ff0202dd10", "00112233445566778899aabbccddeeff"}
	fp, _ := h.fingerprint()
	c := &Client{Root: root, Identity: Identity{Trust: b64.EncodeToString(pub), Installation: "installation-a"}}
	s := State{Kind: "state", Protocol: 2, Installation: "installation-a", Sequence: 1, Hardware: fp, Presented: fp, Access: "allowed", Completed: true}
	return c, h, key, s
}
func envelope(key ed25519.PrivateKey, s any) Envelope {
	raw, _ := json.Marshal(s)
	return Envelope{b64.EncodeToString(raw), b64.EncodeToString(ed25519.Sign(key, append([]byte(contextPrefix), raw...))), "Ed25519"}
}
func TestHardwareValidationAndNormalization(t *testing.T) {
	h := Hardware{"20b612fc-d40b-43aa-a660-00ff0202dd10", "00112233445566778899aabbccddeeff"}
	fp, e := h.fingerprint()
	if e != nil || len(fp) != 64 {
		t.Fatal(e)
	}
	h.UUID = "00000000-0000-0000-0000-000000000000"
	if _, e = h.fingerprint(); e == nil {
		t.Fatal("zero UUID accepted")
	}
	h.UUID = "not-a-uuid"
	if _, e = h.fingerprint(); e == nil {
		t.Fatal("invalid UUID accepted")
	}
}
func TestSignedStateRejectsForgeryReplayAndWrongBinding(t *testing.T) {
	c, h, key, s := fixtureState(t)
	if _, e := c.accept(envelope(key, s), h); e != nil {
		t.Fatal(e)
	}
	if _, e := c.accept(envelope(key, s), h); e == nil {
		t.Fatal("replayed sequence accepted")
	}
	s.Sequence = 2
	s.Hardware = strings.Repeat("a", 64)
	if _, e := c.accept(envelope(key, s), h); e == nil {
		t.Fatal("wrong hardware allowed")
	}
	s.Access = "locked"
	if _, e := c.accept(envelope(key, s), h); e != nil {
		t.Fatal("signed clone lock refused", e)
	}
	forged := envelope(key, s)
	forged.Payload = b64.EncodeToString([]byte(`{"access":"allowed"}`))
	if e := verify(c.Identity.Trust, forged, &State{}); e == nil {
		t.Fatal("forged state accepted")
	}
}
func TestWrongPurposeAndInstallationRejected(t *testing.T) {
	c, h, key, s := fixtureState(t)
	s.Kind = "download"
	if _, e := c.accept(envelope(key, s), h); e == nil {
		t.Fatal("download interpreted as state")
	}
	s.Kind = "state"
	s.Installation = "other"
	if _, e := c.accept(envelope(key, s), h); e == nil {
		t.Fatal("wrong installation accepted")
	}
}
func TestBundleRejectsTraversalAndRawSource(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "bad.zip")
	file, _ := os.Create(path)
	writer := zip.NewWriter(file)
	entry, _ := writer.Create("../source.php")
	entry.Write([]byte("<?php"))
	writer.Close()
	file.Close()
	if e := extractBundle(path, filepath.Join(root, "extract"), Manifest{}); e == nil {
		t.Fatal("traversal accepted")
	}
	if e := validateManifest(Manifest{Format: "office-runtime-v1", Product: "office", Protection: "none"}); e == nil {
		t.Fatal("unprotected package accepted")
	}
}
func TestPrivateIdentityPermission(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "private/identity.json")
	if e := atomicJSON(path, map[string]string{"secret": "test"}, 0600); e != nil {
		t.Fatal(e)
	}
	info, _ := os.Stat(path)
	if info.Mode().Perm() != 0600 {
		t.Fatal("identity exposed")
	}
}
func TestBundleChecksumsAndManifest(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "good.zip")
	file, _ := os.Create(path)
	writer := zip.NewWriter(file)
	m := Manifest{Format: "office-runtime-v1", Product: "office", Protection: "ioncube"}
	for _, role := range []string{"app", "db", "redis", "rdp-web", "rdp-core"} {
		archive := "images/" + role + ".tar"
		body := []byte(role)
		m.Images = append(m.Images, Image{Role: role, Archive: archive, SHA: digest(body)})
		entry, _ := writer.Create(archive)
		entry.Write(body)
	}
	raw, _ := json.Marshal(m)
	entry, _ := writer.Create("manifest.json")
	entry.Write(raw)
	writer.Close()
	file.Close()
	if e := extractBundle(path, filepath.Join(root, "extract"), m); e != nil {
		t.Fatal(e)
	}
	m.Images[0].SHA = strings.Repeat("a", 64)
	if e := extractBundle(path, filepath.Join(root, "other"), m); e == nil {
		t.Fatal("changed manifest accepted")
	}
}
