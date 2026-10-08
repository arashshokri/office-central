package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestFailedSnapshotRestartsWritersWithoutDeletingData(t *testing.T) {
	dir := t.TempDir()
	bin := filepath.Join(dir, "bin")
	os.Mkdir(bin, 0700)
	log := filepath.Join(dir, "commands")
	script := `#!/bin/sh
printf '%s\n' "$*" >> "$MOCK_LOG"
case "$1" in
 inspect) printf 'true\n';;
 exec) exit 9;;
 volume) exit 1;;
esac
`
	os.WriteFile(filepath.Join(bin, "docker"), []byte(script), 0700)
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
	t.Setenv("MOCK_LOG", log)
	c := Client{Root: dir}
	destination := filepath.Join(dir, "backup")
	if e := c.snapshot(destination, "fixture/app:1"); e == nil {
		t.Fatal("failed database dump accepted")
	}
	raw, _ := os.ReadFile(log)
	commands := string(raw)
	if !strings.Contains(commands, "stop office-web") || !strings.Contains(commands, "start office-web") {
		t.Fatal("writers not restored after failed snapshot")
	}
	if strings.Contains(commands, " rm ") || strings.Contains(commands, "down") || strings.Contains(commands, "migrate:fresh") {
		t.Fatal("destructive command in backup")
	}
	if _, e := os.Stat(filepath.Join(destination, "database.sql")); !os.IsNotExist(e) {
		t.Fatal("partial dump retained as valid")
	}
}
func TestSuccessfulSnapshotIsPrivateAndChecksummed(t *testing.T) {
	dir := t.TempDir()
	bin := filepath.Join(dir, "bin")
	os.Mkdir(bin, 0700)
	log := filepath.Join(dir, "commands")
	script := `#!/bin/sh
printf '%s\n' "$*" >> "$MOCK_LOG"
case "$1" in
 inspect) printf 'true\n';;
 exec) printf 'CREATE TABLE fixture(id INT);\n';;
 volume) exit 0;;
 run) printf 'storage fixture archive';;
esac
`
	os.WriteFile(filepath.Join(bin, "docker"), []byte(script), 0700)
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
	t.Setenv("MOCK_LOG", log)
	c := Client{Root: dir}
	destination := filepath.Join(dir, "backup")
	if e := c.snapshot(destination, "fixture/app:1"); e != nil {
		t.Fatal(e)
	}
	info, _ := os.Stat(filepath.Join(destination, "database.sql"))
	if info.Mode().Perm() != 0600 {
		t.Fatal("backup is not private")
	}
	if _, e := os.Stat(filepath.Join(destination, "checksums.json")); e != nil {
		t.Fatal("backup checksum missing")
	}
}
