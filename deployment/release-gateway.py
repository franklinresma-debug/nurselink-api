#!/usr/bin/env python3
"""Root-owned, forced-command deployment gateway. No shell or arbitrary paths.

stdin: one JSON request line followed by the exact-size release tar.
Installed separately from application source; application updates cannot edit it.
"""
try:
    import fcntl
except ImportError:  # Pure validation tests also run on Windows.
    fcntl = None
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
try:
    import pwd
except ImportError:
    pwd = None
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

ROOT = Path('/var/www/lbs-nurselink-releases')
API = Path('/var/www/lbs-nurselink-api')
WEB = Path('/var/www/lbs-nurselink-web')
REPOS = {'api': 'https://github.com/franklinresma-debug/nurselink-api.git',
         'web': 'https://github.com/franklinresma-debug/lbs-nurselink-web.git'}
MAX_UPLOAD = 512 * 1024 * 1024
SERVICES = ['lbs-nurselink-queue.service', 'lbs-nurselink-scheduler.timer']
LOG = None


def validate_request(value):
    if value.get('action') not in ['plan', 'deploy']:
        raise ValueError('Only plan and deploy are supported')
    for name in ['backend', 'frontend']:
        if not re.fullmatch(r'[0-9a-f]{40}', value.get(name, '')):
            raise ValueError('Full lowercase commit IDs are required')
    if not re.fullmatch(r'[0-9]{1,20}-[0-9]{1,4}', value.get('release', '')):
        raise ValueError('Invalid release identifier')
    if type(value.get('allow_migrations')) is not bool:
        raise ValueError('Explicit migration policy required')
    if value['action'] == 'deploy':
        if type(value.get('size')) is not int or not 0 < value['size'] <= MAX_UPLOAD:
            raise ValueError('Invalid package size')
        if not re.fullmatch(r'[0-9a-f]{64}', value.get('sha256', '')):
            raise ValueError('Package checksum required')
    return value


def run(args, cwd=None, app=False, input_data=None, log_output=True):
    if app:
        args = ['/usr/sbin/runuser', '-u', 'www-data', '--', *args]
    result = subprocess.run(args, cwd=cwd, input=input_data, capture_output=True, timeout=900)
    if LOG and log_output:
        with LOG.open('ab') as log:
            log.write(result.stdout + result.stderr)
    if result.returncode:
        raise RuntimeError('Command failed: ' + Path(args[0]).name)
    return result.stdout.decode().strip()


def git(root, *args):
    return run(['git', '-c', 'safe.directory=' + str(root), '-C', str(root), *args], app=True)


def dirty_files(root):
    # Only shared runtime paths are excluded. Source drift always blocks release.
    return git(root, 'status', '--porcelain', '--untracked-files=all', '--', '.',
               ':(exclude)storage', ':(exclude)public/storage', ':(exclude).phpunit.result.cache')


def safe_extract(archive, destination, prefix=None, allowed=None):
    with tarfile.open(archive) as package:
        members = package.getmembers()
        if len(members) > 100000 or sum(m.size for m in members) > 2 * 1024**3:
            raise ValueError('Expanded package too large')
        seen = set()
        for member in members:
            path = PurePosixPath(member.name)
            if path.is_absolute() or '..' in path.parts or not path.parts or '\\' in member.name:
                raise ValueError('Unsafe archive path')
            if member.name in seen or not (member.isfile() or member.isdir()):
                raise ValueError('Links, duplicate entries and special files are forbidden')
            seen.add(member.name)
            if prefix and path.parts[0] != prefix:
                raise ValueError('Unexpected package directory')
            if allowed is not None and member.name not in allowed:
                raise ValueError('Unexpected bundle member')
        package.extractall(destination, members=members, filter='data')


def receive(stream, request, target):
    digest = hashlib.sha256()
    remaining = request['size']
    with target.open('xb') as output:
        while remaining:
            chunk = stream.read(min(1024 * 1024, remaining))
            if not chunk:
                raise ValueError('Truncated package')
            remaining -= len(chunk)
            digest.update(chunk)
            output.write(chunk)
    if digest.hexdigest() != request['sha256']:
        raise ValueError('Package checksum does not match')


def laravel(root, expression):
    script = "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); " + expression
    return json.loads(run(['/usr/bin/php', '-r', script], cwd=root, app=True, log_output=False))


def database_backup(destination):
    connection = laravel(API, "echo json_encode(config('database.connections.mysql'));")
    if connection.get('database') != 'lbs_nurselink_production':
        raise ValueError('Unexpected production database')
    def quote(value):
        return '"' + str(value).replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r') + '"'
    with tempfile.TemporaryDirectory(prefix='lbs-release-', dir='/run') as runtime:
        config = Path(runtime) / 'mysql.cnf'
        config.write_text('[client]\n' + '\n'.join(key + '=' + quote(value) for key, value in {
            'host': connection.get('host', '127.0.0.1'), 'port': connection.get('port', 3306),
            'user': connection['username'], 'password': connection.get('password', ''),
        }.items()) + '\n')
        config.chmod(0o600)
        with gzip.open(destination, 'wb') as output, tempfile.TemporaryFile() as errors:
            process = subprocess.Popen(['/usr/bin/mysqldump', '--defaults-extra-file=' + str(config),
                                        '--single-transaction', '--routines', '--triggers', '--events',
                                        '--no-tablespaces', connection['database']], stdout=subprocess.PIPE, stderr=errors)
            shutil.copyfileobj(process.stdout, output)
            if process.wait(timeout=300):
                raise RuntimeError('Database backup failed; release stopped')
    with gzip.open(destination, 'rb') as backup:
        if not backup.read(1024):
            raise RuntimeError('Empty database backup')


def chown_tree(root):
    account = pwd.getpwnam('www-data')
    for folder, dirs, files in os.walk(root, followlinks=False):
        os.chown(folder, account.pw_uid, account.pw_gid)
        for name in dirs + files:
            os.lchown(Path(folder) / name, account.pw_uid, account.pw_gid)


def previous_target(path, release):
    return path.resolve() if path.is_symlink() else ROOT / ('legacy-' + path.name + '-' + release)


def switch(path, target, legacy):
    temporary = path.with_name(path.name + '.release-next')
    if temporary.exists() or temporary.is_symlink():
        raise ValueError('A previous link switch requires inspection')
    moved = False
    if path.exists() and not path.is_symlink():
        path.rename(legacy)
        moved = True
    try:
        temporary.symlink_to(target, target_is_directory=True)
        temporary.replace(path)
    except Exception:
        if moved:
            legacy.rename(path)
        raise


def health():
    for suffix in ['/', '/admin/', '/backend/api/health', '/backend/api/nurselink/appearance-settings']:
        for attempt in range(5):
            try:
                with urllib.request.urlopen('https://lbs.amsertech.com' + suffix, timeout=20) as response:
                    body = response.read()
                    if response.status != 200:
                        raise RuntimeError('Unexpected HTTP response')
                    if suffix.endswith('appearance-settings'):
                        logo = json.loads(body)['data']['branding']['logos']
                        source = (logo.get('sidebar') or logo.get('admin') or {}).get('source', '')
                        if not re.fullmatch(r'/api/nurselink/appearance-assets/[A-Za-z0-9._-]+', source):
                            raise RuntimeError('Published LBS logo is missing')
                        with urllib.request.urlopen('https://lbs.amsertech.com/backend' + source, timeout=20) as image:
                            if not image.headers.get('Content-Type', '').startswith('image/'):
                                raise RuntimeError('Published LBS logo failed')
                    break
            except Exception:
                if attempt == 4:
                    raise
                time.sleep(2)


def deploy(request, stream):
    global LOG
    ROOT.mkdir(mode=0o755, exist_ok=True)
    ROOT.chmod(0o755)
    with (ROOT / '.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        for name, path in [('api', API), ('web', WEB)]:
            if path.is_symlink() and not path.resolve().is_relative_to(ROOT):
                raise ValueError('Unexpected application symlink')
            drift = dirty_files(path)
            if drift:
                print(f'BLOCKED: uncommitted {name} source changes:\n{drift}', flush=True)
                raise RuntimeError('Reconcile live source changes before deploying')
            git(path, 'fetch', '--no-tags', REPOS[name], 'main')
            sha = request['backend' if name == 'api' else 'frontend']
            git(path, 'merge-base', '--is-ancestor', sha, 'FETCH_HEAD')
        ran = laravel(API, "echo json_encode(app('migrator')->getRepository()->getRan());")
        migration_files = git(API, 'ls-tree', '-r', '--name-only', request['backend'], 'database/migrations').splitlines()
        pending = sorted(Path(name).stem for name in migration_files if name.endswith('.php') and Path(name).stem not in ran)
        print(json.dumps({'pending_migrations': pending, 'backend': request['backend'], 'frontend': request['frontend']}), flush=True)
        if pending and not request['allow_migrations']:
            raise RuntimeError('Pending migrations require explicit compatibility review')
        if request['action'] == 'plan':
            print('Preflight passed. No application files changed.')
            return
        release = ROOT / request['release']
        release.mkdir(mode=0o755)
        release.chmod(0o755)
        LOG = release / 'deployment.log'
        LOG.touch(mode=0o600)
        packet = release / 'release.tar'
        receive(stream, request, packet)
        safe_extract(packet, release, allowed={'backend-vendor.tar.gz', 'frontend-dist.tar.gz'})
        old = {'api': previous_target(API, request['release']), 'web': previous_target(WEB, request['release'])}
        for name in ['api', 'web']:
            stage = release / name
            run(['git', 'clone', '--no-checkout', REPOS[name], str(stage)])
            sha = request['backend' if name == 'api' else 'frontend']
            run(['git', '-C', str(stage), 'checkout', '--detach', sha])
            chown_tree(stage)
        stage_api, stage_web = release / 'api', release / 'web'
        # These paths are fresh clones, never a production directory.
        storage = stage_api / 'storage'
        if storage.is_symlink():
            storage.unlink()
        elif storage.exists():
            shutil.rmtree(storage)
        shared_storage = (API / 'storage').resolve() if API.is_symlink() else old['api'] / 'storage'
        shared_environment = (API / '.env').resolve() if API.is_symlink() else old['api'] / '.env'
        storage.symlink_to(shared_storage, target_is_directory=True)
        (stage_api / '.env').symlink_to(shared_environment)
        public_storage = stage_api / 'public/storage'
        if public_storage.is_symlink():
            public_storage.unlink()
        elif public_storage.exists():
            raise ValueError('Unexpected public storage directory in source')
        public_storage.symlink_to('../storage/app/public', target_is_directory=True)
        safe_extract(release / 'backend-vendor.tar.gz', stage_api, prefix='vendor')
        safe_extract(release / 'frontend-dist.tar.gz', stage_web, prefix='dist')
        for required in [stage_api / 'vendor/autoload.php', stage_web / 'dist/index.html', stage_web / 'dist/admin/index.html']:
            if not required.is_file():
                raise ValueError('Incomplete release package')
        chown_tree(stage_api)
        chown_tree(stage_web)
        # Do not include application output or configuration in GitHub logs.
        state = {**request, 'previous_paths': {k: str(v) for k,v in old.items()},
                 'previous_commits': {'api': git(API, 'rev-parse', 'HEAD'), 'web': git(WEB, 'rev-parse', 'HEAD')},
                 'pending_migrations': pending, 'status': 'prepared', 'database_changed': False}
        manifest = release / 'manifest.json'
        def save():
            manifest.write_text(json.dumps(state, indent=2) + '\n')
            manifest.chmod(0o600)
        save()
        active = [s for s in SERVICES if subprocess.run(['systemctl', 'is-active', '--quiet', s]).returncode == 0]
        switched = []
        maintenance = False
        try:
            run(['/usr/bin/php', 'artisan', 'down', '--retry=60'], cwd=API, app=True)
            maintenance = True
            for service in active:
                run(['systemctl', 'stop', service])
            database_backup(release / 'database.sql.gz')
            (release / 'database.sql.gz').chmod(0o600)
            for name, path in [('api', API), ('web', WEB)]:
                switch(path, release / name, old[name])
                switched.append(name)
            if pending:
                # A failed migration may already have changed MySQL schema.
                state['database_changed'] = True
                save()
                run(['/usr/bin/php', 'artisan', 'migrate', '--force'], cwd=API, app=True)
            run(['/usr/bin/php', 'artisan', 'optimize'], cwd=API, app=True)
            run(['systemctl', 'reload', 'php8.4-fpm.service'])
            run(['/usr/bin/php', 'artisan', 'up'], cwd=API, app=True)
            maintenance = False
            health()
            for service in active:
                run(['systemctl', 'start', service])
                run(['systemctl', 'is-active', '--quiet', service])
            state['status'] = 'successful'
            save()
            print('Release successful: ' + request['release'], flush=True)
        except Exception:
            if not state['database_changed']:
                for service in active:
                    run(['systemctl', 'stop', service])
                for name in reversed(switched):
                    switch(API if name == 'api' else WEB, old[name], old[name])
                run(['/usr/bin/php', 'artisan', 'view:clear'], cwd=API, app=True)
                run(['systemctl', 'reload', 'php8.4-fpm.service'])
                if maintenance or switched:
                    run(['/usr/bin/php', 'artisan', 'up'], cwd=API, app=True)
                for service in active:
                    run(['systemctl', 'start', service])
                state['status'] = 'failed_application_restored'
            else:
                run(['/usr/bin/php', 'artisan', 'down', '--retry=60'], cwd=API, app=True)
                for service in active:
                    run(['systemctl', 'stop', service])
                state['status'] = 'failed_database_review_required'
            save()
            raise


def main():
    if fcntl is None or os.geteuid() != 0:
        raise RuntimeError('The installed gateway requires its Linux service account')
    os.umask(0o077)
    line = sys.stdin.buffer.readline(4097)
    if len(line) > 4096 or not line.endswith(b'\n'):
        raise ValueError('Invalid request header')
    request = validate_request(json.loads(line))
    deploy(request, sys.stdin.buffer)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('RELEASE STOPPED: ' + str(error), file=sys.stderr)
        if LOG:
            print('Private server log: ' + str(LOG), file=sys.stderr)
        sys.exit(1)
