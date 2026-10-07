package main

import (
	"bufio"
	"context"
	"crypto/ed25519"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"syscall"
	"time"
)

func main() {
	if e := execute(); e != nil {
		fmt.Fprintln(os.Stderr, "ERROR:", e)
		os.Exit(1)
	}
}
func execute() error {
	if os.Geteuid() != 0 {
		return errors.New("run office-agent as root")
	}
	if len(os.Args) < 2 {
		return errors.New("usage: office-agent install|resume|daemon|status|reactivate|update [--root /opt/office]")
	}
	command := os.Args[1]
	flags := flag.NewFlagSet(command, flag.ContinueOnError)
	root := flags.String("root", "/opt/office", "persistent installation directory")
	endpoint := flags.String("endpoint", "https://update.ponet.ir", "HTTPS update origin")
	adopt := flags.String("adopt-env", "", "original Office environment for adoption")
	if e := flags.Parse(os.Args[2:]); e != nil {
		return e
	}
	*root = filepath.Clean(*root)
	if !regexp.MustCompile(`^/[A-Za-z0-9/_-]+$`).MatchString(*root) || *root == "/" {
		return errors.New("root must be an absolute Linux path without spaces")
	}
	if e := os.MkdirAll(*root, 0755); e != nil {
		return e
	}
	c, e := openClient(*root, *endpoint)
	if e != nil {
		return e
	}
	if command == "status" {
		raw, e := os.ReadFile(filepath.Join(*root, "agent/public/state.json"))
		if e != nil {
			return e
		}
		var envelope Envelope
		json.Unmarshal(raw, &envelope)
		var s State
		if e = verify(c.Identity.Trust, envelope, &s); e != nil {
			return e
		}
		fmt.Printf("Installation: %s\nAccess: %s\nMessage: %s\nVersion: %s\n", s.Installation, s.Access, s.Message, s.Package.Version)
		return nil
	}
	if command == "reactivate" || command == "update" {
		code := ""
		if command == "reactivate" {
			code, e = prompt("New one-use license: ")
			if e != nil {
				return e
			}
		}
		return c.local(command, code)
	}
	if command != "install" && command != "resume" && command != "daemon" {
		return errors.New("unknown command")
	}
	if command != "daemon" {
		// Only stop our helper, never database or application services.
		active, _ := output("systemctl", "is-active", "office-agent.service")
		if strings.TrimSpace(string(active)) == "active" {
			defer exec.Command("systemctl", "start", "office-agent.service").Run()
		}
		_ = exec.Command("systemctl", "stop", "office-agent.service").Run()
	}
	lock, e := os.OpenFile(filepath.Join(*root, "agent/private/process.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if e != nil {
		return e
	}
	defer lock.Close()
	if e = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); e != nil {
		return errors.New("another helper process is running")
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
	if raw, err := os.ReadFile(filepath.Join(*root, "agent/private/identity.json")); err == nil {
		if err = json.Unmarshal(raw, &c.Identity); err != nil {
			return err
		}
	}
	h, e := hardware()
	if e != nil {
		return e
	}
	if command == "daemon" {
		return c.daemon()
	}
	if e = ensureDocker(); e != nil {
		return e
	}
	var s State
	if c.Identity.Installation == "" {
		code, e := prompt("One-use installation license: ")
		if e != nil {
			return e
		}
		s, e = c.activate(code, "begin", h)
	} else {
		s, e = c.poll(h)
	}
	if e != nil {
		return e
	}
	if s.Completed {
		fmt.Println("Resuming completed installation; data and license are preserved.")
	}
	if s.Completed && s.Access == "locked" {
		if e = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN); e != nil {
			return e
		}
		if e = c.installService(true); e != nil {
			return e
		}
		fmt.Println("Office remains locked; activate a new license at /license or with office-agent reactivate. Customer data is preserved.")
		return nil
	}
	if e = c.deploy(s, h, *adopt); e != nil {
		return e
	}
	if e = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN); e != nil {
		return e
	}
	return c.installService(true)
}
func prompt(label string) (string, error) {
	fmt.Fprint(os.Stdout, label)
	terminal, e := os.OpenFile("/dev/tty", os.O_RDWR, 0)
	if e != nil {
		return "", e
	}
	defer terminal.Close()
	old, e := output("stty", "-F", "/dev/tty", "-g")
	if e != nil {
		return "", e
	}
	if e = exec.Command("stty", "-F", "/dev/tty", "-echo").Run(); e != nil {
		return "", e
	}
	defer exec.Command("stty", "-F", "/dev/tty", strings.TrimSpace(string(old))).Run()
	defer fmt.Println()
	value, e := bufio.NewReader(terminal).ReadString('\n')
	if e != nil {
		return "", e
	}
	value = strings.TrimSpace(value)
	if len(value) < 10 || len(value) > 40 {
		return "", errors.New("invalid license code length")
	}
	return value, nil
}
func (c *Client) local(action, code string) error {
	transport := &http.Transport{DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", filepath.Join(c.Root, "agent/control/control.sock"))
	}}
	client := http.Client{Transport: transport, Timeout: 30 * time.Minute}
	body, _ := json.Marshal(map[string]string{"license_key": code})
	req, _ := http.NewRequest("POST", "http://localhost/"+action, strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.Identity.Control)
	req.Header.Set("Content-Type", "application/json")
	res, e := client.Do(req)
	if e != nil {
		return e
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(res.Body, 65536))
	if res.StatusCode != 200 {
		return fmt.Errorf("helper: %s", raw)
	}
	fmt.Println(string(raw))
	return nil
}
func (c *Client) daemon() error {
	controlDir := filepath.Join(c.Root, "agent/control")
	if e := os.MkdirAll(controlDir, 0755); e != nil {
		return e
	}
	if e := os.Chmod(controlDir, 0755); e != nil {
		return e
	}
	sock := filepath.Join(controlDir, "control.sock")
	if e := os.Remove(sock); e != nil && !os.IsNotExist(e) {
		return e
	}
	listener, e := net.Listen("unix", sock)
	if e != nil {
		return e
	}
	defer listener.Close()
	defer os.Remove(sock)
	if e = os.Chmod(sock, 0660); e != nil {
		return e
	}
	if e = os.Chown(sock, 0, 33); e != nil {
		return e
	}
	var mu sync.Mutex
	mux := http.NewServeMux()
	controlToken := c.Identity.Control
	deviceKey, _ := b64.DecodeString(c.Identity.Key)
	enforce := func(s State) {
		h, err := hardware()
		fp, _ := h.fingerprint()
		allowed := err == nil && s.Access == "allowed" && s.Completed && s.Hardware == fp && s.Presented == fp
		action := "stop"
		if allowed {
			action = "start"
		}
		// No down, no volume removal and no data deletion. Terminating clone
		// gateways also closes already-open terminal/RDP sessions.
		for _, name := range []string{"office-queue", "office-cron", "office-messenger", "office-ssh", "office-rdp", "office-rdp-core"} {
			if err := run(nil, "docker", action, name); err != nil {
				log.Println("worker licensing:", err)
			}
		}
	}
	var initial Envelope
	var cachedState *State
	if raw, err := os.ReadFile(filepath.Join(c.Root, "agent/public/state.json")); err == nil {
		if json.Unmarshal(raw, &initial) == nil {
			var state State
			if verify(c.Identity.Trust, initial, &state) == nil {
				cachedState = &state
			}
		}
	}
	var lastDecision string
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		token := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if r.Method != "POST" || subtle.ConstantTimeCompare([]byte(token), []byte(controlToken)) != 1 {
			http.Error(w, "unauthorized", 401)
			return
		}
		if r.URL.Path == "/identity" {
			var input struct{ Nonce string }
			if err := json.NewDecoder(io.LimitReader(r.Body, 1024)).Decode(&input); err != nil || !regexp.MustCompile("^[a-f0-9]{32}$").MatchString(input.Nonce) {
				http.Error(w, "invalid challenge", 400)
				return
			}
			h, err := hardware()
			if err != nil {
				http.Error(w, "hardware unavailable", 503)
				return
			}
			fp, _ := h.fingerprint()
			sig := ed25519.Sign(deviceKey, []byte("office-hardware-proof/v2\n"+input.Nonce+"\n"+fp))
			raw, _ := json.Marshal(map[string]string{"fingerprint": fp, "signature": b64.EncodeToString(sig)})
			w.Header().Set("Content-Type", "application/json")
			w.Header().Set("Content-Length", fmt.Sprint(len(raw)))
			w.Write(raw)
			return
		}
		if r.URL.Path != "/reactivate" && r.URL.Path != "/update" {
			http.NotFound(w, r)
			return
		}
		var data struct {
			Code string `json:"license_key"`
		}
		if e := json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&data); e != nil {
			http.Error(w, "invalid request", 400)
			return
		}
		mu.Lock()
		defer mu.Unlock()
		h, e := hardware()
		if e == nil && r.URL.Path == "/reactivate" {
			if len(data.Code) < 10 || len(data.Code) > 40 {
				http.Error(w, "invalid license", 422)
				return
			}
			e = c.health()
			if e == nil {
				var state State
				state, e = c.activate(data.Code, "reactivate", h)
				if e == nil {
					enforce(state)
					lastDecision = state.Access + state.Hardware + state.Presented
				}
			}
		} else if e == nil {
			var s State
			s, e = c.poll(h)
			if e == nil {
				e = c.deploy(s, h, "")
			}
		}
		if e != nil {
			log.Println("local operation failed:", e)
			http.Error(w, "درخواست انجام نشد؛ وضعیت سرویس و لاگ office-agent را بررسی کنید.", 502)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"success":true}`)
	})
	server := http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() {
		if e := server.Serve(listener); e != nil && e != http.ErrServerClosed {
			log.Fatal(e)
		}
	}()
	// Serve local identity proofs before potentially slow Docker lifecycle
	// calls, so an authorized app is not blocked during daemon startup.
	mu.Lock()
	if cachedState != nil {
		enforce(*cachedState)
		lastDecision = cachedState.Access + cachedState.Hardware + cachedState.Presented
	}
	mu.Unlock()
	for {
		mu.Lock()
		h, e := hardware()
		if e == nil {
			var state State
			state, e = c.poll(h)
			if e == nil {
				decision := state.Access + state.Hardware + state.Presented
				if decision != lastDecision {
					enforce(state)
					lastDecision = decision
				}
			}
		}
		if e != nil {
			log.Println("State sync unavailable; keeping the previous signed state:", e)
		}
		mu.Unlock()
		time.Sleep(5 * time.Second)
	}
}
