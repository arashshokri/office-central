package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"
)

func TestOfficeSourcePermissionsIgnoreServiceUmask(t *testing.T) {
	// Umask is process-wide; reproduce the service in a separate process so
	// this test does not weaken or interfere with other tests' private files.
	if os.Getenv("OFFICE_TEST_SOURCE_UMASK") != "1" {
		cmd := exec.Command(os.Args[0], "-test.run=^TestOfficeSourcePermissionsIgnoreServiceUmask$", "-test.v")
		cmd.Env = append(os.Environ(), "OFFICE_TEST_SOURCE_UMASK=1")
		if raw, err := cmd.CombinedOutput(); err != nil {
			t.Fatalf("restrictive source extraction: %v\n%s", err, raw)
		}
		return
	}
	syscall.Umask(0077)
	path := sourceZip(t, map[string]string{"office-tag/docker/start.sh": "#!/bin/sh\n", "office-tag/docker/php.ini": "memory_limit=512M\n"})
	root := filepath.Join(t.TempDir(), "private-stage")
	if err := os.Mkdir(root, 0700); err != nil {
		t.Fatal(err)
	}
	if err := extractOfficeSource(path, root, sourceManifest()); err != nil {
		t.Fatal(err)
	}
	for name, mode := range map[string]os.FileMode{".": 0700, "docker": 0755, "app/Providers": 0755, "docker/Caddyfile": 0644, "docker/php.ini": 0644, "docker/start.sh": 0755} {
		info, err := os.Stat(filepath.Join(root, name))
		if err != nil || info.Mode().Perm() != mode {
			t.Fatalf("%s must be %04o inside the private stage: %v %v", name, mode, info, err)
		}
	}
}
