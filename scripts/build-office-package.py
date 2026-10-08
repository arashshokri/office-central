#!/usr/bin/env python3
"""Build owner-side protected Docker images and an Office runtime ZIP.
Requires Docker and a licensed ionCube PHP 8.4 encoder. Never run on a client.
"""
import argparse, hashlib, json, os, re, shutil, subprocess, tempfile, zipfile
from pathlib import Path

def run(*args, **kwargs):
    return subprocess.run(list(args), check=True, **kwargs)
def sha(path):
    digest=hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda:stream.read(1024*1024),b''): digest.update(chunk)
    return digest.hexdigest()
def build(args):
    destination=Path(args.output).resolve()
    if destination.exists():raise ValueError('Refusing to overwrite an existing release archive.')
    office=Path(args.office).resolve()
    version=(office/'VERSION').read_text().strip()
    if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[\w.-]+)?',version): raise ValueError('Invalid Office version.')
    encoder=Path(args.encoder).resolve()
    loader=Path(args.loader).resolve()
    if not encoder.is_file() or not loader.is_file(): raise ValueError('Licensed encoder and matching Linux PHP 8.4 ZTS loader are required.')
    if loader.name != 'ioncube_loader_lin_8.4_ts.so': raise ValueError('FrankenPHP uses ZTS PHP; provide ioncube_loader_lin_8.4_ts.so for the target architecture.')
    if not (office/'app/Providers/OfficeLicenseServiceProvider.php').is_file(): raise ValueError('Office licensing integration is missing.')
    raw_image='office-owner-build:'+version
    protected_image='office-customer:'+version
    with tempfile.TemporaryDirectory(prefix='office-protected-') as temp:
        work=Path(temp); plain=work/'plain';plain.mkdir()
        for folder in ['app','bootstrap','config','database','lang','routes']:
            shutil.copytree(office/folder,plain/folder,ignore=shutil.ignore_patterns('cache','*.sqlite','*.sqlite-*'))
        for name in ['artisan','composer.json','composer.lock','VERSION','RELEASE.json']:
            if (office/name).is_file():shutil.copy2(office/name,plain/name)
        shutil.copytree(office/'public',plain/'public',ignore=shutil.ignore_patterns('build','storage','hot'))
        for file in (office/'resources/views').rglob('*'):
            if file.is_file():
                dest=plain/'resources/views'/file.relative_to(office/'resources/views')
                dest.parent.mkdir(parents=True,exist_ok=True)
                # Preserve view lookup paths; compiled templates carry the code.
                dest.write_text('Protected view; use the precompiled runtime image.')
        (plain/'docker').mkdir()
        for name in ['Caddyfile','php.ini','run-messenger-bots.sh']:
            shutil.copy2(office/'docker'/name,plain/'docker'/name)
        run('docker','build','--target','production','--build-arg','APP_RELEASE_VERSION='+version,'-t',raw_image,str(office))
        # Compile Blade at its actual production paths before removing its
        # readable template sources. Exited container never ships to customers.
        container=run('docker','create','--entrypoint','sh',raw_image,'-c','php artisan view:cache',
                      capture_output=True,text=True).stdout.strip()
        try:
            run('docker','start','-a',container)
            result=json.loads(run('docker','inspect',container,capture_output=True,text=True).stdout)[0]
            if result['State']['ExitCode']!=0:raise RuntimeError('Blade precompilation failed.')
            (plain/'protected-views').mkdir()
            run('docker','cp',container+':/app/storage/framework/views/.',str(plain/'protected-views'))
        finally:run('docker','rm',container)
        encoded=work/'encoded'
        run(str(encoder),'-84','--ascii','--encode','artisan',str(plain),'-o',str(encoded))
        # Check every proprietary PHP file and every compiled view, not merely
        # a caller-supplied manifest label.
        for file in [*plain.rglob('*.php'),plain/'artisan']:
            protected=encoded/file.relative_to(plain)
            content=protected.read_bytes()
            if content==file.read_bytes() or b'ionCube' not in content[:4096]:
                raise ValueError('Encoder did not protect '+str(file.relative_to(plain)))
        (encoded/'office-managed').write_text('office-agent/v2\n')
        loader_dir=work/'loader';loader_dir.mkdir()
        shutil.copy2(loader,loader_dir/'ioncube_loader_lin_8.4_ts.so')
        run('docker','build','--target','customer','--build-context','encoded='+str(encoded),
            '--build-context','loader='+str(loader_dir),'--build-arg','APP_RELEASE_VERSION='+version,
            '-t',protected_image,str(office))
        # No unencoded app layer is an ancestor of the customer target.
        run('docker','run','--rm','--entrypoint','php',protected_image,'-r',
            "if(!extension_loaded('ionCube Loader') || !function_exists('sodium_crypto_sign_verify_detached')) exit(1); require '/app/vendor/autoload.php'; require '/app/bootstrap/app.php';")
        images={'app':protected_image,'db':args.db,'redis':args.redis,'rdp-web':args.rdp_web,'rdp-core':args.rdp_core}
        manifest={'format':'office-runtime-v1','product':'office','version':version,
                  'architecture':args.arch,'source_protection':'ioncube','images':[]}
        for role,ref in images.items():
            if role!='app':run('docker','pull','--platform','linux/'+args.arch,ref)
            info=json.loads(run('docker','image','inspect',ref,capture_output=True,text=True).stdout)[0]
            if info['Architecture']!=args.arch:raise ValueError('Wrong image architecture: '+ref)
            archive=work/(role+'.tar')
            run('docker','save','-o',str(archive),ref)
            manifest['images'].append({'role':role,'ref':ref,'image_id':info['Id'],
                                      'archive':'images/'+role+'.tar','sha256':sha(archive)})
        destination.parent.mkdir(parents=True,exist_ok=True)
        # Publish only a finished archive. An interrupted build leaves no
        # apparently installable ZIP and never replaces an existing release.
        with tempfile.NamedTemporaryFile(dir=destination.parent,prefix='.office-runtime-',suffix='.partial',delete=False) as stream:
            pending=Path(stream.name)
        try:
            with zipfile.ZipFile(pending,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=1,allowZip64=True) as bundle:
                bundle.writestr('manifest.json',json.dumps(manifest,ensure_ascii=False,separators=(',',':')))
                for role in images:bundle.write(work/(role+'.tar'),'images/'+role+'.tar')
            os.link(pending,destination)
        finally:pending.unlink(missing_ok=True)
        print('Bundle:',destination)
        print('SHA256:',sha(destination))
        print('Release:',version)
if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--office',required=True)
    parser.add_argument('--encoder',required=True,help='Licensed ionCube CLI supporting -84.')
    parser.add_argument('--loader',required=True,help='Matching PHP 8.4 Linux ZTS loader ioncube_loader_lin_8.4_ts.so.')
    parser.add_argument('--output',required=True)
    parser.add_argument('--arch',choices=['amd64','arm64'],default='amd64')
    parser.add_argument('--db',default='mariadb:10.11.18')
    parser.add_argument('--redis',default='redis:7.4.5-alpine')
    parser.add_argument('--rdp-web',default='guacamole/guacamole:1.6.0')
    parser.add_argument('--rdp-core',default='guacamole/guacd:1.6.0')
    build(parser.parse_args())
