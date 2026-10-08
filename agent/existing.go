package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Connecting a running Office never generates a Compose model or environment,
// loads an image, migrates a database, or recreates any application container.
type ExistingOffice struct {
	Container string `json:"container"`
	Project   string `json:"project"`
}

func existingOfficeDetected() bool {
	_, err := output("docker", "inspect", "--type", "container", "--format", "{{.Id}}", "office-web")
	return err == nil
}

func (c *Client) existingOffice() (*ExistingOffice, error) {
	raw, err := os.ReadFile(filepath.Join(c.Root, "agent/private/existing.json"))
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var profile ExistingOffice
	if err = json.Unmarshal(raw, &profile); err != nil {
		return nil, err
	}
	if profile.Container != "office-web" || !regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]*$`).MatchString(profile.Project) {
		return nil, errors.New("invalid existing Office identity")
	}
	return &profile, nil
}

func (c *Client) prepareExisting() error {
	raw, err := output("docker", "inspect", "--type", "container", "--format", "{{json .Mounts}}", "office-web")
	if err != nil {
		return errors.New("Office is not running; update and start the existing Office first")
	}
	var mounts []struct {
		Source, Destination string
		RW                  bool
	}
	if err = json.Unmarshal(raw, &mounts); err != nil {
		return err
	}
	found := map[string]bool{}
	for _, mount := range mounts {
		for _, name := range []string{"public", "control"} {
			if mount.Destination == "/run/office-agent/"+name {
				if filepath.Clean(mount.Source) != filepath.Join(c.Root, "agent", name) || mount.RW {
					return fmt.Errorf("helper mount %s must be read-only and use %s", name, c.Root)
				}
				found[name] = true
			}
		}
	}
	if !found["public"] || !found["control"] {
		return errors.New("update your existing Office to a helper-capable release first, then run this same command again; no data was changed")
	}
	raw, err = output("docker", "inspect", "--format", `{{index .Config.Labels "com.docker.compose.project"}}`, "office-web")
	if err != nil {
		return err
	}
	profile := ExistingOffice{Container: "office-web", Project: strings.TrimSpace(string(raw))}
	if !regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]*$`).MatchString(profile.Project) {
		return errors.New("Office Compose project identity is missing")
	}
	if err = atomicJSON(filepath.Join(c.Root, "agent/private/existing.json"), profile, 0600); err != nil {
		return err
	}
	_, err = c.existingHealth()
	return err
}

func (c *Client) existingHealth() (string, error) {
	raw, err := output("docker", "exec", "office-web", "php", "artisan", "office-agent:health", "--json")
	if err != nil {
		return "", err
	}
	var health struct{ Status, Version string }
	if json.Unmarshal(raw, &health) != nil || health.Status != "ok" || !regexp.MustCompile(`^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$`).MatchString(health.Version) {
		return "", errors.New("existing Office health check failed; update Office before connecting the helper")
	}
	return health.Version, nil
}

func (c *Client) connectExisting(code string, h Hardware) error {
	installedVersion, err := c.existingHealth()
	if err != nil {
		return err
	}
	var state State
	if c.Identity.Installation == "" {
		if len(code) < 10 || len(code) > 40 {
			return errors.New("enter the one-use connection code issued by Central")
		}
		state, err = c.activate(code, "begin", h)
	} else {
		state, err = c.poll(h)
	}
	if err != nil {
		return err
	}
	if state.Access == "locked" {
		return errors.New("Office is locked; enter a new license at /license")
	}
	if state.ActivationMode != "attach_once" {
		return errors.New("use a connection license for existing Office")
	}
	if !state.Completed {
		var response struct {
			Signed Envelope `json:"signed_state"`
		}
		if err = c.post("complete", map[string]any{"hardware": h, "application_version": installedVersion, "health_ok": true}, &response); err != nil {
			return err
		}
		if _, err = c.accept(response.Signed, h); err != nil {
			return err
		}
	}
	// Enable enforcement only after healthy local setup and authoritative
	// completion. A failed connection leaves the running Office usable.
	return atomicWrite(filepath.Join(c.Root, "agent/public/enabled"), []byte("office-helper/v2\n"), 0644)
}

func (c *Client) waitControl() error {
	for i := 0; i < 100; i++ {
		if connection, err := net.DialTimeout("unix", filepath.Join(c.Root, "agent/control/control.sock"), 100*time.Millisecond); err == nil {
			connection.Close()
			return nil
		}
		time.Sleep(100 * time.Millisecond)
	}
	return errors.New("helper service did not start; inspect journalctl -u office-agent")
}
