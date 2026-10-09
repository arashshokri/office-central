package main

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

func TestBuildDiagnosticsKeepFinalFailureAndRemoveTerminalCodes(t *testing.T) {
	err := errors.New("ساخت بسته ناموفق بود\n" + strings.Repeat("package download output\n", 1000) + "\x1b[91mFATAL: registry denied DB_PASSWORD=private-value\x1b[0m")
	text := safeUpdateError(err)
	if !strings.Contains(text, "FATAL: registry denied") || strings.Contains(text, "private-value") || strings.Contains(text, "\x1b") || !utf8.ValidString(text) || len([]rune(text)) > 3000 {
		t.Fatal(text)
	}
}

func TestBuildStreamsVerifiedPhaseProgressAndPrivateLog(t *testing.T) {
	path := filepath.Join(t.TempDir(), "build.log")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	var percent int
	var step string
	w := &buildOutput{file: f, vertices: map[string]string{}, completed: map[string]bool{}, report: func(p int, s string) { percent, step = p, s }}
	w.Write([]byte("#8 [vendor 4/4] RUN composer install\n"))
	if percent != 30 || !strings.Contains(step, "composer install") {
		t.Fatal(percent, step)
	}
	w.lastReport = time.Now().Add(-3 * time.Second)
	w.Write([]byte("#8 DONE 1.0s\n"))
	if percent != 35 {
		t.Fatal("phase was not completed", percent)
	}
	raw, err := os.ReadFile(path)
	info, _ := os.Stat(path)
	if err != nil || !strings.Contains(string(raw), "#8 DONE") || info.Mode().Perm() != 0600 {
		t.Fatal(err)
	}
}

func TestBuildRunnerStreamsFailureTailAndEnforcesDeadline(t *testing.T) {
	newOutput := func() *buildOutput { return &buildOutput{vertices: map[string]string{}, completed: map[string]bool{}} }
	w := newOutput()
	err := executeBuild(exec.Command("sh", "-c", "printf 'first\\nFATAL last line\\n'; exit 9"), w, time.Second, time.Second)
	if err == nil || !strings.Contains(err.Error(), "exit status 9") || !strings.Contains(err.Error(), "FATAL last line") {
		t.Fatal(err)
	}
	err = executeBuild(exec.Command("sh", "-c", "exec sleep 5"), newOutput(), 50*time.Millisecond, time.Second)
	if err == nil || !strings.Contains(err.Error(), "BUILD_TIMEOUT") {
		t.Fatal(err)
	}
	err = executeBuild(exec.Command("sh", "-c", "exec sleep 5"), newOutput(), 10*time.Second, 50*time.Millisecond)
	if err == nil || !strings.Contains(err.Error(), "BUILD_IDLE_TIMEOUT") {
		t.Fatal(err)
	}
}

func TestMissingBuildxFailsBeforeMaintenanceOrMigration(t *testing.T) {
	c, state, h, job := sourceUpdateFixture(t)
	t.Setenv("NO_BUILDX", "1")
	err := c.updateExisting(state, h, job, &ExistingOffice{Container: "office-web", Project: "leave-panel"})
	if err == nil || !strings.Contains(err.Error(), "BUILDX_REQUIRED") || job.Maintenance {
		t.Fatal(err, job)
	}
	commands, _ := os.ReadFile(filepath.Join(c.Root, "calls"))
	for _, bad := range []string{"buildx build", "mariadb-dump", "office-helper-migrate", "stop office"} {
		if strings.Contains(string(commands), bad) {
			t.Fatal("missing prerequisites touched customer runtime", string(commands))
		}
	}
}
