# Existing host Nginx proxy

Office Central publishes its application Nginx directly on `0.0.0.0:80`. Make sure no other service on the host already owns port 80.

If TLS is terminated by a separate edge proxy, point `panel.ponet.ir` to the server on HTTP port 80. Preserve the original Host header and forward the client/protocol headers. A separate HTTPS-only Nginx proxy can use:

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
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Issue and renew TLS in the edge proxy/UI. Test the application locally with `curl http://127.0.0.1:80/health`.
