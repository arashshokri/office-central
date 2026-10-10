package main

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestBuildConfigKeepsPrivateStateOutsideHomeAndContext(t *testing.T) {
	root := t.TempDir()
	config := filepath.Join(root, "agent/private/docker")
	if err := os.MkdirAll(config, 0755); err != nil {
		t.Fatal(err)
	}
	old := []byte(`{"auths":{"registry.example.test":{"auth":"existing-login"}}}`)
	if err := os.WriteFile(filepath.Join(config, "config.json"), old, 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DOCKER_CONFIG", "/root/.docker")
	t.Setenv("BUILDX_CONFIG", "/root/.docker/buildx")
	t.Setenv("HTTPS_PROXY", "https://proxy.example.test")
	beforeHome := os.Getenv("HOME")
	env, err := dockerBuildEnvironment(config)
	if err != nil {
		t.Fatal(err)
	}
	values := map[string]string{}
	for _, value := range env {
		key, text, _ := strings.Cut(value, "=")
		if key == "DOCKER_CONFIG" || key == "BUILDX_CONFIG" {
			if _, exists := values[key]; exists {
				t.Fatal("duplicate config override")
			}
		}
		values[key] = text
	}
	if values["DOCKER_CONFIG"] != config || values["BUILDX_CONFIG"] != filepath.Join(config, "buildx") || values["HOME"] != beforeHome || values["HTTPS_PROXY"] != "https://proxy.example.test" {
		t.Fatal("build changed an unrelated environment or used the home config")
	}
	after, err := os.ReadFile(filepath.Join(config, "config.json"))
	if err != nil || string(after) != string(old) {
		t.Fatal("private registry settings were replaced", err)
	}
	for _, dir := range []string{config, filepath.Join(config, "buildx")} {
		info, err := os.Stat(dir)
		if err != nil || info.Mode().Perm() != 0700 {
			t.Fatal("Docker client state is not private", err)
		}
	}
	for _, helperRoot := range []string{"/var/lib/office-helper", "/opt/office"} {
		unit := officeServiceUnit(helperRoot)
		for _, setting := range []string{"ProtectHome=true\n", "ProtectSystem=full\n", "NoNewPrivileges=true\n", "UMask=0077\n", "Environment=DOCKER_CONFIG=" + helperRoot + "/agent/private/docker\n", "Environment=BUILDX_CONFIG=" + helperRoot + "/agent/private/docker/buildx\n"} {
			if !strings.Contains(unit, setting) {
				t.Fatal("service lost sandbox protection or the correct private path", setting)
			}
		}
	}
}

func TestInvalidBuildConfigStopsBeforeInvokingDocker(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "not-a-directory")
	if err := os.WriteFile(path, []byte("occupied"), 0600); err != nil {
		t.Fatal(err)
	}
	err := buildOfficeImage(root, "unused-image", "0.0.1", "", path, nil)
	if err == nil || !strings.Contains(err.Error(), "BUILD_CONFIG:") {
		t.Fatal(err)
	}
}

// CI runs this test as root in a real systemd service with ProtectHome and
// ProtectSystem enabled. It builds a scratch image without registry access.
func TestBuildOfficeImageInServiceSandbox(t *testing.T) {
	root := os.Getenv("OFFICE_BUILDX_SANDBOX_ROOT")
	if root == "" {
		t.Skip("requires the isolated systemd/Docker integration runner")
	}
	if os.Geteuid() != 0 || !strings.HasPrefix(root, "/var/lib/office-build-test.") {
		t.Fatal("invalid isolated sandbox fixture")
	}
	if probe, err := os.CreateTemp("/root", ".office-sandbox-check-"); err == nil {
		probe.Close()
		os.Remove(probe.Name())
		t.Fatal("integration test did not protect the home directory")
	}
	stage := filepath.Join(root, "source")
	if err := os.MkdirAll(stage, 0700); err != nil {
		t.Fatal(err)
	}
	for name, content := range map[string]string{"Dockerfile": "FROM scratch AS managed\nARG APP_RELEASE_VERSION\nLABEL office.test.version=$APP_RELEASE_VERSION\nCOPY VERSION /VERSION\n", "VERSION": "0.0.1\n"} {
		if err := os.WriteFile(filepath.Join(stage, name), []byte(content), 0600); err != nil {
			t.Fatal(err)
		}
	}
	image := "office-helper-sandbox:" + strings.TrimPrefix(filepath.Base(root), "office-build-test.")
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	// Prove the old default config reproduces the customer's exact failure.
	legacy := exec.CommandContext(ctx, "docker", "buildx", "build", "--builder", "default", "--load", "--target", "managed", "-t", image, stage)
	raw, err := legacy.CombinedOutput()
	if err == nil || !strings.Contains(string(raw), "/root/.docker") || !strings.Contains(strings.ToLower(string(raw)), "read-only file system") {
		t.Fatalf("legacy build did not reproduce the protected-home error: %v\n%s", err, raw)
	}
	t.Log("Reproduced the old /root/.docker read-only failure.")
	config := filepath.Join(root, "agent/private/docker")
	if err := buildOfficeImage(stage, image, "0.0.1", filepath.Join(root, "build.log"), config, nil); err != nil {
		t.Fatal(err)
	}
	env, err := dockerBuildEnvironment(config)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		cleanup := exec.Command("docker", "image", "rm", image)
		cleanup.Env = env
		cleanup.Run()
	})
	inspect := exec.Command("docker", "image", "inspect", "--format", `{{index .Config.Labels "office.test.version"}}`, image)
	inspect.Env = env
	raw, err = inspect.CombinedOutput()
	if err != nil || strings.TrimSpace(string(raw)) != "0.0.1" {
		t.Fatalf("verified image did not load into the same Docker daemon: %v\n%s", err, raw)
	}
	t.Log("Built and loaded the image with private client state and the same service protections.")
}
