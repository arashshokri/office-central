#!/usr/bin/env python3
"""Apply isolated Office licensing files without replacing existing project edits."""
import argparse, shutil
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('office')
parser.add_argument('--update', action='store_true', help='Replace previously installed integration files only.')
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
office=Path(args.office).resolve()
if not (office/'artisan').is_file():raise SystemExit('Not an Office checkout.')
source=root/'integrations/office'
for folder in ['app','config','resources','tests']:
    for file in (source/folder).rglob('*'):
        if file.is_file():
            dest=office/file.relative_to(source);dest.parent.mkdir(parents=True,exist_ok=True)
            if dest.exists() and dest.read_bytes()!=file.read_bytes() and not args.update:raise SystemExit('Existing integration file differs: '+str(dest))
            shutil.copy2(file,dest)
provider=office/'bootstrap/providers.php';text=provider.read_text(encoding='utf-8')
entry='    App\\Providers\\OfficeLicenseServiceProvider::class,\n'
if entry.strip() not in text:
    assert 'App\\Providers\\AppServiceProvider::class,' in text
    provider.write_text(text.replace('    App\\Providers\\AppServiceProvider::class,\n','    App\\Providers\\AppServiceProvider::class,\n'+entry), encoding='utf-8', newline='\n')
caddy=office/'docker/Caddyfile';text=caddy.read_text(encoding='utf-8')
if 'forward_auth 127.0.0.1:8080' not in text:
    for matcher,backend in [('@terminal_ws','terminal-gateway:3001'),('@rdp_gateway','rdp-web:8080')]:
        old='    reverse_proxy '+matcher+' '+backend+' {\n        flush_interval -1\n    }'
        new='    route '+matcher+' {\n        forward_auth 127.0.0.1:8080 {\n            uri /internal/license/access\n        }\n        reverse_proxy '+backend+' {\n            flush_interval -1\n        }\n    }'
        assert old in text,matcher
        text=text.replace(old,new)
    caddy.write_text(text, encoding='utf-8', newline='\n')
docker=office/'Dockerfile';text=docker.read_text(encoding='utf-8')
if '    sodium \\' not in text:
    text=text.replace('    pcntl \\\n', '    pcntl \\\n    sodium \\\n')
    docker.write_text(text, encoding='utf-8', newline='\n')
if 'FROM php-base AS customer' not in text:
    docker.write_text(text+'\n'+(source/'customer.Dockerfile.fragment').read_text(encoding='utf-8'), encoding='utf-8', newline='\n')
elif args.update:
    start=text.index('# Protected customer target.')
    marker='# END OFFICE MANAGED CUSTOMER TARGET'
    if marker in text:
        end=text.index(marker,start)+len(marker)
    else:
        ending='CMD ["sh", "-c", "php artisan config:cache && exec frankenphp run --config /etc/caddy/Caddyfile"]'
        assert text.rstrip().endswith(ending), 'Customer target was edited; merge it manually.'
        end=len(text.rstrip())
    docker.write_text(text[:start]+(source/'customer.Dockerfile.fragment').read_text(encoding='utf-8').strip()+text[end:], encoding='utf-8', newline='\n')
console=office/'routes/console.php';text=console.read_text(encoding='utf-8')
marker='// Managed Office: suspend scheduled work without deleting data.'
if marker not in text:
    text+='\n'+marker+'\n'
    text+='foreach (app(\\Illuminate\\Console\\Scheduling\\Schedule::class)->events() as $officeEvent) {\n'
    text+='    $officeEvent->when(fn () => app(\\App\\Services\\OfficeLicense::class)->decision()[\'allowed\']);\n}\n'
    console.write_text(text, encoding='utf-8', newline='\n')
for name in ['.env.example','.env.docker.example']:
    file=office/name;text=file.read_text(encoding='utf-8')
    if 'OFFICE_LICENSE_ENABLED=' not in text:
        text+='\n# Enabled automatically in protected customer packages; keep false for legacy owner installs.\nOFFICE_LICENSE_ENABLED=false\nOFFICE_AGENT_STATE_DIR=/run/office-agent/public\nOFFICE_AGENT_SOCKET=/run/office-agent/control/control.sock\nOFFICE_AGENT_CONTROL_TOKEN=\n'
        file.write_text(text, encoding='utf-8', newline='\n')
print('Office licensing integration installed; unrelated edits preserved.')
