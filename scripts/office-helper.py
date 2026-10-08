#!/usr/bin/env python3
"""Owner-side Office package builder. Customer machines run office-install.sh."""
import argparse
import hashlib
import importlib.util
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile


def extract_source(archive, destination):
    """Extract an owner-provided ZIP without links, traversal or duplicate paths."""
    with zipfile.ZipFile(archive) as bundle:
        entries = bundle.infolist()
        if not entries or len(entries) > 100000 or sum(x.file_size for x in entries) > 4 * 1024**3:
            raise ValueError('Source ZIP exceeds file count or expanded size limits.')
        seen = set()
        for entry in entries:
            name = entry.orig_filename
            path = PurePosixPath(name)
            mode = entry.external_attr >> 16
            if (not name or '\\' in name or '\x00' in name or path.is_absolute()
                    or any(x in ('', '.', '..') for x in name.rstrip('/').split('/'))
                    or ':' in name or name.endswith('//') or name.rstrip('/').casefold() in seen
                    or stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR))):
                raise ValueError('Unsafe source ZIP entry: '+name)
            seen.add(name.rstrip('/').casefold())
        bundle.extractall(destination)
    candidates = [destination] + [x for x in destination.iterdir() if x.is_dir()]
    candidates = [x for x in candidates if (x/'artisan').is_file() and (x/'Dockerfile').is_file()]
    if len(candidates) != 1:
        raise ValueError('ZIP must contain exactly one Office root with artisan and Dockerfile.')
    return candidates[0]


def validate_office(office):
    for name in ['artisan', 'Dockerfile', 'VERSION', 'composer.lock', 'bootstrap/providers.php',
                 'docker/Caddyfile', '.env.example', '.env.docker.example']:
        if not (office/name).is_file():
            raise ValueError('Office source is missing '+name)
    version = (office/'VERSION').read_text().strip()
    if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?', version):
        raise ValueError('Invalid Office VERSION.')
    return version


def resolve_source(source, ref, workspace):
    if source.startswith('https://'):
        if not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?', source):
            raise ValueError('Use an HTTPS GitHub repository URL, a local Office folder or ZIP.')
        if not ref or ref.startswith('-') or not re.fullmatch(r'[A-Za-z0-9_./-]+', ref):
            raise ValueError('GitHub source requires --ref with an explicit tag or commit.')
        office = workspace/'source'
        env = dict(os.environ, GIT_TERMINAL_PROMPT='0')
        subprocess.run(['git', 'clone', '--no-checkout', '--', source, str(office)], check=True, env=env)
        subprocess.run(['git', '-C', str(office), 'checkout', '--detach', ref, '--'], check=True, env=env)
        commit = subprocess.check_output(['git', '-C', str(office), 'rev-parse', 'HEAD'], text=True).strip()
        print('Owner source commit:', commit)
        return office
    source = Path(source).resolve()
    if source.is_dir():
        office = workspace/'source'
        # Work on a snapshot. Never rewrite the owner's source or copy secrets.
        shutil.copytree(source, office, symlinks=True,
                        ignore=shutil.ignore_patterns('.git', '.env', '.env.docker', '.env.local',
                                                     'node_modules', 'vendor', 'storage', 'dist',
                                                     'pre-update-backups', '.office-central-fix', '.agents',
                                                     '__pycache__', '*.zip', '*.log'))
        return office
    if source.is_file() and zipfile.is_zipfile(source):
        digest = hashlib.sha256()
        with source.open('rb') as stream:
            for chunk in iter(lambda: stream.read(1024*1024), b''):
                digest.update(chunk)
        print('Owner source SHA256:', digest.hexdigest())
        extracted = workspace/'extracted'
        extracted.mkdir()
        return extract_source(source, extracted)
    raise ValueError('Source must be an Office folder, source ZIP or GitHub repository URL.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['build'])
    parser.add_argument('--source', required=True, help='Owner Office folder, source ZIP or HTTPS GitHub repo.')
    parser.add_argument('--ref', help='Explicit GitHub tag or commit; credentials use Git credential helper.')
    parser.add_argument('--encoder', help='Optional licensed encoder for source protection.')
    parser.add_argument('--loader', help='Required only when --encoder is supplied.')
    parser.add_argument('--arch', choices=['amd64', 'arm64'], default='amd64')
    parser.add_argument('--output', required=True, help='Destination runtime ZIP (must not exist).')
    args = parser.parse_args()
    scripts = Path(__file__).resolve().parent
    with tempfile.TemporaryDirectory(prefix='office-owner-source-') as temporary:
        office = resolve_source(args.source, args.ref, Path(temporary))
        version = validate_office(office)
        # Integrate current agent/licensing code into this disposable snapshot.
        subprocess.run([os.sys.executable, str(scripts/'integrate-office.py'), str(office), '--update'], check=True)
        spec = importlib.util.spec_from_file_location('office_package', scripts/'build-office-package.py')
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        builder.build(argparse.Namespace(office=str(office), encoder=args.encoder, loader=args.loader,
                      arch=args.arch, output=args.output, db='mariadb:10.11.18', redis='redis:7.4.5-alpine',
                      rdp_web='guacamole/guacamole:1.6.0', rdp_core='guacamole/guacd:1.6.0'))
        print('GitHub release asset name: office-runtime-'+version+'-'+args.arch+'.zip')
        print('Upload this runtime ZIP in Central Releases, or attach it to the matching Office GitHub release.')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        raise SystemExit(str(error)) from error
