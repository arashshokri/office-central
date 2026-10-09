package main

import (
	"archive/zip"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

// Only the centrally inspected, signed source package is accepted. Extraction
// uses a disposable directory, never the customer's working tree or database.
func extractOfficeSource(path, target string, m Manifest) error {
	if err := validateManifest(m); err != nil || m.Format != "office-source-v1" {
		return errors.New("invalid Office source manifest")
	}
	archive, err := zip.OpenReader(path)
	if err != nil {
		return err
	}
	defer archive.Close()
	if len(archive.File) < 1 || len(archive.File) > 100000 {
		return errors.New("source ZIP file count exceeds limit")
	}
	seen := map[string]bool{}
	var expanded uint64
	for _, f := range archive.File {
		name := strings.TrimSuffix(f.Name, "/")
		parts := strings.Split(name, "/")
		if name == "" || strings.ContainsAny(name, "\\:\x00") || strings.HasPrefix(name, "/") || seen[strings.ToLower(name)] || (!f.Mode().IsRegular() && !f.Mode().IsDir()) {
			return errors.New("unsafe or duplicate source ZIP entry")
		}
		for _, part := range parts {
			if part == "" || part == "." || part == ".." || part == ".git" || part == "node_modules" || part == ".env" || part == ".env.docker" || part == ".env.local" || part == "identity.json" || part == "device.key" || strings.HasPrefix(part, "database.sqlite") {
				return errors.New("source ZIP contains an unsafe path, local database or credentials")
			}
		}
		seen[strings.ToLower(name)] = true
		if m.SourceRoot != "" && f.Name == m.SourceRoot && f.Mode().IsDir() {
			continue
		}
		if !strings.HasPrefix(f.Name, m.SourceRoot) {
			return errors.New("source ZIP entry is outside Office root")
		}
		relative := strings.TrimPrefix(f.Name, m.SourceRoot)
		if relative == "vendor/" || relative == "vendor" || strings.HasPrefix(relative, "vendor/") {
			return errors.New("source ZIP contains local Composer dependencies")
		}
		if relative == "" {
			return errors.New("invalid source root")
		}
		if f.UncompressedSize64 > (4<<30)-expanded {
			return errors.New("expanded source exceeds 4 GiB")
		}
		expanded += f.UncompressedSize64
		dest := filepath.Join(target, filepath.FromSlash(relative))
		if f.Mode().IsDir() {
			if err = os.MkdirAll(dest, 0755); err != nil {
				return err
			}
			continue
		}
		if err = os.MkdirAll(filepath.Dir(dest), 0755); err != nil {
			return err
		}
		stream, err := f.Open()
		if err != nil {
			return err
		}
		mode := os.FileMode(0644)
		if f.Mode().Perm()&0111 != 0 {
			mode = 0755
		}
		out, err := os.OpenFile(dest, os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode)
		if err != nil {
			stream.Close()
			return err
		}
		n, copyErr := io.Copy(out, io.LimitReader(stream, int64(f.UncompressedSize64)+1))
		stream.Close()
		closeErr := out.Close()
		if copyErr != nil {
			return copyErr
		}
		if closeErr != nil {
			return closeErr
		}
		if uint64(n) != f.UncompressedSize64 {
			return errors.New("expanded source size mismatch")
		}
	}
	for _, name := range []string{"artisan", "Dockerfile", "VERSION", "composer.json", "composer.lock", "bootstrap/providers.php", "docker/Caddyfile", "app/Providers/OfficeLicenseServiceProvider.php"} {
		info, err := os.Stat(filepath.Join(target, name))
		if err != nil || !info.Mode().IsRegular() || info.Size() < 1 || info.Size() > 5<<20 {
			return fmt.Errorf("Office source has an invalid %s", name)
		}
	}
	installed, err := os.ReadFile(filepath.Join(target, "VERSION"))
	if err != nil || strings.TrimSpace(string(installed)) != m.Version {
		return errors.New("source VERSION differs from signed release")
	}
	dockerfile, err := os.ReadFile(filepath.Join(target, "Dockerfile"))
	if err != nil || !strings.Contains(string(dockerfile), "FROM production AS managed") {
		return errors.New("managed Office build target is missing")
	}
	providers, err := os.ReadFile(filepath.Join(target, "bootstrap/providers.php"))
	if err != nil || !strings.Contains(string(providers), "OfficeLicenseServiceProvider") {
		return errors.New("Office helper provider is missing")
	}
	return nil
}

func buildSourceImage(path, stage string, p Package) (string, error) {
	if shaFile(path) != p.SHA {
		return "", errors.New("source checksum differs from signed release")
	}
	if err := extractOfficeSource(path, stage, p.Manifest); err != nil {
		return "", err
	}
	image := "office-source:" + p.SHA[:24]
	// The exact published Dockerfile builds in isolation; no customer secrets,
	// data volumes or Docker socket are mounted into build steps.
	if _, err := output("docker", "build", "--platform", "linux/"+runtime.GOARCH, "--target", "managed", "--build-arg", "APP_RELEASE_VERSION="+p.Version, "-t", image, stage); err != nil {
		return "", fmt.Errorf("ساخت بستهٔ Office ناموفق بود؛ اتصال به مخازن Docker و وابستگی‌ها را بررسی کنید: %w", err)
	}
	raw, err := output("docker", "image", "inspect", "--format", "{{.Id}}", image)
	if err != nil {
		return "", err
	}
	id := strings.TrimSpace(string(raw))
	if !regexp.MustCompile(`^sha256:[a-f0-9]{64}$`).MatchString(id) {
		return "", errors.New("built image ID is invalid")
	}
	raw, err = output("docker", "run", "--rm", "--network", "none", "--entrypoint", "cat", id, "/app/VERSION")
	if err != nil || strings.TrimSpace(string(raw)) != p.Version {
		return "", errors.New("built Office image version does not match the authorized update")
	}
	if _, err = output("docker", "run", "--rm", "--network", "none", "--entrypoint", "php", id, "-r", "if(!is_file('/app/office-managed') || !function_exists('sodium_crypto_sign_verify_detached')) exit(1); require '/app/vendor/autoload.php'; require '/app/bootstrap/app.php';"); err != nil {
		return "", fmt.Errorf("built Office helper health check failed: %w", err)
	}
	return id, nil
}

// Fresh source installs use the same supported infrastructure versions as the
// Office package builder. Existing deployments use updateExisting and retain
// their database images, credentials and volumes.
func prepareSourceRuntime(path, stage string, p Package) ([]Image, error) {
	app, err := buildSourceImage(path, stage, p)
	if err != nil {
		return nil, err
	}
	images := []Image{{Role: "app", Ref: app, ID: app}}
	for _, item := range []struct{ role, ref string }{
		{"db", "mariadb:10.11.18"}, {"redis", "redis:7.4.5-alpine"},
		{"rdp-web", "guacamole/guacamole:1.6.0"}, {"rdp-core", "guacamole/guacd:1.6.0"},
	} {
		if _, err = output("docker", "pull", "--platform", "linux/"+runtime.GOARCH, item.ref); err != nil {
			return nil, fmt.Errorf("دریافت سرویس %s ناموفق بود؛ اتصال به مخزن Docker را بررسی کنید: %w", item.role, err)
		}
		raw, err := output("docker", "image", "inspect", "--format", "{{.Id}}", item.ref)
		if err != nil {
			return nil, err
		}
		id := strings.TrimSpace(string(raw))
		if !regexp.MustCompile(`^sha256:[a-f0-9]{64}$`).MatchString(id) {
			return nil, errors.New("invalid infrastructure image ID")
		}
		images = append(images, Image{Role: item.role, Ref: id, ID: id})
	}
	return images, nil
}
