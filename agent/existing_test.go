package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func existingFixture(t *testing.T) *Client {
	t.Helper()
	root := t.TempDir()
	bin := filepath.Join(root, "bin")
	os.Mkdir(bin, 0700)
	mounts, _ := json.Marshal([]map[string]any{
		{"Source": filepath.Join(root, "agent/public"), "Destination": "/run/office-agent/public", "RW": false},
		{"Source": filepath.Join(root, "agent/control"), "Destination": "/run/office-agent/control", "RW": false},
	})
	t.Setenv("FIXTURE_MOUNTS", string(mounts))
	t.Setenv("FIXTURE_LOG", filepath.Join(root, "calls"))
	script := `#!/bin/sh
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case "$*" in
  *Mounts*) printf '%s' "$FIXTURE_MOUNTS";;
  *compose.project*) printf 'leave-panel';;
  'exec office-web php artisan office-agent:health --json') printf '{"status":"ok","version":"3.8.20-rc.2"}';;
  *) exit 91;;
esac
`
	os.WriteFile(filepath.Join(bin, "docker"), []byte(script), 0700)
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
	os.MkdirAll(filepath.Join(root, "agent/private"), 0700)
	return &Client{Root: root, Identity: Identity{Control: strings.Repeat("a", 64)}}
}

func TestExistingOfficeConnectionPreservesAllApplicationResources(t *testing.T) {
	c := existingFixture(t)
	if err := c.prepareExisting(); err != nil {
		t.Fatal(err)
	}
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	_, device, _ := ed25519.GenerateKey(rand.Reader)
	c.Identity.Trust = b64.EncodeToString(pub)
	c.Identity.Key = b64.EncodeToString(device)
	c.Identity.RequestID = requestUUID()
	h := Hardware{"20b612fc-d40b-43aa-a660-00ff0202dd10", "00112233445566778899aabbccddeeff"}
	fp, _ := h.fingerprint()
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		state := State{Kind: "state", Protocol: 2, Installation: "existing-a", Sequence: uint64(calls), Hardware: fp, Presented: fp, ActivationMode: "attach_once", Access: "provisioning"}
		var data any
		if strings.HasSuffix(r.URL.Path, "/begin") {
			if body["intent"] != "connect" {
				t.Error("connection intent missing")
			}
			data = map[string]any{"installation_id": "existing-a", "credential": "credential", "signed_state": envelope(key, state)}
		} else if strings.HasSuffix(r.URL.Path, "/complete") {
			if body["application_version"] != "3.8.20-rc.2" || body["health_ok"] != true {
				t.Error("health receipt invalid")
			}
			if _, ok := body["package_sha256"]; ok {
				t.Error("connection depends on a new package")
			}
			state.Completed = true
			state.Access = "allowed"
			data = map[string]any{"signed_state": envelope(key, state)}
		} else {
			t.Error("unexpected package request")
		}
		json.NewEncoder(w).Encode(map[string]any{"success": true, "data": data})
	}))
	defer server.Close()
	c.Identity.Endpoint = server.URL
	c.HTTP = server.Client()
	if err := c.connectExisting("OFF-AAAA-BBBB-CCCC-DDDD", h); err != nil {
		t.Fatal(err)
	}
	if calls != 2 {
		t.Fatal("unexpected API actions")
	}
	if _, err := os.Stat(filepath.Join(c.Root, "agent/public/enabled")); err != nil {
		t.Fatal("enforcement not activated")
	}
	for _, name := range []string{".env", "compose.json", "initial-admin.json"} {
		if _, err := os.Stat(filepath.Join(c.Root, name)); !os.IsNotExist(err) {
			t.Fatal("existing configuration was replaced:", name)
		}
	}
	raw, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	for _, command := range []string{" down", " up", " load", "migrate", "volume", "rm "} {
		if strings.Contains(string(raw), command) {
			t.Fatal("destructive/deployment command during connection", string(raw))
		}
	}
}

func TestExistingOfficeRequiresCorrectReadonlyMountsBeforeConnecting(t *testing.T) {
	c := existingFixture(t)
	t.Setenv("FIXTURE_MOUNTS", `[{"Source":"/wrong","Destination":"/run/office-agent/public","RW":true}]`)
	if err := c.prepareExisting(); err == nil {
		t.Fatal("unsafe mounts accepted")
	}
	if _, err := os.Stat(filepath.Join(c.Root, "agent/public/enabled")); !os.IsNotExist(err) {
		t.Fatal("failed setup enabled licensing")
	}
}

func TestExistingOfficeUnhealthySetupNeverCallsCentral(t *testing.T) {
	c := existingFixture(t)
	os.WriteFile(filepath.Join(c.Root, "bin/docker"), []byte("#!/bin/sh\nexit 1\n"), 0700)
	if err := c.connectExisting("OFF-AAAA-BBBB-CCCC-DDDD", Hardware{}); err == nil {
		t.Fatal("unhealthy installation accepted")
	}
	if _, err := os.Stat(filepath.Join(c.Root, "agent/public/enabled")); !os.IsNotExist(err) {
		t.Fatal("failed setup blocked Office")
	}
}
