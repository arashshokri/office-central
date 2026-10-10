package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

type UpdateJob struct {
	ID          string `json:"id"`
	Status      string `json:"status"`
	Progress    int    `json:"progress"`
	Stage       string `json:"stage"`
	Version     string `json:"version"`
	Message     string `json:"message"`
	Error       string `json:"error,omitempty"`
	Maintenance bool   `json:"maintenance"`
	StartedAt   string `json:"started_at"`
	FinishedAt  string `json:"finished_at,omitempty"`
}

func (c *Client) updateJob() UpdateJob {
	var job UpdateJob
	raw, err := os.ReadFile(filepath.Join(c.Root, "agent/public/update.json"))
	if err == nil {
		json.Unmarshal(raw, &job)
	}
	return job
}
func (c *Client) saveJob(job UpdateJob) error {
	return atomicJSON(filepath.Join(c.Root, "agent/public/update.json"), job, 0644)
}
func (c *Client) updateStage(job *UpdateJob, stage, message string, maintenance bool) error {
	// Completed-work milestones, never an estimate of elapsed build time.
	progress := map[string]int{"queued": 0, "authorization": 3, "download": 8, "build": 30,
		"backup": 65, "migration": 75, "services": 85, "health": 94, "confirmation": 98}[stage]
	if progress > job.Progress {
		job.Progress = progress
	}
	job.Stage = stage
	job.Message = message
	job.Maintenance = maintenance
	return c.saveJob(*job)
}
func cleanErrorText(text string) string {
	text = regexp.MustCompile(`\x1b\[[0-?]*[ -/]*[@-~]`).ReplaceAllString(text, "")
	text = regexp.MustCompile(`(?i)(password|token|secret|app_key)(["']?\s*[:=]\s*)[^\s,]+`).ReplaceAllString(text, "$1$2[redacted]")
	text = regexp.MustCompile(`odt_[A-Za-z0-9]+`).ReplaceAllString(text, "[download-token]")
	return text
}

func safeUpdateError(err error) string { return errorSummary(cleanErrorText(err.Error()), 3000) }

// SemVer precedence, including numeric prerelease identifiers. Never offer a
// downgrade merely because a lexical comparison puts 3.10 before 3.9.
func newerVersion(candidate, current string) bool {
	parse := func(s string) ([3]int, string, bool) {
		var core [3]int
		parts := strings.SplitN(strings.TrimPrefix(s, "v"), "-", 2)
		nums := strings.Split(parts[0], ".")
		if len(nums) != 3 {
			return core, "", false
		}
		for i, n := range nums {
			v, e := strconv.Atoi(n)
			if e != nil || v < 0 {
				return core, "", false
			}
			core[i] = v
		}
		pre := ""
		if len(parts) == 2 {
			pre = parts[1]
		}
		return core, pre, true
	}
	a, ap, ok := parse(candidate)
	b, bp, valid := parse(current)
	if !ok || !valid {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return a[i] > b[i]
		}
	}
	if ap == bp {
		return false
	}
	if ap == "" {
		return true
	}
	if bp == "" {
		return false
	}
	aa, bb := strings.Split(ap, "."), strings.Split(bp, ".")
	for i := 0; i < len(aa) && i < len(bb); i++ {
		if aa[i] == bb[i] {
			continue
		}
		an, ae := strconv.Atoi(aa[i])
		bn, be := strconv.Atoi(bb[i])
		if ae == nil && be == nil {
			return an > bn
		}
		if ae == nil {
			return false
		}
		if be == nil {
			return true
		}
		return aa[i] > bb[i]
	}
	return len(aa) > len(bb)
}
func (c *Client) checkUpdate(h Hardware) (State, string, error) {
	state, err := c.poll(h)
	if err != nil {
		return state, "", err
	}
	raw, err := output("docker", "exec", "office-web", "cat", "/app/VERSION")
	if err != nil {
		return state, "", err
	}
	installed := strings.TrimSpace(string(raw))
	if !regexp.MustCompile(`^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$`).MatchString(installed) {
		return state, "", errors.New("invalid installed Office version")
	}
	job := c.updateJob()
	_, pending := os.Stat(filepath.Join(c.Root, "agent/private/update-receipt.json"))
	retry := state.Update.Version == installed && (job.Maintenance || pending == nil)
	state.Update.Available = state.Access == "allowed" && state.Completed && state.Update.Available && (newerVersion(state.Update.Version, installed) || retry)
	return state, installed, nil
}

type updateReceipt struct {
	Installation string   `json:"installation"`
	Hardware     Hardware `json:"hardware"`
	Package      Package  `json:"package"`
}

func (c *Client) confirmDeployment(p Package, h Hardware) error {
	receipt := updateReceipt{c.Identity.Installation, h, p}
	path := filepath.Join(c.Root, "agent/private/update-receipt.json")
	if err := atomicJSON(path, receipt, 0600); err != nil {
		return err
	}
	var response struct {
		Signed Envelope `json:"signed_state"`
	}
	if err := c.post("complete", map[string]any{"hardware": h, "release_id": p.Release, "package_sha256": p.SHA, "application_version": p.Version, "health_ok": true}, &response); err != nil {
		return err
	}
	if _, err := c.accept(response.Signed, h); err != nil {
		return err
	}
	return os.Remove(path)
}
func (c *Client) performUpdate(job *UpdateJob, h Hardware, confirmation UpdateConfirmation) (err error) {
	defer func() {
		job.FinishedAt = time.Now().UTC().Format(time.RFC3339)
		if err != nil {
			job.Status = "error"
			job.Error = safeUpdateError(err)
			job.Message = "بروزرسانی در مرحلهٔ «" + job.Stage + "» متوقف شد."
		} else {
			job.Status = "success"
			job.Progress = 100
			job.Stage = "complete"
			job.Message = "بروزرسانی با موفقیت نصب و در مرکز ثبت شد."
			job.Maintenance = false
		}
		if e := c.saveJob(*job); e != nil && err == nil {
			err = e
		}
	}()
	// A lost completion response is retried without repeating backup/migration.
	if raw, e := os.ReadFile(filepath.Join(c.Root, "agent/private/update-receipt.json")); e == nil {
		var receipt updateReceipt
		if json.Unmarshal(raw, &receipt) != nil || receipt.Installation != c.Identity.Installation {
			return errors.New("invalid pending update receipt")
		}
		if !confirmation.matches(State{Package: receipt.Package}) {
			return errors.New("UPDATE_OFFER_CHANGED: نسخهٔ تأییدشده با نصب در انتظار ثبت نتیجه مطابقت ندارد؛ دوباره وضعیت را بررسی کنید")
		}
		fp, _ := h.fingerprint()
		bound, _ := receipt.Hardware.fingerprint()
		if fp != bound {
			return errors.New("pending receipt belongs to different hardware")
		}
		installed, e := c.existingHealth()
		if e != nil {
			return e
		}
		if installed != receipt.Package.Version {
			return errors.New("installed version differs from pending receipt")
		}
		job.Version = installed
		c.updateStage(job, "confirmation", "نصب انجام شده؛ ثبت نتیجه در مرکز…", false)
		return c.confirmDeployment(receipt.Package, h)
	}
	if err = c.updateStage(job, "authorization", "بررسی نسخهٔ مجاز در مرکز…", job.Maintenance); err != nil {
		return err
	}
	state, _, err := c.checkUpdate(h)
	if err != nil {
		return err
	}
	if !state.Update.Available || state.Package.Release != state.Update.Release {
		return errors.New("UPDATE_NOT_AUTHORIZED: نسخهٔ جدیدتری برای این لایسنس باز نشده است")
	}
	if !confirmation.matches(state) {
		return errors.New("UPDATE_OFFER_CHANGED: نسخهٔ مجاز تغییر کرده است؛ دوباره بروزرسانی را بررسی و نسخهٔ جدید را تأیید کنید")
	}
	job.Version = state.Package.Version
	profile, e := c.existingOffice()
	if e != nil {
		return e
	}
	if profile == nil {
		// An installed managed deployment follows the same non-destructive update
		// path as an attached Office: keep its existing database and configuration.
		profile = &ExistingOffice{Container: "office-web", Project: "leave-panel"}
	}
	return c.updateExisting(state, h, job, profile)
}

func writeControlJSON(w http.ResponseWriter, status int, value any) {
	raw, _ := json.Marshal(value)
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Content-Length", fmt.Sprint(len(raw)))
	w.WriteHeader(status)
	w.Write(raw)
}

type UpdateConfirmation struct {
	Version string `json:"expected_version"`
	Release string `json:"expected_release_id"`
}

func (c *Client) cachedUpdate(h Hardware) map[string]any {
	fingerprint, err := h.fingerprint()
	age := time.Since(c.CheckedAt)
	if err != nil || c.CheckedFingerprint != fingerprint || age < 0 || age >= 10*time.Second {
		return nil
	}
	return c.CheckedUpdate
}

func (confirmation UpdateConfirmation) matches(state State) bool {
	return (confirmation.Version == "" || confirmation.Version == state.Package.Version) &&
		(confirmation.Release == "" || confirmation.Release == state.Package.Release)
}

func (c *Client) handleUpdates(w http.ResponseWriter, r *http.Request, mu *sync.Mutex, enforce func(State)) bool {
	if r.URL.Path != "/check-update" && r.URL.Path != "/update" && r.URL.Path != "/update-status" {
		return false
	}
	if r.URL.Path == "/update-status" {
		writeControlJSON(w, 200, c.updateJob())
		return true
	}
	if job := c.updateJob(); job.Status == "running" {
		writeControlJSON(w, 202, job)
		return true
	}
	var confirmation UpdateConfirmation
	if r.URL.Path == "/update" {
		err := json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&confirmation)
		if err != nil && err != io.EOF {
			writeControlJSON(w, 422, map[string]string{"message": "UPDATE_CONFIRMATION_INVALID: تأیید نسخه معتبر نیست؛ دوباره بروزرسانی را بررسی کنید."})
			return true
		}
	}
	mu.Lock()
	h, err := hardware()
	if err != nil {
		mu.Unlock()
		writeControlJSON(w, 422, map[string]string{"message": safeUpdateError(err)})
		return true
	}
	// Coalesce repeated read-only checks across tabs/users for ten seconds.
	// Installation always revalidates authorization without using this cache.
	fingerprint, _ := h.fingerprint()
	if r.URL.Path == "/check-update" {
		if cached := c.cachedUpdate(h); cached != nil {
			mu.Unlock()
			writeControlJSON(w, 200, cached)
			return true
		}
	}
	state, installed, err := c.checkUpdate(h)
	if err != nil {
		mu.Unlock()
		var limited *RateLimitError
		if errors.As(err, &limited) {
			w.Header().Set("Retry-After", strconv.Itoa(limited.Seconds))
			writeControlJSON(w, 429, map[string]any{"message": limited.Error(), "retry_after": limited.Seconds})
		} else {
			writeControlJSON(w, 502, map[string]string{"message": safeUpdateError(err)})
		}
		return true
	}
	if r.URL.Path == "/check-update" {
		c.CheckedUpdate = map[string]any{"update": state.Update, "installed_version": installed, "confirmation_supported": true, "checked_at": time.Now().UTC().Format(time.RFC3339)}
		c.CheckedAt, c.CheckedFingerprint = time.Now(), fingerprint
		cached := c.CheckedUpdate
		mu.Unlock()
		writeControlJSON(w, 200, cached)
		return true
	}
	if !confirmation.matches(state) {
		mu.Unlock()
		writeControlJSON(w, 409, map[string]string{"message": "UPDATE_OFFER_CHANGED: نسخهٔ مجاز تغییر کرده است؛ دوباره بروزرسانی را بررسی و نسخهٔ جدید را تأیید کنید."})
		return true
	}
	old := c.updateJob()
	if old.Status == "running" {
		mu.Unlock()
		writeControlJSON(w, 202, old)
		return true
	}
	_, pending := os.Stat(filepath.Join(c.Root, "agent/private/update-receipt.json"))
	if !state.Update.Available && pending != nil {
		mu.Unlock()
		writeControlJSON(w, 409, map[string]string{"message": "UPDATE_NOT_AUTHORIZED: نسخهٔ جدیدتری برای این لایسنس باز نشده است."})
		return true
	}
	job := UpdateJob{ID: randomHex(16), Status: "running", Stage: "queued", Version: state.Package.Version, Maintenance: old.Maintenance, Message: "بروزرسانی در صف اجراست…", StartedAt: time.Now().UTC().Format(time.RFC3339)}
	c.CheckedUpdate = nil
	if err = c.saveJob(job); err != nil {
		mu.Unlock()
		writeControlJSON(w, 500, map[string]string{"message": safeUpdateError(err)})
		return true
	}
	// The accepted operation owns the mutex before its response is sent. No
	// second request can queue a duplicate migration or race signed sequences.
	accepted := job
	confirmation = UpdateConfirmation{Version: state.Package.Version, Release: state.Package.Release}
	go func() {
		defer mu.Unlock()
		if e := c.performUpdate(&job, h, confirmation); e != nil {
			fmt.Fprintln(os.Stderr, "Office update:", safeUpdateError(e))
		}
		if s, e := c.poll(h); e == nil {
			enforce(s)
		}
	}()
	writeControlJSON(w, 202, accepted)
	return true
}

// Resolve the exact existing Compose configuration; never generate new DB
// credentials, change published ports, replace volumes, or pull source code.
func (c *Client) existingModel(profile *ExistingOffice) (map[string]any, string, error) {
	raw, err := output("docker", "inspect", "--format", "{{json .Config.Labels}}", "office-web")
	if err != nil {
		return nil, "", err
	}
	var labels map[string]string
	if err = json.Unmarshal(raw, &labels); err != nil {
		return nil, "", err
	}
	if labels["com.docker.compose.project"] != profile.Project {
		return nil, "", errors.New("Compose project changed")
	}
	dir := labels["com.docker.compose.project.working_dir"]
	if !filepath.IsAbs(dir) {
		return nil, "", errors.New("existing Compose working directory is unavailable")
	}
	args := []string{"compose", "--project-name", profile.Project, "--project-directory", dir}
	files := strings.Split(labels["com.docker.compose.project.config_files"], ",")
	for _, file := range files {
		if !filepath.IsAbs(file) {
			return nil, "", errors.New("existing Compose file is unavailable")
		}
		info, e := os.Stat(file)
		if e != nil || !info.Mode().IsRegular() {
			return nil, "", errors.New("existing Compose file cannot be read; do not remove the installed release directory")
		}
		args = append(args, "-f", file)
	}
	raw, err = output("docker", append(args, "config", "--format", "json")...)
	if err != nil {
		return nil, "", err
	}
	var model map[string]any
	if err = json.Unmarshal(raw, &model); err != nil {
		return nil, "", err
	}
	services, ok := model["services"].(map[string]any)
	if !ok {
		return nil, "", errors.New("invalid existing Compose services")
	}
	app, ok := services["app"].(map[string]any)
	if !ok || app["container_name"] != "office-web" {
		return nil, "", errors.New("unexpected Office web service")
	}
	db, ok := services["db"].(map[string]any)
	if !ok || db["container_name"] != "office-db" {
		return nil, "", errors.New("unexpected Office database service")
	}
	raw, err = output("docker", "inspect", "--format", "{{json .Mounts}}", "office-web")
	if err != nil {
		return nil, "", err
	}
	var mounts []struct{ Destination, Name, Type string }
	if err = json.Unmarshal(raw, &mounts); err != nil {
		return nil, "", err
	}
	volume := ""
	for _, m := range mounts {
		if m.Destination == "/app/storage" && m.Type == "volume" {
			volume = m.Name
		}
	}
	if !regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]*$`).MatchString(volume) {
		return nil, "", errors.New("a named persistent Office storage volume is required")
	}
	// Preserve proxy networks attached manually outside Compose as well.
	raw, err = output("docker", "inspect", "--format", "{{json .NetworkSettings.Networks}}", "office-web")
	if err != nil {
		return nil, "", err
	}
	var attached map[string]any
	if err = json.Unmarshal(raw, &attached); err != nil {
		return nil, "", err
	}
	networks, _ := model["networks"].(map[string]any)
	if networks == nil {
		networks = map[string]any{}
	}
	appNetworks, _ := app["networks"].(map[string]any)
	if appNetworks == nil {
		appNetworks = map[string]any{}
	}
	for name := range attached {
		found := false
		for key, settings := range networks {
			definition, ok := settings.(map[string]any)
			if ok && definition["name"] == name {
				appNetworks[key] = appNetworks[key]
				found = true
				break
			}
		}
		if !found {
			key := "helper_proxy_" + digest([]byte(name))[:12]
			networks[key] = map[string]any{"name": name, "external": true}
			appNetworks[key] = nil
		}
	}
	app["networks"] = appNetworks
	model["networks"] = networks
	return model, volume, nil
}
func existingImageModel(model map[string]any, image string) error {
	services := model["services"].(map[string]any)
	for _, name := range []string{"app", "terminal-gateway", "outbound-worker", "scheduler", "bale-bot"} {
		if value, ok := services[name]; ok {
			service := value.(map[string]any)
			service["image"] = image
			service["pull_policy"] = "never"
			delete(service, "build")
		}
	}
	app := services["app"].(map[string]any)
	migration := map[string]any{}
	for k, v := range app {
		migration[k] = v
	}
	for _, key := range []string{"container_name", "ports", "healthcheck", "depends_on", "profiles"} {
		delete(migration, key)
	}
	migration["restart"] = "no"
	migration["command"] = []string{"php", "artisan", "migrate", "--force"}
	migration["entrypoint"] = []string{}
	services["office-helper-migrate"] = migration
	return nil
}
func (c *Client) updateExisting(state State, h Hardware, job *UpdateJob, profile *ExistingOffice) (err error) {
	priorMaintenance := job.Maintenance
	if err = c.updateStage(job, "download", "دریافت و بررسی امضای بسته…", priorMaintenance); err != nil {
		return err
	}
	path, err := c.bundle(h, state.Package)
	if err != nil {
		return err
	}
	stage, err := os.MkdirTemp(c.Root, ".update-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	image := ""
	if state.Package.Manifest.Format == "office-source-v1" {
		if err = c.updateStage(job, "build", "ساخت و بررسی نسخهٔ جدید؛ ممکن است چند دقیقه زمان ببرد…", priorMaintenance); err != nil {
			return err
		}
		logPath := filepath.Join(c.Root, "agent/private", "build-"+state.Package.SHA[:24]+".log")
		image, err = buildSourceImage(path, stage, state.Package, SourceBuildOptions{LogPath: logPath, DockerConfigDir: filepath.Join(c.Root, "agent/private/docker"), Report: func(progress int, step string) {
			if progress > job.Progress {
				job.Progress = progress
			}
			job.Message = "ساخت و بررسی نسخهٔ جدید؛ ممکن است چند دقیقه زمان ببرد…"
			if step != "" {
				job.Message += "\nمرحلهٔ ساخت: " + errorSummary(step, 600)
			}
			_ = c.saveJob(*job)
		}})
		if err != nil {
			return err
		}
	} else if err = extractBundle(path, stage, state.Package.Manifest); err != nil {
		return err
	}
	for _, im := range state.Package.Manifest.Images {
		if im.Role == "app" {
			if _, err = output("docker", "load", "-i", filepath.Join(stage, im.Archive)); err != nil {
				return err
			}
			raw, e := output("docker", "image", "inspect", "--format", "{{.Id}}", im.Ref)
			if e != nil {
				return e
			}
			if strings.TrimSpace(string(raw)) != im.ID {
				return errors.New("image ID differs from signed manifest")
			}
			image = im.ID
		}
	}
	if image == "" {
		return errors.New("application image is missing")
	}
	if state.Package.Manifest.Format != "office-source-v1" {
		if err = validateOfficeWebConfig(image); err != nil {
			return err
		}
	}
	model, volume, err := c.existingModel(profile)
	if err != nil {
		return err
	}
	backup := filepath.Join(c.Root, "backups", job.ID)
	if err = atomicJSON(filepath.Join(backup, "compose-before.json"), model, 0600); err != nil {
		return err
	}
	if err = existingImageModel(model, image); err != nil {
		return err
	}
	active := filepath.Join(c.Root, "agent/private/active-compose.json")
	candidate := filepath.Join(c.Root, "agent/private/update-"+job.ID+".json")
	if err = atomicJSON(candidate, model, 0600); err != nil {
		return err
	}
	compose := func(args ...string) error {
		_, e := output("docker", append([]string{"compose", "--project-name", profile.Project, "--project-directory", c.Root, "-f", candidate}, args...)...)
		return e
	}
	if err = compose("config", "--quiet"); err != nil {
		return err
	}
	if err = c.updateStage(job, "backup", "پشتیبان‌گیری از دیتابیس و فایل‌ها…", true); err != nil {
		return err
	}
	if err = c.snapshotStorage(backup, image, volume); err != nil {
		job.Maintenance = priorMaintenance
		return err
	}
	// Restore only the web endpoint for progress/errors. Business requests stay
	// in maintenance through the read-only update state; workers remain paused.
	_ = run(nil, "docker", "start", "office-web")
	migrated := false
	defer func() {
		if err != nil {
			if !migrated {
				job.Maintenance = priorMaintenance
			}
			_ = run(nil, "docker", "start", "office-web")
		}
	}()
	if err = c.updateStage(job, "migration", "اعمال تغییرات دیتابیس…", true); err != nil {
		return err
	}
	migrated = true
	if err = compose("run", "--rm", "--no-deps", "-T", "office-helper-migrate"); err != nil {
		return err
	}
	if err = c.updateStage(job, "services", "راه‌اندازی نسخهٔ جدید…", true); err != nil {
		return err
	}
	// DB, Redis and existing persistent volumes are deliberately not recreated.
	if err = compose("up", "-d", "--no-deps", "--no-build", "--pull", "never", "--wait", "--wait-timeout", "300", "app"); err != nil {
		return err
	}
	if err = c.updateStage(job, "health", "بررسی سلامت نسخهٔ نصب‌شده…", true); err != nil {
		return err
	}
	installed, e := c.existingHealth()
	if e != nil {
		return e
	}
	if installed != state.Package.Version {
		return errors.New("installed version does not match the authorized release")
	}
	if err = atomicJSON(active, model, 0600); err != nil {
		return err
	}
	services := model["services"].(map[string]any)
	names := []string{}
	for _, name := range []string{"terminal-gateway", "outbound-worker", "scheduler", "bale-bot"} {
		if _, ok := services[name]; ok {
			names = append(names, name)
		}
	}
	if len(names) > 0 {
		if err = compose(append([]string{"up", "-d", "--no-deps", "--no-build", "--pull", "never"}, names...)...); err != nil {
			return err
		}
	}
	job.Maintenance = false
	if err = c.updateStage(job, "confirmation", "ثبت نصب موفق در مرکز…", false); err != nil {
		return err
	}
	return c.confirmDeployment(state.Package, h)
}
