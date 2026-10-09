package main

import (
	"archive/zip"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func sourceZip(t *testing.T, entries map[string]string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "source.zip")
	f, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	z := zip.NewWriter(f)
	files := map[string]string{}
	for _, name := range []string{"artisan", "composer.json", "composer.lock", "docker/Caddyfile", "app/Providers/OfficeLicenseServiceProvider.php"} {
		files["office-tag/"+name] = "<?php fixture"
	}
	files["office-tag/VERSION"] = "3.8.22"
	files["office-tag/Dockerfile"] = "FROM production AS managed"
	files["office-tag/bootstrap/providers.php"] = "<?php return [OfficeLicenseServiceProvider::class];"
	for name, body := range entries {
		files[name] = body
	}
	for name, body := range files {
		stream, err := z.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err = stream.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err = z.Close(); err != nil {
		t.Fatal(err)
	}
	if err = f.Close(); err != nil {
		t.Fatal(err)
	}
	return path
}

func sourceManifest() Manifest {
	return Manifest{Format: "office-source-v1", Product: "office", Version: "3.8.22", Architecture: "any", Protection: "none", SourceRoot: "office-tag/"}
}

func sourceUpdateFixture(t *testing.T) (*Client, State, Hardware, *UpdateJob) {
	t.Helper()
	c, state, h, job := existingUpdateFixture(t)
	path := sourceZip(t, nil)
	sha := shaFile(path)
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(filepath.Join(c.Root, "cache", sha+".zip"), raw, 0600); err != nil {
		t.Fatal(err)
	}
	state.Package.SHA = sha
	state.Package.Size = int64(len(raw))
	state.Package.Manifest = sourceManifest()
	if err = os.Rename(filepath.Join(c.Root, "bin/docker"), filepath.Join(c.Root, "bin/docker-runtime")); err != nil {
		t.Fatal(err)
	}
	script := `#!/bin/sh
case "$*" in
 'buildx version') if [ "$NO_BUILDX" = 1 ]; then exit 1; fi;;
 'buildx build '*) printf '%s\n' "$*" >> "$FIXTURE_LOG"; if [ "$FAIL_SOURCE_BUILD" = 1 ]; then echo 'registry unavailable'; exit 1; fi;;
 'image inspect --format {{.Id}} office-source:'*) printf '%s\n' "$*" >> "$FIXTURE_LOG"; printf 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';;
 'run --rm --network none --entrypoint cat '*) printf '%s\n' "$*" >> "$FIXTURE_LOG"; printf '3.8.22';;
 'run --rm --network none --entrypoint php '*) printf '%s\n' "$*" >> "$FIXTURE_LOG";;
 *) exec "$(dirname "$0")/docker-runtime" "$@";;
esac
`
	if err = os.WriteFile(filepath.Join(c.Root, "bin/docker"), []byte(script), 0700); err != nil {
		t.Fatal(err)
	}
	return c, state, h, job
}

func TestFreshSourceInstallBuildsFullRuntimeAndConfirmsOnlyAfterHealth(t *testing.T) {
	c, state, h, _ := sourceUpdateFixture(t)
	state.Completed = false
	state.Access = "provisioning"
	state.Deployment = Deployment{URL: "https://office.customer.test", Email: "admin@example.test", Bind: "127.0.0.1", Port: 8082}
	script := `#!/bin/sh
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case "$*" in
 'volume inspect leave-panel_db_data') if [ "$EXISTING_DATABASE" = 1 ]; then exit 0; else exit 1; fi;;
 'image inspect --format {{.Id}} '*) printf 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';;
 'run --rm --network none --entrypoint cat '*) printf '3.8.22';;
 *'migrate php artisan office-agent:admin') cat >/dev/null;;
 *'office-agent:health --expected-version='*) if [ "$FAIL_FRESH_HEALTH" = 1 ]; then exit 8; fi;;
esac
`
	if err := os.WriteFile(filepath.Join(c.Root, "bin/docker"), []byte(script), 0700); err != nil {
		t.Fatal(err)
	}
	args := os.Args
	os.Args = []string{"office-agent", "daemon"}
	t.Cleanup(func() { os.Args = args })
	t.Setenv("EXISTING_DATABASE", "1")
	if err := c.deploy(state, h, ""); err == nil || !strings.Contains(err.Error(), "existing customer database") {
		t.Fatal("existing data was not protected", err)
	}
	if _, err := os.Stat(filepath.Join(c.Root, ".env")); !os.IsNotExist(err) {
		t.Fatal("existing data environment replaced")
	}
	t.Setenv("EXISTING_DATABASE", "")
	t.Setenv("FAIL_FRESH_HEALTH", "1")
	if err := c.deploy(state, h, ""); err == nil {
		t.Fatal("unhealthy installation confirmed")
	}
	if _, err := os.Stat(filepath.Join(c.Root, "agent/public/state.json")); !os.IsNotExist(err) {
		t.Fatal("failed health saved completed state")
	}
	credentials, err := os.ReadFile(filepath.Join(c.Root, "initial-admin.json"))
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("FAIL_FRESH_HEALTH", "")
	if err := c.deploy(state, h, ""); err != nil {
		t.Fatal(err)
	}
	after, _ := os.ReadFile(filepath.Join(c.Root, "initial-admin.json"))
	if string(credentials) != string(after) {
		t.Fatal("retry changed administrator credentials")
	}
	raw, _ := os.ReadFile(filepath.Join(c.Root, "compose.json"))
	var compose map[string]any
	if err := json.Unmarshal(raw, &compose); err != nil {
		t.Fatal(err)
	}
	services := compose["services"].(map[string]any)
	for _, name := range []string{"app", "db", "redis", "rdp-web", "rdp-guacd"} {
		if services[name].(map[string]any)["image"] != "sha256:"+strings.Repeat("a", 64) {
			t.Fatal("image was not pinned", name)
		}
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	for _, ref := range []string{"mariadb:10.11.18", "redis:7.4.5-alpine", "guacamole/guacamole:1.6.0", "guacamole/guacd:1.6.0"} {
		if !strings.Contains(string(commands), ref) {
			t.Fatal("infrastructure missing", ref)
		}
	}
	if strings.Contains(string(commands), "down -v") || strings.Contains(string(commands), "volume rm") {
		t.Fatal("destructive database action")
	}
}

func TestSourceUpdateBuildsBeforeMaintenanceAndKeepsCustomerDatabase(t *testing.T) {
	c, state, h, job := sourceUpdateFixture(t)
	if err := c.updateExisting(state, h, job, &ExistingOffice{Container: "office-web", Project: "leave-panel"}); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(filepath.Join(c.Root, "agent/private/active-compose.json"))
	if err != nil {
		t.Fatal(err)
	}
	var model map[string]any
	if err = json.Unmarshal(raw, &model); err != nil {
		t.Fatal(err)
	}
	services := model["services"].(map[string]any)
	if services["db"].(map[string]any)["image"] != "original-db:1" {
		t.Fatal("database replaced")
	}
	env := services["app"].(map[string]any)["environment"].(map[string]any)
	if env["APP_KEY"] != "fixture-original-key" || env["DB_PASSWORD"] != "fixture-original-password" {
		t.Fatal("customer secrets replaced")
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	text := string(commands)
	if !strings.Contains(text, "buildx build --builder default --load --progress plain --platform linux/") || strings.Index(text, "buildx build") > strings.Index(text, "mariadb-dump") {
		t.Fatal("build did not precede maintenance and backup", text)
	}
	for _, bad := range []string{"down", "volume rm", "migrate:fresh", "load -i", "git clone", " up -d db"} {
		if strings.Contains(text, bad) {
			t.Fatal("unexpected operation", text)
		}
	}
	if _, err = os.Stat(filepath.Join(c.Root, "backups/fixture-job/checksums.json")); err != nil {
		t.Fatal("backup missing")
	}
}

func TestFailedSourceBuildLeavesRunningOfficeAndDataUntouched(t *testing.T) {
	c, state, h, job := sourceUpdateFixture(t)
	t.Setenv("FAIL_SOURCE_BUILD", "1")
	err := c.updateExisting(state, h, job, &ExistingOffice{Container: "office-web", Project: "leave-panel"})
	if err == nil || !strings.Contains(err.Error(), "registry unavailable") || job.Maintenance {
		t.Fatal("build failure not contained", err, job)
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	for _, forbidden := range []string{"stop", "migrate", "mariadb-dump", "up -d", "down", "volume rm"} {
		if strings.Contains(string(commands), forbidden) {
			t.Fatal("build failure changed live services", string(commands))
		}
	}
}

func TestSourceExtractorRejectsPathsSecretsDuplicatesAndVersionMismatch(t *testing.T) {
	for _, extra := range []map[string]string{
		{"office-tag/../escape.php": "bad"}, {"office-tag/.env": "DB_PASSWORD=secret"},
		{"office-tag/vendor/autoload.php": "bad"}, {"office-tag/Version": "3.8.22"},
		{"outside.php": "bad"}, {"office-tag/VERSION": "3.8.23"},
		{"office-tag/Dockerfile": "FROM production"}, {"office-tag/bootstrap/providers.php": "<?php return [];"},
	} {
		if err := extractOfficeSource(sourceZip(t, extra), t.TempDir(), sourceManifest()); err == nil {
			t.Fatal("accepted unsafe source", extra)
		}
	}
}

func TestSourceChecksumMismatchPreventsAnyBuild(t *testing.T) {
	path := sourceZip(t, nil)
	_, err := buildSourceImage(path, t.TempDir(), Package{SHA: strings.Repeat("0", 64), Version: "3.8.22", Manifest: sourceManifest()})
	if err == nil || !strings.Contains(err.Error(), "checksum") {
		t.Fatal(err)
	}
}

func TestSourceAcceptsBundledPublicAssets(t *testing.T) {
	path := sourceZip(t, map[string]string{"office-tag/public/vendor/chart.js/chart.js": "/* shipped asset */"})
	if err := extractOfficeSource(path, t.TempDir(), sourceManifest()); err != nil {
		t.Fatal(err)
	}
}
