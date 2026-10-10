package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"
)

type BuildReport func(int, string)

type buildOutput struct {
	mu            sync.Mutex
	file          *os.File
	tail, pending string
	last          time.Time
	bytes         int
	report        BuildReport
	vertices      map[string]string
	completed     map[string]bool
	step          string
	lastReport    time.Time
}

var buildVertex = regexp.MustCompile(`^#(\d+) \[([a-zA-Z0-9_-]+) (\d+)/(\d+)\] (.+)$`)
var buildDone = regexp.MustCompile(`^#(\d+) (?:DONE|CACHED)(?:\s|$)`)

func (w *buildOutput) Write(raw []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.last = time.Now()
	text := cleanErrorText(string(raw))
	w.tail += text
	if len(w.tail) > 65536 {
		w.tail = w.tail[len(w.tail)-65536:]
	}
	// Keep a bounded private log and a separate recent tail for diagnostics.
	if w.file != nil && w.bytes < 32<<20 {
		remaining := (32 << 20) - w.bytes
		if len(text) > remaining {
			text = text[:remaining]
		}
		if _, err := w.file.WriteString(text); err != nil {
			return 0, err
		}
		w.bytes += len(text)
	}
	w.pending += string(raw)
	for {
		i := strings.IndexByte(w.pending, '\n')
		if i < 0 {
			break
		}
		line := strings.TrimSpace(cleanErrorText(w.pending[:i]))
		w.pending = w.pending[i+1:]
		if parts := buildVertex.FindStringSubmatch(line); parts != nil {
			w.step = parts[2] + ": " + parts[5]
			if parts[3] == parts[4] {
				w.vertices[parts[1]] = parts[2]
			}
		}
		if parts := buildDone.FindStringSubmatch(line); parts != nil {
			if phase := w.vertices[parts[1]]; phase != "" {
				w.completed[phase] = true
			}
		}
	}
	if len(w.pending) > 65536 {
		w.pending = w.pending[len(w.pending)-65536:]
	}
	if w.report != nil && time.Since(w.lastReport) >= 2*time.Second {
		progress := 30
		for phase, weight := range map[string]int{"php-base": 8, "vendor": 5, "assets": 5, "terminal-runtime": 4, "ssh-prerequisites": 4, "managed": 4} {
			if w.completed[phase] {
				progress += weight
			}
		}
		w.report(progress, w.step)
		w.lastReport = time.Now()
	}
	return len(raw), nil
}

func (w *buildOutput) recent() string {
	w.mu.Lock()
	defer w.mu.Unlock()
	return w.tail
}

// Cancel a silent or hung build instead of buffering output indefinitely.
// This command never attaches customer volumes or changes running services.
func executeBuild(command *exec.Cmd, output *buildOutput, total, idle time.Duration) error {
	ctx, cancel := context.WithTimeout(context.Background(), total)
	defer cancel()
	// CommandContext installs the context used by os/exec's cancellation watcher.
	cmd := exec.CommandContext(ctx, command.Path, command.Args[1:]...)
	cmd.Env = command.Env
	cmd.WaitDelay = 2 * time.Second
	cmd.Stdout, cmd.Stderr = output, output
	output.last = time.Now()
	finished := make(chan struct{})
	idleExpired := false
	var guard sync.Mutex
	go func() {
		ticker := time.NewTicker(time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-finished:
				return
			case <-ticker.C:
				output.mu.Lock()
				quiet := time.Since(output.last)
				output.mu.Unlock()
				if quiet > idle {
					guard.Lock()
					idleExpired = true
					guard.Unlock()
					cancel()
					return
				}
			}
		}
	}()
	err := cmd.Run()
	close(finished)
	guard.Lock()
	timedOut := idleExpired
	guard.Unlock()
	if timedOut {
		return fmt.Errorf("BUILD_IDLE_TIMEOUT: ساخت برای %s خروجی جدیدی نداشت؛ اتصال مخازن و منابع سرور را بررسی کنید.\n%s", idle, output.recent())
	}
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return fmt.Errorf("BUILD_TIMEOUT: ساخت در مهلت %s کامل نشد.\n%s", total, output.recent())
	}
	if err != nil {
		return fmt.Errorf("BUILD_FAILED: %w\nآخرین خروجی ساخت:\n%s", err, output.recent())
	}
	return nil
}

// Buildx writes client state even with the local default builder. The service
// intentionally hides /root, so keep this state outside HOME and the source
// build context. Existing private config/auth files are never overwritten.
func dockerBuildEnvironment(configDir string) ([]string, error) {
	if !filepath.IsAbs(configDir) {
		return nil, errors.New("BUILD_CONFIG: مسیر خصوصی تنظیمات Docker باید مطلق باشد")
	}
	buildxDir := filepath.Join(configDir, "buildx")
	for _, dir := range []string{configDir, buildxDir} {
		if err := os.MkdirAll(dir, 0700); err != nil {
			return nil, fmt.Errorf("BUILD_CONFIG: آماده‌سازی مسیر خصوصی Docker ناموفق بود: %w", err)
		}
		if err := os.Chmod(dir, 0700); err != nil {
			return nil, fmt.Errorf("BUILD_CONFIG: تنظیم دسترسی مسیر خصوصی Docker ناموفق بود: %w", err)
		}
		probe, err := os.CreateTemp(dir, ".write-check-")
		if err != nil {
			return nil, fmt.Errorf("BUILD_CONFIG: مسیر خصوصی Docker قابل نوشتن نیست: %w", err)
		}
		probe.Close()
		if err := os.Remove(probe.Name()); err != nil {
			return nil, err
		}
	}
	env := make([]string, 0, len(os.Environ())+2)
	for _, value := range os.Environ() {
		if !strings.HasPrefix(value, "DOCKER_CONFIG=") && !strings.HasPrefix(value, "BUILDX_CONFIG=") {
			env = append(env, value)
		}
	}
	return append(env, "DOCKER_CONFIG="+configDir, "BUILDX_CONFIG="+buildxDir), nil
}

func buildOfficeImage(stage, image, version, logPath, configDir string, report BuildReport) error {
	env, err := dockerBuildEnvironment(configDir)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	check := exec.CommandContext(ctx, "docker", "buildx", "version")
	check.Env = env
	if err := check.Run(); err != nil {
		return errors.New("BUILDX_REQUIRED: افزونهٔ Docker Buildx در دسترس نیست. مدیر سرور باید افزونهٔ سازگار با Docker نصب کند (در مخزن رسمی Docker: apt-get install docker-buildx-plugin)، سپس دوباره تلاش کنید؛ هیچ تغییری در دیتابیس انجام نشده است.")
	}
	var file *os.File
	if logPath != "" {
		if err := os.MkdirAll(filepath.Dir(logPath), 0700); err != nil {
			return err
		}
		var err error
		file, err = os.OpenFile(logPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0600)
		if err != nil {
			return err
		}
		defer file.Close()
		if err := file.Chmod(0600); err != nil {
			return err
		}
	}
	w := &buildOutput{file: file, report: report, vertices: map[string]string{}, completed: map[string]bool{}}
	cmd := exec.Command("docker", "buildx", "build", "--builder", "default", "--load", "--progress", "plain", "--platform", "linux/"+runtime.GOARCH, "--target", "managed", "--build-arg", "APP_RELEASE_VERSION="+version, "-t", image, stage)
	cmd.Env = env
	err = executeBuild(cmd, w, 45*time.Minute, 5*time.Minute)
	if file != nil && w.bytes >= 32<<20 {
		file.WriteString("\nآخرین خروجی ساخت:\n" + w.recent())
	}
	if err != nil && logPath != "" {
		return fmt.Errorf("%w\nلاگ ساخت: %s", err, logPath)
	}
	return err
}

// Keep error summaries valid UTF-8 and retain the actual final error.
func errorSummary(text string, limit int) string {
	runes := []rune(text)
	if limit <= 0 {
		return ""
	}
	if len(runes) <= limit {
		return text
	}
	separator := []rune("\n… خروجی میانی کوتاه شده است …\n")
	if limit <= len(separator)+200 {
		return string(runes[len(runes)-limit:])
	}
	return string(runes[:200]) + string(separator) + string(runes[len(runes)-(limit-200-len(separator)):])
}
