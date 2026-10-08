package main

import (
	"archive/zip"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"
)

func TestUpdateConfirmationPinsVersionAndRelease(t *testing.T) {
	state := State{Package: Package{Version: "3.8.23", Release: "release-a"}}
	for _, row := range []struct {
		confirmation UpdateConfirmation
		want         bool
	}{
		{UpdateConfirmation{Version: "3.8.23", Release: "release-a"}, true},
		{UpdateConfirmation{Version: "3.8.22", Release: "release-a"}, false},
		{UpdateConfirmation{Version: "3.8.23", Release: "release-b"}, false},
		{UpdateConfirmation{Version: "3.8.23"}, true},
		{UpdateConfirmation{}, true}, // Existing CLI clients retain their behavior.
	} {
		if row.confirmation.matches(state) != row.want {
			t.Fatal(row)
		}
	}
}

func TestMalformedUpdateConfirmationDoesNotStartWork(t *testing.T) {
	c := &Client{Root: t.TempDir()}
	w := httptest.NewRecorder()
	r := httptest.NewRequest("POST", "/update", strings.NewReader(`{"expected_version":42}`))
	c.handleUpdates(w, r, &sync.Mutex{}, func(State) { t.Fatal("unexpected enforcement") })
	if w.Code != 422 || !strings.Contains(w.Body.String(), "UPDATE_CONFIRMATION_INVALID") {
		t.Fatal(w.Code, w.Body)
	}
	if job := c.updateJob(); job.Status != "" {
		t.Fatal("unexpected job", job)
	}
}

func TestUpdateSemVerAndSafeErrors(t *testing.T) {
	for _, row := range []struct {
		next, current string
		want          bool
	}{{"3.10.0", "3.9.9", true}, {"3.8.21", "3.8.21", false}, {"3.8.20", "3.8.21", false}, {"3.8.21-rc.10", "3.8.21-rc.2", true}, {"3.8.21-rc.1", "3.8.21", false}, {"3.8.21", "3.8.21-rc.1", true}} {
		if newerVersion(row.next, row.current) != row.want {
			t.Fatal(row)
		}
	}
	text := safeUpdateError(assertError("DB_PASSWORD=fixturesecret token=fixturetoken https://update.ponet.ir/api/v1/packages/download/odt_fixturecode"))
	for _, secret := range []string{"fixturesecret", "fixturetoken", "odt_fixturecode"} {
		if strings.Contains(text, secret) {
			t.Fatal("error leaks a secret")
		}
	}
}

type assertError string

func (e assertError) Error() string { return string(e) }

func existingUpdateFixture(t *testing.T) (*Client, State, Hardware, *UpdateJob) {
	t.Helper()
	c := existingFixture(t)
	root := c.Root
	source := filepath.Join(root, "original-compose.yaml")
	os.WriteFile(source, []byte("fixture"), 0600)
	model := map[string]any{"name": "leave-panel", "services": map[string]any{
		"app": map[string]any{"image": "old-image", "container_name": "office-web", "build": map[string]any{"context": root}, "environment": map[string]string{"APP_KEY": "fixture-original-key", "DB_PASSWORD": "fixture-original-password"}, "ports": []any{map[string]any{"target": 8080, "published": "8080", "host_ip": "127.0.0.1"}}, "networks": map[string]any{"internal": nil}, "volumes": []any{map[string]string{"type": "volume", "source": "app_storage", "target": "/app/storage"}}},
		"db":  map[string]any{"image": "original-db:1", "container_name": "office-db"}, "scheduler": map[string]any{"image": "old-image", "container_name": "office-cron"}},
		"volumes": map[string]any{"app_storage": map[string]string{"name": "customer_storage"}}, "networks": map[string]any{"internal": map[string]string{"name": "customer_internal"}}}
	raw, _ := json.Marshal(model)
	t.Setenv("FIXTURE_MODEL", string(raw))
	labels, _ := json.Marshal(map[string]string{"com.docker.compose.project": "leave-panel", "com.docker.compose.project.working_dir": root, "com.docker.compose.project.config_files": source})
	t.Setenv("FIXTURE_LABELS", string(labels))
	mounts, _ := json.Marshal([]map[string]string{{"Destination": "/app/storage", "Name": "customer_storage", "Type": "volume"}})
	t.Setenv("FIXTURE_STORAGE", string(mounts))
	script := `#!/bin/sh
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case "$*" in
 *'.Config.Labels'*) printf '%s' "$FIXTURE_LABELS";;
 *'.Mounts'*) printf '%s' "$FIXTURE_STORAGE";;
 *'.NetworkSettings.Networks'*) printf '{"customer_internal":{},"proxynet":{}}';;
 *'.State.Running'*) printf 'true';;
 'image inspect --format {{.Id}} fixture/app:1') printf 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';;
 *'config --format json') printf '%s' "$FIXTURE_MODEL";;
 *'office-helper-migrate') if [ "$FAIL_MIGRATION" = 1 ]; then echo 'SQLSTATE fixture migration failure' >&2; exit 1; fi;;
 'exec office-web php artisan office-agent:health --json') printf '{"status":"ok","version":"3.8.22"}';;
 'exec office-db sh -c '*) printf 'CREATE TABLE fixture(id INT);';;
 'run --rm --network none '*) printf 'storage backup fixture';;
esac
`
	os.WriteFile(filepath.Join(root, "bin/docker"), []byte(script), 0700)
	public, key, _ := ed25519.GenerateKey(rand.Reader)
	_, device, _ := ed25519.GenerateKey(rand.Reader)
	h := Hardware{"20b612fc-d40b-43aa-a660-00ff0202dd10", "00112233445566778899aabbccddeeff"}
	fp, _ := h.fingerprint()
	c.Identity.Trust = b64.EncodeToString(public)
	c.Identity.Key = b64.EncodeToString(device)
	c.Identity.Installation = "existing-a"
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/complete") {
			t.Error("unexpected request", r.URL.Path)
		}
		var receipt map[string]any
		json.NewDecoder(r.Body).Decode(&receipt)
		if receipt["application_version"] != "3.8.22" || receipt["health_ok"] != true {
			t.Error("bad completion receipt")
		}
		state := State{Kind: "state", Protocol: 2, Installation: c.Identity.Installation, Sequence: 1, Hardware: fp, Presented: fp, Access: "allowed", Completed: true}
		json.NewEncoder(w).Encode(map[string]any{"success": true, "data": map[string]any{"signed_state": envelope(key, state)}})
	}))
	t.Cleanup(server.Close)
	c.HTTP = server.Client()
	c.Identity.Endpoint = server.URL
	manifest := Manifest{Format: "office-runtime-v1", Product: "office", Protection: "none", Architecture: runtime.GOARCH, Version: "3.8.22"}
	archive := filepath.Join(root, "fixture.zip")
	f, _ := os.Create(archive)
	z := zip.NewWriter(f)
	for _, role := range []string{"app", "db", "redis", "rdp-web", "rdp-core"} {
		body := []byte("image-" + role)
		path := "images/" + role + ".tar"
		entry, _ := z.Create(path)
		entry.Write(body)
		manifest.Images = append(manifest.Images, Image{Role: role, Archive: path, SHA: digest(body), ID: "sha256:" + strings.Repeat("a", 64), Ref: "fixture/" + role + ":1"})
	}
	entry, _ := z.Create("manifest.json")
	raw, _ = json.Marshal(manifest)
	entry.Write(raw)
	z.Close()
	f.Close()
	sha := shaFile(archive)
	os.MkdirAll(filepath.Join(root, "cache"), 0700)
	os.Rename(archive, filepath.Join(root, "cache", sha+".zip"))
	info, _ := os.Stat(filepath.Join(root, "cache", sha+".zip"))
	state := State{Access: "allowed", Completed: true, Package: Package{Release: "release-a", Version: "3.8.22", SHA: sha, Size: info.Size(), Manifest: manifest}, Update: UpdateOffer{Available: true, Release: "release-a", Version: "3.8.22"}}
	return c, state, h, &UpdateJob{ID: "fixture-job", Status: "running", Version: "3.8.22"}
}
func TestExistingUpdatePreservesEnvironmentDatabaseAndProxy(t *testing.T) {
	c, state, h, job := existingUpdateFixture(t)
	if err := c.updateExisting(state, h, job, &ExistingOffice{Container: "office-web", Project: "leave-panel"}); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(filepath.Join(c.Root, "agent/private/active-compose.json"))
	if err != nil {
		t.Fatal(err)
	}
	var model map[string]any
	json.Unmarshal(raw, &model)
	services := model["services"].(map[string]any)
	app := services["app"].(map[string]any)
	env := app["environment"].(map[string]any)
	if env["APP_KEY"] != "fixture-original-key" || env["DB_PASSWORD"] != "fixture-original-password" {
		t.Fatal("customer secrets replaced")
	}
	if services["db"].(map[string]any)["image"] != "original-db:1" {
		t.Fatal("database engine changed")
	}
	if !strings.Contains(string(raw), "proxynet") || !strings.Contains(string(raw), "customer_storage") {
		t.Fatal("proxy or storage replaced")
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	text := string(commands)
	if strings.Index(text, "mariadb-dump") > strings.Index(text, "run --rm --no-deps -T office-helper-migrate") {
		t.Fatal("migration ran before backup")
	}
	for _, bad := range []string{"down", "volume rm", "migrate:fresh", " up -d db", "git clone"} {
		if strings.Contains(text, bad) {
			t.Fatal("destructive update", text)
		}
	}
	if _, err := os.Stat(filepath.Join(c.Root, "backups/fixture-job/checksums.json")); err != nil {
		t.Fatal("no verified backup")
	}
	if _, err := os.Stat(filepath.Join(c.Root, "agent/private/update-receipt.json")); !os.IsNotExist(err) {
		t.Fatal("receipt not confirmed")
	}
}
func TestFailedMigrationKeepsDataAndPublishesMaintenanceError(t *testing.T) {
	c, state, h, job := existingUpdateFixture(t)
	t.Setenv("FAIL_MIGRATION", "1")
	err := c.updateExisting(state, h, job, &ExistingOffice{Container: "office-web", Project: "leave-panel"})
	if err == nil || !strings.Contains(err.Error(), "SQLSTATE") || !job.Maintenance {
		t.Fatal("migration failure not contained", err)
	}
	raw, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	if !strings.Contains(string(raw), "start office-web") {
		t.Fatal("progress endpoint not recovered")
	}
	if _, e := os.Stat(filepath.Join(c.Root, "backups/fixture-job/database.sql")); e != nil {
		t.Fatal("backup missing")
	}
	if strings.Contains(string(raw), "down") || strings.Contains(string(raw), "migrate:fresh") {
		t.Fatal("data deleted")
	}
	_ = state
	_ = h
}

func TestPendingConfirmationDoesNotRepeatMigrationOrBackup(t *testing.T) {
	c, state, h, job := existingUpdateFixture(t)
	if err := atomicJSON(filepath.Join(c.Root, "agent/private/update-receipt.json"), updateReceipt{c.Identity.Installation, h, state.Package}, 0600); err != nil {
		t.Fatal(err)
	}
	if err := c.performUpdate(job, h, UpdateConfirmation{Version: state.Package.Version, Release: state.Package.Release}); err != nil {
		t.Fatal(err)
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	for _, command := range []string{"mariadb-dump", "office-helper-migrate", "load -i"} {
		if strings.Contains(string(commands), command) {
			t.Fatal("repeated an already completed deployment", string(commands))
		}
	}
	if job.Status != "success" || job.Maintenance {
		t.Fatal("completion not recovered", job)
	}
}

func TestPendingConfirmationRejectsDifferentApprovedVersionWithoutTouchingData(t *testing.T) {
	c, state, h, job := existingUpdateFixture(t)
	if err := atomicJSON(filepath.Join(c.Root, "agent/private/update-receipt.json"), updateReceipt{c.Identity.Installation, h, state.Package}, 0600); err != nil {
		t.Fatal(err)
	}
	err := c.performUpdate(job, h, UpdateConfirmation{Version: "3.8.23"})
	if err == nil || !strings.Contains(err.Error(), "UPDATE_OFFER_CHANGED") {
		t.Fatal(err)
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	if len(commands) != 0 {
		t.Fatal("unexpected Docker operation", string(commands))
	}
}
