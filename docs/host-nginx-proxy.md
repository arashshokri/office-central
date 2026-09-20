# Existing host Nginx proxy

Office Central does not bind ports 80 or 443. Its application Nginx is published at `0.0.0.0:8787` by default so a host or containerized Nginx UI can reach it. Use the host firewall to allow port 8787 only from the reverse proxy or trusted management network.

Create a proxy entry for `panel.ponet.ir` with upstream `http://127.0.0.1:8787`. Enable WebSocket support if the UI offers it, preserve the original Host header, and forward the client/protocol headers. A native Nginx server block uses:

```nginx
server {
    listen 80;
    server_name panel.ponet.ir;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name panel.ponet.ir;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Issue and renew TLS in the existing host proxy/UI. Block untrusted public traffic to port 8787; test it locally with `curl http://127.0.0.1:8787/health`. Set `INTERNAL_BIND_ADDRESS=127.0.0.1` when the proxy runs directly on the host and external binding is unnecessary.
