package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"regexp"
	"runtime"
	"strings"
	"time"
)

func run(input io.Reader, name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Stdin = input
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	if e := cmd.Run(); e != nil {
		return fmt.Errorf("%s failed: %w", name, e)
	}
	return nil
}
func output(name string, args ...string) ([]byte, error) {
	raw, e := exec.Command(name, args...).CombinedOutput()
	if e != nil {
		return nil, fmt.Errorf("%s failed: %s", name, raw)
	}
	return raw, nil
}
func (c *Client) compose(args ...string) error { return c.composeInput(nil, args...) }
func (c *Client) composeInput(input io.Reader, args ...string) error {
	base := []string{"compose", "--project-name", "leave-panel", "--env-file", filepath.Join(c.Root, ".env"), "-f", filepath.Join(c.Root, "compose.json")}
	return run(input, "docker", append(base, args...)...)
}
func validateManifest(m Manifest) error {
	if m.Format != "office-runtime-v1" || m.Product != "office" || m.Protection != "ioncube" || m.Architecture != runtime.GOARCH || len(m.Images) != 5 {
		return errors.New("incompatible or unprotected runtime manifest")
	}
	roles := map[string]bool{}
	for _, image := range m.Images {
		allowed := map[string]bool{"app": true, "db": true, "redis": true, "rdp-web": true, "rdp-core": true}
		if !allowed[image.Role] || roles[image.Role] || image.Archive != "images/"+image.Role+".tar" || len(image.SHA) != 64 || len(image.ID) != 71 || !strings.HasPrefix(image.ID, "sha256:") {
			return errors.New("invalid image manifest")
		}
		if _, e := hex.DecodeString(image.SHA); e != nil {
			return e
		}
		if _, e := hex.DecodeString(image.ID[7:]); e != nil {
			return e
		}
		roles[image.Role] = true
	}
	return nil
}
func shaFile(path string) string {
	f, e := os.Open(path)
	if e != nil {
		return ""
	}
	defer f.Close()
	h := sha256.New()
	if _, e = io.Copy(h, f); e != nil {
		return ""
	}
	return hex.EncodeToString(h.Sum(nil))
}
func (c *Client) bundle(h Hardware, p Package) (string, error) {
	if e := validateManifest(p.Manifest); e != nil {
		return "", e
	}
	if p.Manifest.Version != p.Version || p.Size < 1 || p.Size > 20<<30 {
		return "", errors.New("invalid package size or version")
	}
	if !regexp.MustCompile("^[a-f0-9]{64}$").MatchString(p.SHA) {
		return "", errors.New("invalid package checksum")
	}
	cache := filepath.Join(c.Root, "cache", p.SHA+".zip")
	if shaFile(cache) == p.SHA {
		return cache, nil
	}
	var response struct {
		Signed Envelope `json:"signed_download"`
	}
	if e := c.post("download", map[string]any{"hardware": h, "release_id": p.Release}, &response); e != nil {
		return "", e
	}
	var d struct {
		Kind         string `json:"kind"`
		Protocol     int    `json:"protocol"`
		Installation string `json:"installation_id"`
		Hardware     string `json:"hardware_fingerprint"`
		Release      string `json:"release_id"`
		SHA          string `json:"sha256"`
		Size         int64  `json:"size"`
		URL          string `json:"url"`
	}
	if e := verify(c.Identity.Trust, response.Signed, &d); e != nil {
		return "", e
	}
	fp, _ := h.fingerprint()
	origin, _ := url.Parse(c.Identity.Endpoint)
	u, e := url.Parse(d.URL)
	if e != nil || u.Scheme != "https" || u.Host != origin.Host || u.User != nil || d.Kind != "download" || d.Protocol != 2 || d.Installation != c.Identity.Installation || d.Hardware != fp || d.Release != p.Release || d.SHA != p.SHA || d.Size != p.Size {
		return "", errors.New("download authorization rejected")
	}
	if e = os.MkdirAll(filepath.Dir(cache), 0700); e != nil {
		return "", e
	}
	// Packages may be large; retain connection timeouts but allow the transfer.
	client := *c.HTTP
	client.Timeout = 2 * time.Hour
	res, e := client.Get(d.URL)
	if e != nil {
		return "", e
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return "", fmt.Errorf("package download HTTP %d", res.StatusCode)
	}
	file, e := os.CreateTemp(filepath.Dir(cache), ".download-")
	if e != nil {
		return "", e
	}
	defer os.Remove(file.Name())
	defer file.Close()
	hash := sha256.New()
	n, e := io.Copy(io.MultiWriter(file, hash), io.LimitReader(res.Body, p.Size+1))
	if e != nil {
		return "", e
	}
	if n != p.Size || hex.EncodeToString(hash.Sum(nil)) != p.SHA {
		return "", errors.New("package checksum or size mismatch")
	}
	if e = file.Sync(); e != nil {
		return "", e
	}
	if e = file.Close(); e != nil {
		return "", e
	}
	return cache, os.Rename(file.Name(), cache)
}
func extractBundle(path, target string, m Manifest) error {
	archive, e := zip.OpenReader(path)
	if e != nil {
		return e
	}
	defer archive.Close()
	allowed := map[string]string{"manifest.json": ""}
	for _, im := range m.Images {
		allowed[im.Archive] = im.SHA
	}
	if len(archive.File) != len(allowed) {
		return errors.New("unexpected bundle entry count")
	}
	seen := map[string]bool{}
	var expanded uint64
	if e = os.MkdirAll(target, 0700); e != nil {
		return e
	}
	for _, f := range archive.File {
		checksum, ok := allowed[f.Name]
		if !ok || seen[f.Name] || !f.Mode().IsRegular() {
			return errors.New("unsafe, symlink or duplicate bundle entry")
		}
		seen[f.Name] = true
		if f.UncompressedSize64 > (20<<30)-expanded {
			return errors.New("expanded bundle exceeds limit")
		}
		expanded += f.UncompressedSize64
		stream, e := f.Open()
		if e != nil {
			return e
		}
		if f.Name == "manifest.json" {
			raw, e := io.ReadAll(io.LimitReader(stream, 65537))
			stream.Close()
			if e != nil {
				return e
			}
			if len(raw) > 65536 {
				return errors.New("manifest too large")
			}
			var actual Manifest
			if e = json.Unmarshal(raw, &actual); e != nil {
				return e
			}
			if !reflect.DeepEqual(actual, m) {
				return errors.New("bundle manifest differs from signed release")
			}
			continue
		}
		dest := filepath.Join(target, f.Name)
		if e = os.MkdirAll(filepath.Dir(dest), 0700); e != nil {
			stream.Close()
			return e
		}
		out, e := os.OpenFile(dest, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
		if e != nil {
			stream.Close()
			return e
		}
		hash := sha256.New()
		written, copyErr := io.Copy(io.MultiWriter(out, hash), io.LimitReader(stream, int64(f.UncompressedSize64)+1))
		e = copyErr
		stream.Close()
		closeErr := out.Close()
		if e != nil {
			return e
		}
		if closeErr != nil {
			return closeErr
		}
		if uint64(written) != f.UncompressedSize64 {
			return errors.New("expanded image size mismatch")
		}
		if hex.EncodeToString(hash.Sum(nil)) != checksum {
			return errors.New("image checksum mismatch")
		}
	}
	return nil
}
func (c *Client) configure(s *State, adopt string) error {
	envPath := filepath.Join(c.Root, ".env")
	old, e := os.ReadFile(envPath)
	if e != nil && !os.IsNotExist(e) {
		return e
	}
	fresh := len(old) == 0
	if fresh && adopt != "" {
		old, e = os.ReadFile(adopt)
		if e != nil {
			return e
		}
		fresh = false
	}
	if len(old) == 0 {
		if _, e := output("docker", "volume", "inspect", "leave-panel_db_data"); e == nil {
			return errors.New("existing database: rerun with --adopt-env /path/to/original/.env.docker")
		}
		key, _ := hex.DecodeString(randomHex(32))
		old = []byte("APP_NAME=Office\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:" + base64.StdEncoding.EncodeToString(key) + "\nDB_CONNECTION=mysql\nDB_HOST=db\nDB_PORT=3306\nDB_DATABASE=workflow\nDB_USERNAME=workflow_user\nDB_PASSWORD=" + randomHex(24) + "\nMARIADB_ROOT_PASSWORD=" + randomHex(24) + "\nMARIADB_AUTO_UPGRADE=1\nREDIS_CLIENT=predis\nREDIS_HOST=redis\nREDIS_PORT=6379\nREDIS_DB=0\nREDIS_CACHE_DB=1\nQUEUE_CONNECTION=database\nCACHE_STORE=redis\nSESSION_DRIVER=redis\nSESSION_SECURE_COOKIE=true\nSESSION_ENCRYPT=true\nAPP_TIMEZONE=Asia/Tehran\nSSM_TERMINAL_SERVICE_SECRET=" + randomHex(32) + "\n")
	}
	d := &s.Deployment
	u, e := url.Parse(d.URL)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil {
		return errors.New("license must specify a valid HTTPS customer URL")
	}
	if d.Bind == "" {
		d.Bind = "127.0.0.1"
	}
	if net.ParseIP(d.Bind) == nil {
		return errors.New("invalid bind IP")
	}
	if d.Port == 0 {
		d.Port = 8080
	}
	if d.Port < 1024 || d.Port > 65535 {
		return errors.New("invalid HTTP port")
	}
	overrides := map[string]string{"APP_URL": d.URL, "APP_BIND_IP": d.Bind, "APP_PORT": fmt.Sprint(d.Port), "OFFICE_LICENSE_ENABLED": "true", "OFFICE_AGENT_CONTROL_TOKEN": c.Identity.Control, "OFFICE_AGENT_STATE_DIR": "/run/office-agent/public", "TRUSTED_PROXIES": "127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16", "OFFICE_AGENT_SOCKET": "/run/office-agent/control/control.sock"}
	lines := strings.Split(string(old), "\n")
	kept := []string{}
	for _, line := range lines {
		key := strings.TrimSpace(strings.SplitN(line, "=", 2)[0])
		if _, ok := overrides[key]; !ok {
			kept = append(kept, line)
		}
	}
	for key, value := range overrides {
		if strings.ContainsAny(value, "\r\n\"$") {
			return errors.New("unsafe deployment value")
		}
		kept = append(kept, key+"=\""+value+"\"")
	}
	if fresh {
		if d.Email == "" {
			return errors.New("initial administrator email is required")
		}
		if e = atomicJSON(filepath.Join(c.Root, "initial-admin.json"), map[string]string{"email": d.Email, "name": d.Name, "password": randomHex(12)}, 0600); e != nil {
			return e
		}
	}
	// Persist initial credentials before the environment. After a crash,
	// an existing environment must always have its recoverable admin secret.
	if e = atomicWrite(envPath, []byte(strings.Join(kept, "\n")+"\n"), 0600); e != nil {
		return e
	}
	return c.writeCompose(*s)
}
func (c *Client) writeCompose(s State) error {
	images := map[string]string{}
	for _, im := range s.Package.Manifest.Images {
		images[im.Role] = im.Ref
	}
	shared := map[string]any{"image": images["app"], "pull_policy": "never", "restart": "unless-stopped", "env_file": []string{filepath.Join(c.Root, ".env")}, "volumes": []string{"app_storage:/app/storage", filepath.Join(c.Root, "agent/public") + ":/run/office-agent/public:ro", filepath.Join(c.Root, "agent/control") + ":/run/office-agent/control:ro", "/sys/class/dmi/id/product_uuid:/run/office-agent/product_uuid:ro", "/etc/machine-id:/run/office-agent/machine-id:ro"}, "networks": []string{"leave-panel-network"}}
	service := func(command []string) map[string]any {
		x := map[string]any{}
		for k, v := range shared {
			x[k] = v
		}
		if command != nil {
			x["command"] = command
		}
		return x
	}
	app := service(nil)
	app["container_name"] = "office-web"
	app["ports"] = []string{fmt.Sprintf("%s:%d:8080", s.Deployment.Bind, s.Deployment.Port)}
	app["depends_on"] = map[string]any{"db": map[string]string{"condition": "service_healthy"}, "redis": map[string]string{"condition": "service_healthy"}}
	health := func(cmd []string) map[string]any {
		return map[string]any{"test": cmd, "interval": "10s", "timeout": "5s", "retries": 30, "start_period": "40s"}
	}
	app["healthcheck"] = health([]string{"CMD-SHELL", "curl -fsS http://127.0.0.1:8080/up >/dev/null"})
	db := map[string]any{"image": images["db"], "pull_policy": "never", "container_name": "office-db", "restart": "unless-stopped", "env_file": []string{filepath.Join(c.Root, ".env")}, "environment": map[string]string{"MARIADB_DATABASE": "${DB_DATABASE}", "MARIADB_USER": "${DB_USERNAME}", "MARIADB_PASSWORD": "${DB_PASSWORD}", "MARIADB_ROOT_PASSWORD": "${MARIADB_ROOT_PASSWORD}"}, "volumes": []string{"db_data:/var/lib/mysql"}, "healthcheck": health([]string{"CMD", "healthcheck.sh", "--connect", "--innodb_initialized"}), "networks": []string{"leave-panel-network"}}
	redis := map[string]any{"image": images["redis"], "pull_policy": "never", "container_name": "office-cache", "restart": "unless-stopped", "healthcheck": health([]string{"CMD", "redis-cli", "ping"}), "networks": []string{"leave-panel-network"}}
	terminal := service([]string{"node", "/gateway/src/index.mjs"})
	terminal["container_name"] = "office-ssh"
	terminal["environment"] = map[string]string{"LARAVEL_BACKEND_URL": "http://app:8080/api/internal/server-access/terminal", "SSM_TERMINAL_ALLOWED_ORIGINS": "${APP_URL}", "SSM_TERMINAL_SERVICE_SECRET": "${SSM_TERMINAL_SERVICE_SECRET}", "PORT": "3001", "REDIS_HOST": "redis"}
	queue := service([]string{"php", "artisan", "queue:work", "database", "--queue=outbound", "--sleep=3", "--tries=3", "--timeout=250", "--max-time=3600"})
	queue["container_name"] = "office-queue"
	scheduler := service([]string{"php", "artisan", "schedule:work"})
	scheduler["container_name"] = "office-cron"
	messenger := service([]string{"sh", "/app/docker/run-messenger-bots.sh"})
	messenger["container_name"] = "office-messenger"
	migrate := service([]string{"php", "artisan", "migrate", "--force"})
	migrate["restart"] = "no"
	migrate["profiles"] = []string{"tools"}
	rdp := map[string]any{"image": images["rdp-web"], "pull_policy": "never", "container_name": "office-rdp", "restart": "unless-stopped", "env_file": []string{filepath.Join(c.Root, ".env")}, "entrypoint": []string{"/bin/sh", "-c"}, "command": []string{`set -eu; export JSON_ENABLED=true JSON_TRUST_ALL_CERTS=false; export JSON_SECRET_KEY="$$(printf '%s|office-rdp-json-v1' "$$SSM_TERMINAL_SERVICE_SECRET" | sha256sum | cut -c1-32)"; exec /opt/guacamole/bin/entrypoint.sh`}, "environment": map[string]string{"GUACD_HOSTNAME": "rdp-guacd", "GUACD_PORT": "4822", "WEBAPP_CONTEXT": "rdp-gateway"}, "networks": []string{"leave-panel-network"}}
	guacd := map[string]any{"image": images["rdp-core"], "pull_policy": "never", "container_name": "office-rdp-core", "restart": "unless-stopped", "tmpfs": []string{"/drive:rw,nosuid,nodev,noexec,size=512m,mode=1777"}, "networks": []string{"leave-panel-network"}}
	networks := map[string]any{"leave-panel-network": map[string]any{"name": "leave-panel_leave-panel-network", "driver": "bridge"}}
	if s.Deployment.Proxy != "" {
		if _, e := output("docker", "network", "inspect", s.Deployment.Proxy); e != nil {
			return errors.New("configured customer proxy network does not exist")
		}
		networks["proxy"] = map[string]any{"external": true, "name": s.Deployment.Proxy}
		app["networks"] = map[string]any{"leave-panel-network": map[string]any{"aliases": []string{"leave-panel-app-1"}}, "proxy": map[string]any{"aliases": []string{"office-web", "leave-panel-app-1"}}}
	} else {
		app["networks"] = map[string]any{"leave-panel-network": map[string]any{"aliases": []string{"leave-panel-app-1"}}}
	}
	model := map[string]any{"services": map[string]any{"app": app, "db": db, "redis": redis, "terminal-gateway": terminal, "outbound-worker": queue, "scheduler": scheduler, "bale-bot": messenger, "migrate": migrate, "rdp-web": rdp, "rdp-guacd": guacd}, "volumes": map[string]any{"db_data": map[string]string{"name": "leave-panel_db_data"}, "app_storage": map[string]string{"name": "leave-panel_app_storage"}}, "networks": networks}
	return atomicJSON(filepath.Join(c.Root, "compose.json"), model, 0600)
}
func (c *Client) health(expected ...string) error {
	args := []string{"exec", "-T", "app", "php", "artisan", "office-agent:health"}
	if len(expected) > 0 {
		args = append(args, "--expected-version="+expected[0])
	}
	return c.compose(args...)
}
func (c *Client) deploy(s State, h Hardware, adopt string) error {
	if s.Access == "locked" {
		return fmt.Errorf("installation locked: %s", s.Message)
	}
	if e := ensureDocker(); e != nil {
		return e
	}
	path, e := c.bundle(h, s.Package)
	if e != nil {
		return e
	}
	stage, e := os.MkdirTemp(c.Root, ".runtime-")
	if e != nil {
		return e
	}
	defer os.RemoveAll(stage)
	if e = extractBundle(path, stage, s.Package.Manifest); e != nil {
		return e
	}
	for _, image := range s.Package.Manifest.Images {
		if e = run(nil, "docker", "load", "-i", filepath.Join(stage, image.Archive)); e != nil {
			return e
		}
		raw, e := output("docker", "image", "inspect", "--format", "{{.Id}}", image.Ref)
		if e != nil {
			return e
		}
		if strings.TrimSpace(string(raw)) != image.ID {
			return errors.New("loaded image ID differs from signed package")
		}
	}
	existing := false
	if _, err := output("docker", "volume", "inspect", "leave-panel_db_data"); err == nil {
		existing = true
		if _, err = os.Stat(filepath.Join(c.Root, ".env")); err != nil && adopt == "" {
			return errors.New("existing data requires --adopt-env; original database credentials and APP_KEY must be retained")
		}
	}
	backupDir := filepath.Join(c.Root, "backups", fmt.Sprint(time.Now().UnixNano()))
	if adopt != "" {
		if raw, err := os.ReadFile(adopt); err != nil {
			return err
		} else if err = atomicWrite(filepath.Join(backupDir, ".env"), raw, 0600); err != nil {
			return err
		}
	}
	for _, name := range []string{".env", "compose.json"} {
		if raw, err := os.ReadFile(filepath.Join(c.Root, name)); err == nil {
			if err = atomicWrite(filepath.Join(backupDir, name), raw, 0600); err != nil {
				return err
			}
		}
	}
	if e = c.configure(&s, adopt); e != nil {
		return e
	}
	if e = os.MkdirAll(filepath.Join(c.Root, "agent/control"), 0755); e != nil {
		return e
	}
	if existing {
		image := ""
		for _, item := range s.Package.Manifest.Images {
			if item.Role == "app" {
				image = item.Ref
			}
		}
		if e = c.snapshot(backupDir, image); e != nil {
			return e
		}
	}
	if e = c.compose("up", "-d", "--wait", "--wait-timeout", "300", "db", "redis"); e != nil {
		return e
	}
	if e = c.compose("run", "--rm", "--no-deps", "migrate"); e != nil {
		return e
	}
	credentials := filepath.Join(c.Root, "initial-admin.json")
	if raw, e := os.ReadFile(credentials); e == nil {
		if e = c.composeInput(bytes.NewReader(raw), "run", "--rm", "--no-deps", "-T", "migrate", "php", "artisan", "office-agent:admin"); e != nil {
			return e
		}
	}
	if e = c.compose("up", "-d", "--remove-orphans", "--wait", "--wait-timeout", "300"); e != nil {
		return e
	}
	if e = c.health(s.Package.Version); e != nil {
		return e
	}
	if len(os.Args) > 1 && os.Args[1] != "daemon" {
		if e = c.installService(false); e != nil {
			return e
		}
	}
	var completion struct {
		Signed Envelope `json:"signed_state"`
	}
	if e = c.post("complete", map[string]any{"hardware": h, "release_id": s.Package.Release, "package_sha256": s.Package.SHA, "application_version": s.Package.Version, "health_ok": true}, &completion); e != nil {
		return e
	}
	if _, e = c.accept(completion.Signed, h); e != nil {
		return e
	}
	fmt.Println("Office installed successfully:", s.Deployment.URL)
	if raw, e := os.ReadFile(credentials); e == nil {
		fmt.Println("Initial administrator credentials (keep private):", string(raw))
		fmt.Println("Saved root-only:", credentials)
	}
	return nil
}
func (c *Client) installService(start bool) error {
	exe, e := os.Executable()
	if e != nil {
		return e
	}
	raw, e := os.ReadFile(exe)
	if e != nil {
		return e
	}
	if e = atomicWrite("/usr/local/bin/office-agent", raw, 0755); e != nil {
		return e
	}
	unit := "[Unit]\nDescription=Office licensing helper\nAfter=network-online.target docker.service\nWants=network-online.target\n[Service]\nType=simple\nExecStart=/usr/local/bin/office-agent daemon --root " + c.Root + "\nRestart=always\nRestartSec=5\nUMask=0077\nNoNewPrivileges=true\nProtectSystem=full\nProtectHome=true\n[Install]\nWantedBy=multi-user.target\n"
	if e = atomicWrite("/etc/systemd/system/office-agent.service", []byte(unit), 0644); e != nil {
		return e
	}
	if e = run(nil, "systemctl", "daemon-reload"); e != nil {
		return e
	}
	if start {
		return run(nil, "systemctl", "enable", "--now", "office-agent.service")
	}
	return run(nil, "systemctl", "enable", "office-agent.service")
}
func ensureDocker() error {
	if _, e := output("docker", "compose", "version"); e == nil {
		if _, e = output("docker", "info"); e == nil {
			return nil
		}
		if e = run(nil, "systemctl", "start", "docker"); e != nil {
			return e
		}
		_, e = output("docker", "info")
		return e
	}
	return errors.New("Docker Engine and Compose v2 required. Run the Docker prerequisites installer, then resume; the code has not been consumed.")
}
