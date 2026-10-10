package main

import (
	"bytes"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func wizardFixture(t *testing.T) *installWizard {
	t.Helper()
	c := &Client{Root: t.TempDir()}
	if err := os.MkdirAll(c.wizardPath(""), 0700); err != nil {
		t.Fatal(err)
	}
	w := newInstallWizard(c, WizardConfig{Host: "setup.example.test", Port: 8443, Token: strings.Repeat("a", 64), Expires: time.Now().Add(time.Hour)}, Hardware{})
	w.state = State{ActivationMode: "installer_once", Access: "provisioning", Deployment: Deployment{URL: "https://office.example.test", Email: "admin@example.test", Name: "Administrator", Bind: "127.0.0.1", Port: 8080}, Package: Package{Version: "3.8.31", Manifest: Manifest{Format: "office-source-v1", Product: "office", Protection: "none", Architecture: "any", Version: "3.8.31"}}}
	w.activate = func(string) (State, error) { return w.state, nil }
	w.poll = func() (State, error) { return w.state, nil }
	w.finish = func() error { return nil }
	return w
}
func wizardRequest(w *installWizard, method, path string, body any, token bool) *httptest.ResponseRecorder {
	raw, _ := json.Marshal(body)
	req := httptest.NewRequest(method, "https://setup.example.test:8443"+path, bytes.NewReader(raw))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Origin", "https://setup.example.test:8443")
	if token {
		req.Header.Set("Authorization", "Bearer "+w.config.Token)
	}
	res := httptest.NewRecorder()
	w.handler().ServeHTTP(res, req)
	return res
}
func wizardInput(w *installWizard) WizardInput {
	return WizardInput{Deployment: w.state.Deployment, Password: "private-test-password", PasswordConfirmation: "private-test-password", Confirm: true, Version: "3.8.31"}
}
func authorizeWizard(t *testing.T, w *installWizard) {
	t.Helper()
	res := wizardRequest(w, "POST", "/api/license", map[string]string{"license": "OFF-TEST-TEST-TEST-TEST"}, true)
	if res.Code != 200 {
		t.Fatal(res.Code, res.Body.String())
	}
}
func waitWizard(t *testing.T, w *installWizard) WizardStatus {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		status := w.snapshot()
		if status.Job.Status != "running" {
			return status
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("wizard never finished")
	return WizardStatus{}
}
func TestWizardRequiresPrivateTokenValidHostOriginAndExpiry(t *testing.T) {
	w := wizardFixture(t)
	if res := wizardRequest(w, "GET", "/api/status", nil, false); res.Code != 401 {
		t.Fatal(res.Code)
	}
	req := httptest.NewRequest("POST", "https://setup.example.test:8443/api/license", strings.NewReader(`{"license":"OFF-TEST-TEST"}`))
	req.Header.Set("Authorization", "Bearer "+w.config.Token)
	req.Header.Set("Origin", "https://evil.test")
	res := httptest.NewRecorder()
	w.handler().ServeHTTP(res, req)
	if res.Code != 403 {
		t.Fatal("cross origin accepted")
	}
	req = httptest.NewRequest("GET", "https://evil.test/api/status", nil)
	req.Header.Set("Authorization", "Bearer "+w.config.Token)
	res = httptest.NewRecorder()
	w.handler().ServeHTTP(res, req)
	if res.Code != 403 {
		t.Fatal("untrusted host accepted")
	}
	w.config.Expires = time.Now().Add(-time.Second)
	if res = wizardRequest(w, "GET", "/api/status", nil, true); res.Code != 401 {
		t.Fatal("expired link accepted")
	}
}
func TestWizardStaticAssetsDoNotExposeIdentityOrSecrets(t *testing.T) {
	w := wizardFixture(t)
	for _, path := range []string{"/", "/setup.css", "/setup.js", "/vazirmatn.woff2"} {
		res := wizardRequest(w, "GET", path, nil, false)
		if res.Code != 200 || strings.Contains(res.Body.String(), w.config.Token) || res.Header().Get("Referrer-Policy") != "no-referrer" {
			t.Fatal(path, res.Code)
		}
	}
	for _, path := range []string{"/agent/private/identity.json", "/../initial-admin.json", "/.env", "/OFL.txt"} {
		if res := wizardRequest(w, "GET", path, nil, true); res.Code != 404 {
			t.Fatal("private file visible", path)
		}
	}
}
func TestWizardValidatesSettingsAndDoesNotStartWithoutExplicitConfirmation(t *testing.T) {
	w := wizardFixture(t)
	if res := wizardRequest(w, "POST", "/api/install", wizardInput(w), true); res.Code != 409 {
		t.Fatal("unlicensed install accepted")
	}
	authorizeWizard(t, w)
	invalid := []WizardInput{}
	input := wizardInput(w)
	input.Confirm = false
	invalid = append(invalid, input)
	input = wizardInput(w)
	input.Version = "3.8.32"
	invalid = append(invalid, input)
	input = wizardInput(w)
	input.Deployment.URL = "https://another.test"
	invalid = append(invalid, input)
	input = wizardInput(w)
	input.Password = "short"
	invalid = append(invalid, input)
	input = wizardInput(w)
	input.Deployment.Email = "invalid"
	invalid = append(invalid, input)
	input = wizardInput(w)
	input.Deployment.Proxy = "network; rm -rf /"
	invalid = append(invalid, input)
	for _, candidate := range invalid {
		if res := wizardRequest(w, "POST", "/api/install", candidate, true); res.Code != 422 {
			t.Fatal(res.Code, res.Body.String())
		}
	}
	if _, err := os.Stat(filepath.Join(w.client.Root, "initial-admin.json")); !os.IsNotExist(err) {
		t.Fatal("rejected setup stored credentials")
	}
}
func TestWizardProgressContinuesAfterBrowserDisconnectAndFinishesAfterHealthAndDaemon(t *testing.T) {
	w := wizardFixture(t)
	authorizeWizard(t, w)
	var runs atomic.Int32
	built, proceed := make(chan struct{}), make(chan struct{})
	w.deploy = func(s State) error {
		runs.Add(1)
		w.report("build", "building", 34)
		close(built)
		<-proceed
		w.report("confirmation", "health passed", 98)
		return nil
	}
	finish, allowFinish := make(chan struct{}), make(chan struct{})
	w.finish = func() error { close(finish); <-allowFinish; return nil }
	first := wizardRequest(w, "POST", "/api/install", wizardInput(w), true)
	if first.Code != 202 {
		t.Fatal(first.Body.String())
	}
	<-built
	for i := 0; i < 4; i++ {
		res := wizardRequest(w, "POST", "/api/install", wizardInput(w), true)
		if res.Code != 202 {
			t.Fatal(res.Code)
		}
	}
	status := wizardRequest(w, "GET", "/api/status", nil, true)
	if strings.Contains(status.Body.String(), "private-test-password") || !strings.Contains(status.Body.String(), `"progress":34`) {
		t.Fatal("unsafe or blocked status", status.Body.String())
	}
	close(proceed)
	<-finish
	if w.snapshot().Job.Progress != 98 || w.snapshot().Job.Status != "running" {
		t.Fatal("claimed success before daemon started")
	}
	close(allowFinish)
	done := waitWizard(t, w)
	if runs.Load() != 1 || done.Job.Status != "success" || done.Job.Progress != 100 {
		t.Fatal(runs.Load(), done)
	}
	if _, err := os.Stat(filepath.Join(w.client.Root, "initial-admin.json")); !os.IsNotExist(err) {
		t.Fatal("password retained after success")
	}
}
func TestWizardFailureRetainsCredentialsAndRetryPreservesDatabaseEnvironment(t *testing.T) {
	w := wizardFixture(t)
	authorizeWizard(t, w)
	w.deploy = func(State) error {
		w.report("services", "starting", 85)
		return errors.New("runtime unhealthy token=private-token")
	}
	if res := wizardRequest(w, "POST", "/api/install", wizardInput(w), true); res.Code != 202 {
		t.Fatal(res.Body.String())
	}
	failed := waitWizard(t, w)
	if failed.Job.Progress != 85 || failed.Job.Status != "error" || strings.Contains(failed.Job.Error, "private-token") {
		t.Fatal(failed)
	}
	credentials, _ := os.ReadFile(filepath.Join(w.client.Root, "initial-admin.json"))
	info, _ := os.Stat(filepath.Join(w.client.Root, "initial-admin.json"))
	if info.Mode().Perm() != 0600 {
		t.Fatal("credentials not private")
	}
	env := []byte("APP_KEY=preserve\nDB_PASSWORD=preserve\n")
	_ = os.WriteFile(filepath.Join(w.client.Root, ".env"), env, 0600)
	w.deploy = func(State) error {
		after, _ := os.ReadFile(filepath.Join(w.client.Root, "initial-admin.json"))
		if !bytes.Equal(after, credentials) {
			t.Error("retry reset admin secret")
		}
		after, _ = os.ReadFile(filepath.Join(w.client.Root, ".env"))
		if !bytes.Equal(after, env) {
			t.Error("retry changed customer DB configuration")
		}
		return nil
	}
	if res := wizardRequest(w, "POST", "/api/install", WizardInput{Confirm: true, Version: "3.8.31"}, true); res.Code != 202 {
		t.Fatal(res.Body.String())
	}
	if done := waitWizard(t, w); done.Job.Progress != 100 || done.Job.Status != "success" {
		t.Fatal(done)
	}
}
func TestWizardTLSCertificateMatchesHostAndPrivateKeyIsProtected(t *testing.T) {
	w := wizardFixture(t)
	if err := w.client.setupCertificate(w.config, "", ""); err != nil {
		t.Fatal(err)
	}
	pair, err := tls.LoadX509KeyPair(w.client.wizardPath("setup-cert.pem"), w.client.wizardPath("setup-key.pem"))
	if err != nil {
		t.Fatal(err)
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil || leaf.VerifyHostname(w.config.Host) != nil {
		t.Fatal("invalid installer certificate")
	}
	info, _ := os.Stat(w.client.wizardPath("setup-key.pem"))
	if info.Mode().Perm() != 0600 {
		t.Fatal("TLS key exposed")
	}
	server := httptest.NewUnstartedServer(w.handler())
	server.TLS = &tls.Config{Certificates: []tls.Certificate{pair}, MinVersion: tls.VersionTLS12}
	server.StartTLS()
	defer server.Close()
	pool := x509.NewCertPool()
	pool.AddCert(leaf)
	client := &http.Client{Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool, ServerName: w.config.Host, MinVersion: tls.VersionTLS12}}}
	defer client.CloseIdleConnections()
	req, _ := http.NewRequest("GET", server.URL+"/", nil)
	req.Host = "setup.example.test:8443"
	response, err := client.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != 200 {
		t.Fatal(response.StatusCode)
	}
}

func TestWizardLostCompletionDoesNotInstallASeparatelyGrantedUpdate(t *testing.T) {
	w := wizardFixture(t)
	authorizeWizard(t, w)
	w.poll = func() (State, error) {
		s := w.state
		s.Completed = true
		s.Access = "allowed"
		s.ApplicationVersion = "3.8.31"
		s.Package.Version = "3.8.32"
		return s, nil
	}
	w.deploy = func(s State) error {
		if !s.Completed || s.Package.Version != "3.8.31" {
			return errors.New("resume selected an unconfirmed update")
		}
		return nil
	}
	if res := wizardRequest(w, "POST", "/api/install", wizardInput(w), true); res.Code != 202 {
		t.Fatal(res.Body.String())
	}
	if done := waitWizard(t, w); done.Job.Status != "success" {
		t.Fatal(done)
	}
}
