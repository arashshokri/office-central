package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/subtle"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"embed"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/mail"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"syscall"
	"time"
)

//go:embed wizard-assets/*
var wizardAssets embed.FS

type WizardConfig struct {
	Host    string    `json:"host"`
	Bind    string    `json:"bind"`
	Port    int       `json:"port"`
	Token   string    `json:"token"`
	Expires time.Time `json:"expires"`
}
type WizardStatus struct {
	Job        UpdateJob  `json:"job"`
	Authorized bool       `json:"authorized"`
	Deployment Deployment `json:"deployment"`
	Version    string     `json:"version"`
	Configured bool       `json:"configured"`
}
type WizardInput struct {
	Deployment           Deployment `json:"deployment"`
	Password             string     `json:"password"`
	PasswordConfirmation string     `json:"password_confirmation"`
	Confirm              bool       `json:"confirm"`
	Version              string     `json:"expected_version"`
}
type installWizard struct {
	client      *Client
	config      WizardConfig
	hardware    Hardware
	mu          sync.Mutex
	status      WizardStatus
	state       State
	busy        bool
	lastLicense time.Time
	failedAuth  int
	authWindow  time.Time
	activate    func(string) (State, error)
	poll        func() (State, error)
	deploy      func(State) error
	finish      func() error
}

type transferProgress struct {
	writer            io.Writer
	size, transferred int64
	last              time.Time
	report            func(int)
}

func (p *transferProgress) Write(raw []byte) (int, error) {
	n, err := p.writer.Write(raw)
	p.transferred += int64(n)
	if p.size > 0 && time.Since(p.last) >= time.Second {
		p.report(int(p.transferred * 100 / p.size))
		p.last = time.Now()
	}
	return n, err
}
func (c *Client) reportInstall(stage, message string, progress int) {
	if c.InstallProgress != nil {
		c.InstallProgress(stage, message, progress)
	}
}
func pendingWebSetup(root string) bool {
	_, err := os.Stat(filepath.Join(root, "agent/private/wizard.json"))
	if err != nil {
		return false
	}
	var status WizardStatus
	raw, _ := os.ReadFile(filepath.Join(root, "agent/private/install-job.json"))
	_ = json.Unmarshal(raw, &status)
	return status.Job.Status != "success"
}
func wizardHost(host string) bool {
	return net.ParseIP(host) != nil || regexp.MustCompile(`^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$`).MatchString(host)
}
func setupHost() string {
	interfaces, _ := net.InterfaceAddrs()
	for _, address := range interfaces {
		ip, _, err := net.ParseCIDR(address.String())
		if err == nil && ip.To4() != nil && ip.IsGlobalUnicast() {
			return ip.String()
		}
	}
	return "127.0.0.1"
}
func (c *Client) wizardPath(name string) string { return filepath.Join(c.Root, "agent/private", name) }
func (c *Client) readWizardConfig() (WizardConfig, error) {
	var config WizardConfig
	raw, err := os.ReadFile(c.wizardPath("wizard.json"))
	if err == nil {
		err = json.Unmarshal(raw, &config)
	}
	if err == nil && (!wizardHost(config.Host) || net.ParseIP(config.Bind) == nil || config.Port < 1024 || config.Port > 65535 || len(config.Token) != 64) {
		err = errors.New("invalid installer configuration")
	}
	return config, err
}

func (c *Client) launchWizard(host, bind string, port int, certPath, keyPath string) error {
	if err := ensureDocker(); err != nil {
		return err
	}
	config, err := c.readWizardConfig()
	if err == nil && time.Now().Before(config.Expires) {
		// An active installer keeps its URL, identity and in-flight operation.
		if active, _ := output("systemctl", "is-active", "office-installer.service"); strings.TrimSpace(string(active)) == "active" {
			return c.printWizardURL(config)
		}
	}
	if active, _ := output("systemctl", "is-active", "office-installer.service"); strings.TrimSpace(string(active)) == "active" {
		var status WizardStatus
		raw, _ := os.ReadFile(c.wizardPath("install-job.json"))
		_ = json.Unmarshal(raw, &status)
		if status.Job.Status == "running" {
			return errors.New("installation is still running; do not restart the installer during deployment")
		}
		if _, err = output("systemctl", "stop", "office-installer.service"); err != nil {
			return err
		}
	}
	if existing, err := c.existingOffice(); err != nil {
		return err
	} else if existing != nil {
		return errors.New("Office already exists; use setup/connect to preserve its data")
	}
	if _, err = os.Stat(filepath.Join(c.Root, "compose.json")); os.IsNotExist(err) {
		if existingOfficeDetected() {
			return errors.New("Office already exists; use connect instead of a new installation")
		}
		if _, err = output("docker", "volume", "inspect", "leave-panel_db_data"); err == nil {
			return errors.New("existing customer database detected; use connect")
		}
	}
	if host == "" {
		host = config.Host
	}
	if host == "" {
		host = setupHost()
	}
	if !wizardHost(host) || net.ParseIP(bind) == nil || port < 1024 || port > 65535 {
		return errors.New("setup requires a hostname/IP, listen IP and port 1024–65535")
	}
	config = WizardConfig{Host: host, Bind: bind, Port: port, Token: randomHex(32), Expires: time.Now().Add(24 * time.Hour)}
	if err = c.setupCertificate(config, certPath, keyPath); err != nil {
		return err
	}
	if err = atomicJSON(c.wizardPath("wizard.json"), config, 0600); err != nil {
		return err
	}
	// Install the long-lived binary and licensing unit before the restricted
	// installer service starts. No installation code is reserved here.
	if err = c.installService(false); err != nil {
		return err
	}
	if _, err = output("systemctl", "disable", "office-agent.service"); err != nil {
		return err
	}
	if err = atomicWrite("/etc/systemd/system/office-installer.service", []byte(officeWizardUnit(c.Root)), 0644); err != nil {
		return err
	}
	if err = run(nil, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	if err = run(nil, "systemctl", "enable", "--now", "office-installer.service"); err != nil {
		return err
	}
	pair, err := tls.LoadX509KeyPair(c.wizardPath("setup-cert.pem"), c.wizardPath("setup-key.pem"))
	if err != nil {
		return err
	}
	pool := x509.NewCertPool()
	certificate, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil {
		return err
	}
	pool.AddCert(certificate)
	local := &http.Client{Timeout: time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool, ServerName: config.Host, MinVersion: tls.VersionTLS12}}}
	defer local.CloseIdleConnections()
	ip := bind
	if ip == "0.0.0.0" {
		ip = "127.0.0.1"
	}
	if ip == "::" {
		ip = "::1"
	}
	for attempt := 0; attempt < 30; attempt++ {
		request, _ := http.NewRequest("GET", "https://"+net.JoinHostPort(ip, fmt.Sprint(port))+"/", nil)
		request.Host = net.JoinHostPort(config.Host, fmt.Sprint(port))
		res, err := local.Do(request)
		if err == nil {
			res.Body.Close()
			if res.StatusCode == 200 {
				return c.printWizardURL(config)
			}
		}
		time.Sleep(time.Second)
	}
	return errors.New("installer did not start; run journalctl -u office-installer -n 60 --no-pager")
}
func officeWizardUnit(root string) string {
	return "[Unit]\nDescription=Office web installation wizard\nAfter=network-online.target docker.service\nWants=network-online.target\n[Service]\nType=simple\nExecStart=/usr/local/bin/office-agent wizard --root " + root + "\nEnvironment=DOCKER_CONFIG=" + filepath.Join(root, "agent/private/docker") + "\nRestart=on-failure\nRestartSec=5\nUMask=0077\nNoNewPrivileges=true\nProtectSystem=full\nProtectHome=true\n[Install]\nWantedBy=multi-user.target\n"
}
func (c *Client) printWizardURL(config WizardConfig) error {
	raw, err := os.ReadFile(c.wizardPath("setup-cert.pem"))
	if err != nil {
		return err
	}
	block, _ := pem.Decode(raw)
	if block == nil {
		return errors.New("invalid installer TLS certificate")
	}
	fmt.Printf("\nOffice web setup:\nhttps://%s/#%s\n\nTLS certificate SHA-256: %s\n", net.JoinHostPort(config.Host, fmt.Sprint(config.Port)), config.Token, digest(block.Bytes))
	fmt.Printf("Private link expires: %s\nOpen TCP %d only for the installer users. Closing the browser does not stop installation.\n", config.Expires.Format(time.RFC3339), config.Port)
	fmt.Println("For the generated local TLS certificate, verify the fingerprint before accepting it in the browser. A trusted certificate can be supplied with --setup-cert and --setup-key.")
	return nil
}
func (c *Client) setupCertificate(config WizardConfig, certPath, keyPath string) error {
	var certificate, key []byte
	if certPath != "" || keyPath != "" {
		if certPath == "" || keyPath == "" {
			return errors.New("provide both setup-cert and setup-key")
		}
		var err error
		certificate, err = os.ReadFile(certPath)
		if err != nil {
			return err
		}
		key, err = os.ReadFile(keyPath)
		if err != nil {
			return err
		}
		pair, err := tls.X509KeyPair(certificate, key)
		if err != nil {
			return err
		}
		leaf, err := x509.ParseCertificate(pair.Certificate[0])
		if err != nil {
			return err
		}
		if err = leaf.VerifyHostname(config.Host); err != nil {
			return err
		}
		if time.Now().Before(leaf.NotBefore) || time.Now().After(leaf.NotAfter) {
			return errors.New("installer certificate is not currently valid")
		}
	} else {
		secret, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			return err
		}
		serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
		if err != nil {
			return err
		}
		template := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: "Office Setup"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(48 * time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
		if ip := net.ParseIP(config.Host); ip != nil {
			template.IPAddresses = []net.IP{ip}
		} else {
			template.DNSNames = []string{config.Host}
		}
		der, err := x509.CreateCertificate(rand.Reader, template, template, &secret.PublicKey, secret)
		if err != nil {
			return err
		}
		encoded, err := x509.MarshalPKCS8PrivateKey(secret)
		if err != nil {
			return err
		}
		certificate = pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
		key = pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: encoded})
	}
	if err := atomicWrite(c.wizardPath("setup-cert.pem"), certificate, 0600); err != nil {
		return err
	}
	return atomicWrite(c.wizardPath("setup-key.pem"), key, 0600)
}

func newInstallWizard(c *Client, config WizardConfig, h Hardware) *installWizard {
	w := &installWizard{client: c, config: config, hardware: h}
	raw, _ := os.ReadFile(c.wizardPath("install-job.json"))
	_ = json.Unmarshal(raw, &w.status)
	// Authenticate saved setup from the signed state, never from UI storage.
	raw, _ = os.ReadFile(filepath.Join(c.Root, "agent/public/state.json"))
	var envelope Envelope
	if json.Unmarshal(raw, &envelope) == nil && verify(c.Identity.Trust, envelope, &w.state) == nil && w.state.Installation == c.Identity.Installation {
		fp, err := h.fingerprint()
		w.status.Authorized = err == nil && w.state.Hardware == fp && w.state.Presented == fp && w.state.Access != "locked" && w.state.ActivationMode == "installer_once"
		w.status.Version = w.state.Package.Version
		if w.state.Completed && w.state.ApplicationVersion != "" {
			w.status.Version = w.state.ApplicationVersion
		}
		if !w.status.Configured {
			w.status.Deployment = wizardDefaults(w.state.Deployment)
		}
	} else {
		w.status.Authorized = false
	}
	if w.status.Job.Status == "running" {
		w.status.Job.Status = "error"
		w.status.Job.Error = "سرویس نصب راه‌اندازی مجدد شده است. ادامهٔ نصب را تأیید کنید؛ اطلاعات نصب حفظ شده‌اند."
		_ = atomicJSON(c.wizardPath("install-job.json"), w.status, 0600)
	}
	w.activate = func(code string) (State, error) {
		if c.Identity.Installation != "" {
			return c.poll(h)
		}
		return c.activate(code, "begin", h)
	}
	w.poll = func() (State, error) { return c.poll(h) }
	w.deploy = func(s State) error {
		if s.Completed {
			return c.health(s.Package.Version)
		}
		return c.deploy(s, h, "")
	}
	w.finish = func() error {
		if _, err := output("systemctl", "enable", "--now", "office-agent.service"); err != nil {
			return err
		}
		return c.waitControl()
	}
	c.InstallProgress = w.report
	return w
}
func wizardDefaults(d Deployment) Deployment {
	if d.Bind == "" {
		d.Bind = "127.0.0.1"
	}
	if d.Port == 0 {
		d.Port = 8080
	}
	if d.Name == "" {
		d.Name = "مدیر سامانه"
	}
	return d
}
func (w *installWizard) snapshot() WizardStatus { w.mu.Lock(); defer w.mu.Unlock(); return w.status }
func (w *installWizard) report(stage, message string, progress int) {
	w.mu.Lock()
	defer w.mu.Unlock()
	job := &w.status.Job
	if progress > job.Progress && progress < 100 {
		job.Progress = progress
	}
	job.Stage = stage
	job.Message = message
	if err := atomicJSON(w.client.wizardPath("install-job.json"), w.status, 0600); err != nil {
		job.Error = "ذخیرهٔ وضعیت نصب ناموفق بود؛ فضای دیسک را بررسی کنید."
	}
}
func validateWizardInput(input WizardInput, licensed Deployment, version string) error {
	d := input.Deployment
	email, err := mail.ParseAddress(d.Email)
	if !input.Confirm || input.Version != version {
		return errors.New("نسخه و شروع نصب را دوباره تأیید کنید")
	}
	if d.URL != licensed.URL {
		return errors.New("آدرس پنل باید با آدرس ثبت‌شده روی لایسنس یکسان باشد")
	}
	if err != nil || email.Address != d.Email || len(d.Email) > 255 {
		return errors.New("ایمیل مدیر معتبر نیست")
	}
	if strings.TrimSpace(d.Name) == "" || len(d.Name) > 300 || strings.ContainsAny(d.Name, "\r\n") {
		return errors.New("نام مدیر سامانه را وارد کنید")
	}
	if len(input.Password) < 12 || len(input.Password) > 128 || input.Password != input.PasswordConfirmation {
		return errors.New("رمز باید حداقل ۱۲ و حداکثر ۱۲۸ کاراکتر باشد و با تکرار رمز یکسان باشد")
	}
	if net.ParseIP(d.Bind) == nil || d.Port < 1024 || d.Port > 65535 {
		return errors.New("IP اتصال و پورت وب معتبر نیست")
	}
	if d.Proxy != "" && !regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`).MatchString(d.Proxy) {
		return errors.New("نام شبکهٔ پروکسی معتبر نیست")
	}
	return nil
}
func (w *installWizard) handler() http.Handler {
	return http.HandlerFunc(func(res http.ResponseWriter, req *http.Request) {
		res.Header().Set("Cache-Control", "no-store")
		res.Header().Set("Referrer-Policy", "no-referrer")
		res.Header().Set("X-Content-Type-Options", "nosniff")
		res.Header().Set("X-Frame-Options", "DENY")
		res.Header().Set("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
		expectedHost := net.JoinHostPort(w.config.Host, fmt.Sprint(w.config.Port))
		if req.Host != expectedHost {
			http.Error(res, "Invalid host", http.StatusForbidden)
			return
		}
		if !strings.HasPrefix(req.URL.Path, "/api/") {
			if req.Method != "GET" {
				res.WriteHeader(405)
				return
			}
			name := map[string]string{"/": "index.html", "/setup.css": "setup.css", "/setup.js": "setup.js", "/vazirmatn.woff2": "vazirmatn.woff2"}[req.URL.Path]
			if name == "" {
				http.NotFound(res, req)
				return
			}
			data, err := wizardAssets.ReadFile("wizard-assets/" + name)
			if err != nil {
				http.NotFound(res, req)
				return
			}
			if name == "index.html" {
				res.Header().Set("Content-Type", "text/html; charset=utf-8")
			}
			if name == "setup.css" {
				res.Header().Set("Content-Type", "text/css; charset=utf-8")
			}
			if name == "setup.js" {
				res.Header().Set("Content-Type", "text/javascript; charset=utf-8")
			}
			if strings.HasSuffix(name, "woff2") {
				res.Header().Set("Content-Type", "font/woff2")
			}
			res.Write(data)
			return
		}
		if origin := req.Header.Get("Origin"); origin != "" && origin != "https://"+expectedHost {
			writeControlJSON(res, 403, map[string]string{"message": "درخواست از آدرس دیگری مجاز نیست"})
			return
		}
		if time.Now().After(w.config.Expires) {
			writeControlJSON(res, 401, map[string]string{"message": "لینک نصب منقضی شده است؛ دستور راه‌انداز را دوباره اجرا کنید"})
			return
		}
		if subtle.ConstantTimeCompare([]byte(req.Header.Get("Authorization")), []byte("Bearer "+w.config.Token)) != 1 {
			w.mu.Lock()
			if time.Since(w.authWindow) > time.Minute {
				w.authWindow = time.Now()
				w.failedAuth = 0
			}
			w.failedAuth++
			limited := w.failedAuth > 20
			w.mu.Unlock()
			status := 401
			if limited {
				status = 429
				res.Header().Set("Retry-After", "60")
			}
			writeControlJSON(res, status, map[string]string{"message": "از لینک خصوصی اعلام‌شده در سرور وارد صفحهٔ نصب شوید"})
			return
		}
		if req.URL.Path == "/api/status" && req.Method == "GET" {
			writeControlJSON(res, 200, w.snapshot())
			return
		}
		if req.Method != "POST" || !strings.HasPrefix(req.Header.Get("Content-Type"), "application/json") {
			writeControlJSON(res, 405, map[string]string{"message": "درخواست معتبر نیست"})
			return
		}
		req.Body = http.MaxBytesReader(res, req.Body, 16<<10)
		if req.URL.Path == "/api/license" {
			w.license(res, req)
			return
		}
		if req.URL.Path == "/api/install" {
			w.install(res, req)
			return
		}
		http.NotFound(res, req)
	})
}
func wizardDecode(req *http.Request, value any) error {
	decoder := json.NewDecoder(req.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(value); err != nil {
		return err
	}
	var extra any
	if err := decoder.Decode(&extra); err != io.EOF {
		return errors.New("one JSON document required")
	}
	return nil
}
func (w *installWizard) license(res http.ResponseWriter, req *http.Request) {
	var input struct {
		License string `json:"license"`
	}
	if wizardDecode(req, &input) != nil || len(strings.TrimSpace(input.License)) < 8 || len(input.License) > 40 {
		writeControlJSON(res, 422, map[string]string{"message": "لایسنس معتبر را وارد کنید"})
		return
	}
	w.mu.Lock()
	if w.busy || w.status.Job.Status == "success" {
		status := w.status
		w.mu.Unlock()
		writeControlJSON(res, 202, status)
		return
	}
	if w.status.Authorized {
		status := w.status
		w.mu.Unlock()
		writeControlJSON(res, 200, status)
		return
	}
	if time.Since(w.lastLicense) < 3*time.Second {
		w.mu.Unlock()
		res.Header().Set("Retry-After", "3")
		writeControlJSON(res, 429, map[string]string{"message": "چند ثانیه صبر کنید و دوباره تلاش کنید"})
		return
	}
	w.busy = true
	w.lastLicense = time.Now()
	w.mu.Unlock()
	lock, err := w.client.installLock()
	var state State
	if err == nil {
		state, err = w.activate(strings.TrimSpace(input.License))
		lock.Close()
	}
	w.mu.Lock()
	w.busy = false
	if err == nil && (state.ActivationMode != "installer_once" || state.Access == "locked") {
		err = errors.New("این لایسنس برای نصب جدید فعال نیست؛ با واحد فروش یا نماینده فنی خود در ارتباط باشید")
	}
	if err == nil {
		err = validateManifest(state.Package.Manifest)
	}
	if err == nil {
		w.state = state
		w.status.Authorized = true
		w.status.Version = state.Package.Version
		w.status.Deployment = wizardDefaults(state.Deployment)
	}
	status := w.status
	w.mu.Unlock()
	if err != nil {
		writeControlJSON(res, 422, map[string]string{"message": safeUpdateError(err)})
		return
	}
	writeControlJSON(res, 200, status)
}
func (c *Client) installLock() (*os.File, error) {
	lock, err := os.OpenFile(c.wizardPath("process.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		lock.Close()
		return nil, errors.New("عملیات دیگری در حال اجراست؛ وضعیت نصب را بررسی کنید")
	}
	return lock, nil
}
func (w *installWizard) install(res http.ResponseWriter, req *http.Request) {
	w.mu.Lock()
	if w.busy || w.status.Job.Status == "success" {
		status := w.status
		w.mu.Unlock()
		writeControlJSON(res, 202, status)
		return
	}
	if !w.status.Authorized {
		w.mu.Unlock()
		writeControlJSON(res, 409, map[string]string{"message": "ابتدا لایسنس نصب را تأیید کنید"})
		return
	}
	var input WizardInput
	if wizardDecode(req, &input) != nil {
		w.mu.Unlock()
		writeControlJSON(res, 422, map[string]string{"message": "اطلاعات نصب قابل خواندن نیست"})
		return
	}
	var err error
	if !w.status.Configured {
		err = validateWizardInput(input, w.state.Deployment, w.status.Version)
	} else if !input.Confirm || input.Version != w.status.Version {
		err = errors.New("ادامهٔ نصب و نسخه را تأیید کنید")
	}
	if err != nil {
		w.mu.Unlock()
		writeControlJSON(res, 422, map[string]string{"message": err.Error()})
		return
	}
	lock, err := w.client.installLock()
	if err != nil {
		w.mu.Unlock()
		writeControlJSON(res, 409, map[string]string{"message": err.Error()})
		return
	}
	if !w.status.Configured {
		d := input.Deployment
		if d.Port == w.config.Port && (d.Bind == w.config.Bind || d.Bind == "0.0.0.0" || w.config.Bind == "0.0.0.0") {
			lock.Close()
			w.mu.Unlock()
			writeControlJSON(res, 422, map[string]string{"message": "پورت وب Office باید با پورت راه‌انداز متفاوت باشد"})
			return
		}
		if d.Proxy != "" {
			if _, err = output("docker", "network", "inspect", d.Proxy); err != nil {
				lock.Close()
				w.mu.Unlock()
				writeControlJSON(res, 422, map[string]string{"message": "شبکهٔ پروکسی روی این سرور وجود ندارد؛ نام شبکهٔ Docker را بررسی کنید"})
				return
			}
		}
		listener, listenErr := net.Listen("tcp", net.JoinHostPort(d.Bind, fmt.Sprint(d.Port)))
		if listenErr != nil {
			lock.Close()
			w.mu.Unlock()
			writeControlJSON(res, 422, map[string]string{"message": "IP یا پورت وب روی سرور قابل استفاده نیست؛ پورت آزاد یا IP معتبر انتخاب کنید"})
			return
		}
		listener.Close()
	}
	if !w.status.Configured {
		// Both files are private. Once configured, retries retain the original
		// administrator and DB credentials even after a partial deployment.
		err = atomicJSON(w.client.wizardPath("setup-deployment.json"), input.Deployment, 0600)
		if err == nil {
			err = atomicJSON(filepath.Join(w.client.Root, "initial-admin.json"), map[string]string{"name": input.Deployment.Name, "email": input.Deployment.Email, "password": input.Password}, 0600)
		}
		if err == nil {
			w.status.Deployment = input.Deployment
			w.status.Configured = true
		}
	}
	if err != nil {
		lock.Close()
		w.mu.Unlock()
		writeControlJSON(res, 500, map[string]string{"message": "ثبت تنظیمات نصب ناموفق بود؛ فضای دیسک را بررسی کنید"})
		return
	}
	priorJob := w.status.Job
	w.status.Job = UpdateJob{ID: randomHex(16), Status: "running", Stage: "authorization", Progress: 3, Version: w.status.Version, Message: "بررسی مجوز و آغاز نصب…", StartedAt: time.Now().UTC().Format(time.RFC3339)}
	if err = atomicJSON(w.client.wizardPath("install-job.json"), w.status, 0600); err != nil {
		w.status.Job = priorJob
		lock.Close()
		w.mu.Unlock()
		writeControlJSON(res, 500, map[string]string{"message": "ثبت عملیات ناموفق بود"})
		return
	}
	w.busy = true
	status := w.status
	w.mu.Unlock()
	go w.runInstall(lock)
	writeControlJSON(res, 202, status)
}
func (w *installWizard) runInstall(lock *os.File) {
	state, err := w.poll()
	if err == nil && state.Completed && state.ApplicationVersion == w.status.Version {
		// A completion receipt may have been lost just before a newer update
		// was granted. Resume only health/daemon startup for this installation.
		state.Package.Version = state.ApplicationVersion
	}
	if err == nil && (state.ActivationMode != "installer_once" || state.Access == "locked" || state.Package.Version != w.status.Version) {
		err = errors.New("مجوز نصب یا نسخه تغییر کرده است؛ با نماینده فنی خود در ارتباط باشید")
	}
	if err == nil {
		err = w.deploy(state)
	}
	// Start the licensing daemon only after releasing its process lock.
	lock.Close()
	if err == nil {
		err = w.finish()
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	w.busy = false
	job := &w.status.Job
	job.FinishedAt = time.Now().UTC().Format(time.RFC3339)
	if err != nil {
		job.Status = "error"
		job.Message = "نصب در این مرحله متوقف شد؛ پس از رفع خطا ادامهٔ نصب را تأیید کنید."
		job.Error = safeUpdateError(err)
	} else {
		job.Status = "success"
		job.Progress = 100
		job.Stage = "complete"
		job.Message = "Office با موفقیت نصب و فعال شد."
		job.Error = ""
		// The chosen password is known to the customer; do not retain a second
		// cleartext copy after completion. Existing administrators are never reset.
		_ = os.Remove(filepath.Join(w.client.Root, "initial-admin.json"))
	}
	_ = atomicJSON(w.client.wizardPath("install-job.json"), w.status, 0600)
}
func (c *Client) serveWizard() error {
	config, err := c.readWizardConfig()
	if err != nil {
		return err
	}
	lock, err := os.OpenFile(c.wizardPath("wizard.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return err
	}
	defer lock.Close()
	if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return errors.New("another installer is already running")
	}
	h, err := hardware()
	if err != nil {
		return err
	}
	wizard := newInstallWizard(c, config, h)
	server := &http.Server{Addr: net.JoinHostPort(config.Bind, fmt.Sprint(config.Port)), Handler: wizard.handler(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 20 * time.Second, WriteTimeout: 90 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16 << 10, TLSConfig: &tls.Config{MinVersion: tls.VersionTLS12}}
	done := make(chan struct{})
	defer close(done)
	go func() {
		ticker := time.NewTicker(30 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-done:
				return
			case <-ticker.C:
				status := wizard.snapshot()
				finished, _ := time.Parse(time.RFC3339, status.Job.FinishedAt)
				if status.Job.Status != "running" && (time.Now().After(config.Expires) || (status.Job.Status == "success" && time.Since(finished) > 15*time.Minute)) {
					server.Close()
					return
				}
			}
		}
	}()
	err = server.ListenAndServeTLS(c.wizardPath("setup-cert.pem"), c.wizardPath("setup-key.pem"))
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
