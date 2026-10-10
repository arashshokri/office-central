package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// This exercises real FrankenPHP under the helper's systemd umask, without
// customer credentials, storage mounts or network access.
func TestOfficeSourceWebRuntimeInServiceSandbox(t *testing.T) {
	root := os.Getenv("OFFICE_BUILDX_SANDBOX_ROOT")
	if root == "" {
		t.Skip("requires the isolated systemd/Docker integration runner")
	}
	if os.Geteuid() != 0 || !strings.HasPrefix(root, "/var/lib/office-build-test.") {
		t.Fatal("invalid isolated sandbox fixture")
	}
	fixture := sourceZip(t, map[string]string{
		"office-tag/Dockerfile":       "FROM dunglas/frankenphp:1-php8.4-bookworm AS production\nENV XDG_CONFIG_HOME=/tmp/caddy/config XDG_DATA_HOME=/tmp/caddy/data\nCOPY docker/Caddyfile /etc/caddy/Caddyfile\nCOPY docker/php.ini /usr/local/etc/php/conf.d/zz-leave-panel.ini\nCOPY public /app/public\nUSER www-data\nFROM production AS managed\n",
		"office-tag/docker/Caddyfile": "{\n auto_https off\n admin off\n}\n:8080 {\n root * /app/public\n php_server\n}\n",
		"office-tag/docker/php.ini":   "memory_limit=512M\n",
		"office-tag/public/index.php": "<?php echo 'office-health';\n",
	})
	stage := filepath.Join(root, "web-source")
	if err := os.MkdirAll(stage, 0700); err != nil {
		t.Fatal(err)
	}
	if err := extractOfficeSource(fixture, stage, sourceManifest()); err != nil {
		t.Fatal(err)
	}
	image := "office-helper-web-test:" + strings.TrimPrefix(filepath.Base(root), "office-build-test.")
	config := filepath.Join(root, "agent/private/docker")
	env, err := dockerBuildEnvironment(config)
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("DOCKER_CONFIG", config)
	t.Setenv("BUILDX_CONFIG", filepath.Join(config, "buildx"))
	docker := func(args ...string) ([]byte, error) {
		command := exec.Command("docker", args...)
		command.Env = env
		return command.CombinedOutput()
	}
	t.Cleanup(func() { docker("image", "rm", image) })
	// Reproduce the customer's root-owned configuration with inherited 0600.
	if err := os.Chmod(filepath.Join(stage, "docker/Caddyfile"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := buildOfficeImage(stage, image, "3.8.22", filepath.Join(root, "web-legacy.log"), config, nil); err != nil {
		t.Fatal(err)
	}
	raw, err := docker("run", "--rm", "--network", "none", "--entrypoint", "frankenphp", image, "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile")
	if err == nil || !strings.Contains(string(raw), "Caddyfile: permission denied") {
		t.Fatalf("legacy image did not reproduce the unreadable web config: %v\n%s", err, raw)
	}
	t.Log("Reproduced Caddyfile: permission denied as the image runtime user.")
	// Re-extract in another private root; the fix must ignore the service umask.
	stage = filepath.Join(root, "web-source-fixed")
	if err := os.MkdirAll(stage, 0700); err != nil {
		t.Fatal(err)
	}
	if err := extractOfficeSource(fixture, stage, sourceManifest()); err != nil {
		t.Fatal(err)
	}
	if err := buildOfficeImage(stage, image, "3.8.22", filepath.Join(root, "web-fixed.log"), config, nil); err != nil {
		t.Fatal(err)
	}
	if err := validateOfficeWebConfig(image); err != nil {
		t.Fatal(err)
	}
	raw, err = docker("run", "--rm", "--network", "none", "--entrypoint", "sh", image, "-c", `
set -eu
test "$(id -u)" = 33
test -r /etc/caddy/Caddyfile
test -r /usr/local/etc/php/conf.d/zz-leave-panel.ini
frankenphp run --config /etc/caddy/Caddyfile >/tmp/office-web-test.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true' EXIT
attempt=0
while [ "$attempt" -lt 30 ]; do
  if php -r "exit(@file_get_contents('http://127.0.0.1:8080/up') === 'office-health' ? 0 : 1);"; then exit 0; fi
  kill -0 "$server" || break
  attempt=$((attempt + 1))
  sleep 1
done
cat /tmp/office-web-test.log
exit 1
`)
	if err != nil {
		t.Fatalf("fixed image did not serve healthy HTTP as www-data: %v\n%s", err, raw)
	}
	t.Log("Fixed source image served /up over HTTP as www-data under UMask=0077.")
}
