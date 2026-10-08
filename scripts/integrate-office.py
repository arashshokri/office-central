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
if not source.is_dir():source=root/'agent/integration'
if not source.is_dir():raise SystemExit('Office integration kit is missing.')
def copy_managed(file,dest):
    dest.parent.mkdir(parents=True,exist_ok=True)
    if dest.exists() and dest.read_bytes()!=file.read_bytes() and not args.update:raise SystemExit('Existing integration file differs: '+str(dest))
    if file.resolve()!=dest.resolve():shutil.copy2(file,dest)
for folder in ['app','config','resources','public','tests','docs','.github']:
    for file in (source/folder).rglob('*'):
        if file.is_file():
            copy_managed(file,office/file.relative_to(source))
# Office owns a standalone copy of its installer, daemon, package builder and
# integration kit. A source ZIP or GitHub checkout can build without Central.
for file in (root/'agent').glob('*'):
    if file.is_file() and (file.suffix=='.go' or file.name=='go.mod'):
        copy_managed(file,office/'agent'/file.name)
for name in ['integrate-office.py','office-helper.py','build-office-package.py','build-office-agent.sh']:
    copy_managed(root/'scripts'/name,office/'scripts'/name)
copy_managed(root/'tests/Deployment/office_source_test.py',office/'tests/Deployment/office_source_test.py')
install=root/'public/agent/install.sh'
docker_install=root/'public/agent/install-docker.sh'
if not install.is_file():install=root/'office-install.sh'
if not docker_install.is_file():docker_install=root/'agent/install-docker.sh'
copy_managed(install,office/'office-install.sh')
copy_managed(docker_install,office/'agent/install-docker.sh')
for file in source.rglob('*'):
    if file.is_file():copy_managed(file,office/'agent/integration'/file.relative_to(source))
ignore=office/'agent/.gitignore'
if not ignore.exists() or ignore.read_text()=='office-agent\n*.test\n':
    ignore.write_text('/office-agent\n*.test\n',encoding='utf-8')
ignore=office/'dist/.gitignore';ignore.parent.mkdir(parents=True,exist_ok=True)
if not ignore.exists():ignore.write_text('*\n!.gitignore\n',encoding='utf-8')
ignore=office/'.gitignore';text=ignore.read_text(encoding='utf-8') if ignore.exists() else ''
if '__pycache__/' not in text:
    ignore.write_text(text+'\n# Owner package helper runtime cache\n__pycache__/\n',encoding='utf-8',newline='\n')
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
docker=office/'Dockerfile';dockerText=docker.read_text(encoding='utf-8')
if 'FROM production AS managed' not in dockerText:
    docker.write_text(dockerText+'\n'+(source/'managed.Dockerfile.fragment').read_text(encoding='utf-8'),encoding='utf-8',newline='\n')
elif args.update:
    start=dockerText.index('# Standard managed Office image.')
    marker='# END OFFICE STANDARD MANAGED TARGET'
    end=dockerText.index(marker,start)+len(marker)
    docker.write_text(dockerText[:start]+(source/'managed.Dockerfile.fragment').read_text(encoding='utf-8').strip()+dockerText[end:],encoding='utf-8',newline='\n')
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
# Existing deployments gain two read-only helper mounts on their next ordinary
# Office update. No credentials, volumes, ports or proxy settings are changed.
compose=office/'compose.yaml';text=compose.read_text(encoding='utf-8')
if '/run/office-agent/control:ro' not in text:
    anchor='    - app_storage:/app/storage\n'
    if text.count(anchor)!=1:raise SystemExit('Unexpected Office storage anchor; merge helper mounts manually.')
    text=text.replace(anchor,anchor+'    - ${OFFICE_AGENT_ROOT:-/var/lib/office-helper}/agent/public:/run/office-agent/public:ro\n'
                      +'    - ${OFFICE_AGENT_ROOT:-/var/lib/office-helper}/agent/control:/run/office-agent/control:ro\n')
    compose.write_text(text,encoding='utf-8',newline='\n')
layout=office/'resources/views/layouts/app.blade.php';text=layout.read_text(encoding='utf-8')
link="                            @if(auth()->user()?->role === 'admin')\n"
link+="                            <li class=\"nav-item\"><a class=\"nav-link\" href=\"{{ route('office-agent.license') }}\"><i class=\"fas fa-key\"></i><span class=\"nav-label\">مجوز و helper</span></a></li>\n                            @endif\n\n"
if link in text:layout.write_text(text.replace(link,''),encoding='utf-8',newline='\n')
settings=office/'app/Http/Controllers/SettingsController.php';text=settings.read_text(encoding='utf-8')
if "route('settings.system-update')" not in text:
    anchor="        ])->where('visible', true)->values();"
    if anchor not in text:raise SystemExit('Office settings controller anchor is missing.')
    entry="            [\n                'title' => 'بروزرسانی سامانه',\n                'description' => 'وضعیت لایسنس، اعتبار و دریافت نسخه‌های مجاز',\n                'icon' => 'fa-arrows-rotate',\n                'route' => route('settings.system-update'),\n                'visible' => $user->isAdmin(),\n            ],\n"
    settings.write_text(text.replace(anchor,entry+anchor),encoding='utf-8',newline='\n')
elif args.update:
    settings.write_text(text.replace('وضعیت لایسنس، اعتبار و دریافت نسخه‌های مجاز از مرکز','وضعیت لایسنس، اعتبار و دریافت نسخه‌های مجاز'),encoding='utf-8',newline='\n')
console=office/'routes/console.php';text=console.read_text(encoding='utf-8')
text=text.replace("app(\\App\\Services\\OfficeLicense::class)->decision()['allowed']);", "app(\\App\\Services\\OfficeLicense::class)->decision()['allowed'] && !app(\\App\\Services\\OfficeLicense::class)->maintenance());")
console.write_text(text,encoding='utf-8',newline='\n')
dockerIgnore=office/'.dockerignore';text=dockerIgnore.read_text(encoding='utf-8')
for pattern in ['.office-central-fix', 'dist', 'agent', '__pycache__']:
    if pattern not in text.splitlines():text+='\n'+pattern+'\n'
dockerIgnore.write_text(text,encoding='utf-8',newline='\n')
print('Office licensing integration installed; unrelated edits preserved.')
