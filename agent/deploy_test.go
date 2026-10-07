package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestFailedFreshConfigurationCanResumeWithoutLosingAdminCredentials(t *testing.T) {
	root := t.TempDir()
	bin := filepath.Join(root, "bin")
	if e := os.Mkdir(bin, 0700); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(filepath.Join(bin, "docker"), []byte("#!/bin/sh\nexit 1\n"), 0700); e != nil {
		t.Fatal(e)
	}
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
	c := Client{Root: root, Identity: Identity{Control: strings.Repeat("a", 64)}}
	s := State{Deployment: Deployment{URL: "https://customer.example"}}
	if e := c.configure(&s, ""); e == nil {
		t.Fatal("missing administrator accepted")
	}
	if _, e := os.Stat(filepath.Join(root, ".env")); !os.IsNotExist(e) {
		t.Fatal("failed setup left a non-resumable environment")
	}
	s.Deployment.Email = "admin@customer.example"
	if e := c.configure(&s, ""); e != nil {
		t.Fatal(e)
	}
	before, e := os.ReadFile(filepath.Join(root, "initial-admin.json"))
	if e != nil {
		t.Fatal(e)
	}
	if e = c.configure(&s, ""); e != nil {
		t.Fatal(e)
	}
	after, e := os.ReadFile(filepath.Join(root, "initial-admin.json"))
	if e != nil || !bytes.Equal(before, after) {
		t.Fatal("resume replaced the initial administrator secret")
	}
}

func TestGeneratedComposePreservesDataAndUsesLoadedImages(t *testing.T) {
	root := t.TempDir()
	c := Client{Root: root}
	s := State{Deployment: Deployment{Bind: "127.0.0.1", Port: 8080}}
	for _, role := range []string{"app", "db", "redis", "rdp-web", "rdp-core"} {
		s.Package.Manifest.Images = append(s.Package.Manifest.Images, Image{Role: role, Ref: "test/" + role + ":1"})
	}
	if e := c.writeCompose(s); e != nil {
		t.Fatal(e)
	}
	raw, e := os.ReadFile(filepath.Join(root, "compose.json"))
	if e != nil {
		t.Fatal(e)
	}
	var model map[string]any
	if e = json.Unmarshal(raw, &model); e != nil {
		t.Fatal(e)
	}
	volumes := model["volumes"].(map[string]any)
	if volumes["db_data"].(map[string]any)["name"] != "leave-panel_db_data" {
		t.Fatal("database volume changed")
	}
	if volumes["app_storage"].(map[string]any)["name"] != "leave-panel_app_storage" {
		t.Fatal("customer storage volume changed")
	}
	services := model["services"].(map[string]any)
	for _, name := range []string{"app", "db", "redis", "rdp-web", "rdp-guacd", "terminal-gateway", "outbound-worker", "scheduler", "bale-bot", "migrate"} {
		service := services[name].(map[string]any)
		if service["pull_policy"] != "never" {
			t.Fatal("customer image pulling bypasses Central")
		}
		if _, ok := service["build"]; ok {
			t.Fatal("source builds must not run at customers")
		}
	}
	if path := os.Getenv("OFFICE_TEST_MODEL"); path != "" {
		if e = atomicWrite(path, raw, 0600); e != nil {
			t.Fatal(e)
		}
	}
}
