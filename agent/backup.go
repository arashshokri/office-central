package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

func streamCommand(path string, name string, args ...string) error {
	file, e := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if e != nil {
		return e
	}
	cmd := exec.Command(name, args...)
	cmd.Stdout = file
	cmd.Stderr = os.Stderr
	e = cmd.Run()
	syncErr := file.Sync()
	closeErr := file.Close()
	if e != nil {
		os.Remove(path)
		return fmt.Errorf("backup command failed: %w", e)
	}
	if syncErr != nil {
		return syncErr
	}
	if closeErr != nil {
		return closeErr
	}
	info, e := os.Stat(path)
	if e != nil {
		return e
	}
	if info.Size() == 0 {
		return fmt.Errorf("empty backup refused")
	}
	return nil
}

// The database and storage are never deleted. On a failed snapshot, restart
// previously running writers; after migration begins, leave them stopped until
// an explicit resume rather than running old code against a partial schema.
func (c *Client) snapshot(directory string, appImage string) error {
	if e := os.MkdirAll(directory, 0700); e != nil {
		return e
	}
	if e := os.Chmod(directory, 0700); e != nil {
		return e
	}
	names := []string{"office-web", "office-queue", "office-cron", "office-messenger", "office-ssh", "office-rdp", "office-rdp-core"}
	running := []string{}
	for _, name := range names {
		raw, e := output("docker", "inspect", "--format", "{{.State.Running}}", name)
		if e == nil && strings.TrimSpace(string(raw)) == "true" {
			running = append(running, name)
		}
	}
	if len(running) > 0 {
		if e := run(nil, "docker", append([]string{"stop"}, running...)...); e != nil {
			return e
		}
	}
	complete := false
	defer func() {
		if !complete && len(running) > 0 {
			_ = run(nil, "docker", append([]string{"start"}, running...)...)
		}
	}()
	if e := run(nil, "docker", "start", "office-db"); e != nil {
		return fmt.Errorf("existing database container must be recoverable before snapshot: %w", e)
	}
	sql := filepath.Join(directory, "database.sql")
	if e := streamCommand(sql, "docker", "exec", "office-db", "sh", "-c", `export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump --user=root --single-transaction --routines --events --databases "$MARIADB_DATABASE"`); e != nil {
		return e
	}
	if _, e := output("docker", "volume", "inspect", "leave-panel_app_storage"); e == nil {
		if e = streamCommand(filepath.Join(directory, "storage.tar.gz"), "docker", "run", "--rm", "--network", "none", "--user", "0:0", "--entrypoint", "tar", "-v", "leave-panel_app_storage:/source:ro", appImage, "-C", "/source", "-czf", "-", "."); e != nil {
			return e
		}
	}
	checks := map[string]string{"database.sql": shaFile(sql)}
	if _, e := os.Stat(filepath.Join(directory, "storage.tar.gz")); e == nil {
		checks["storage.tar.gz"] = shaFile(filepath.Join(directory, "storage.tar.gz"))
	}
	if e := atomicJSON(filepath.Join(directory, "checksums.json"), checks, 0600); e != nil {
		return e
	}
	complete = true
	return nil
}
