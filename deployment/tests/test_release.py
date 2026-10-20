import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from contextlib import ExitStack
from types import SimpleNamespace


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).resolve().parents[1] / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


gateway = load('gateway', 'release-gateway.py')
validation = load('validation', 'validate-release.py')


class ReleaseSafeguards(unittest.TestCase):
    def request(self, **changes):
        return {'action': 'deploy', 'backend': 'a'*40, 'frontend': 'b'*40,
                'release': '123-1', 'allow_migrations': False, 'size': 3,
                'sha256': hashlib.sha256(b'abc').hexdigest(), **changes}

    def test_exact_commits_and_explicit_migration_policy(self):
        gateway.validate_request(self.request())
        for changes in [{'backend': 'main'}, {'frontend': 'a'*40+';id'},
                        {'release': '../production'}, {'allow_migrations': 'false'},
                        {'action': 'shell'}, {'size': gateway.MAX_UPLOAD+1}, {'size': True},
                        {'sha256': 'invalid'}]:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                gateway.validate_request(self.request(**changes))

    def test_stream_checksum_and_truncation(self):
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder)/'release.tar'
            gateway.receive(io.BytesIO(b'abc'), self.request(), target)
            self.assertEqual(target.read_bytes(), b'abc')
            with self.assertRaises(ValueError):
                gateway.receive(io.BytesIO(b'ab'), self.request(), Path(folder)/'short')
            with self.assertRaises(ValueError):
                gateway.receive(io.BytesIO(b'bad'), self.request(), Path(folder)/'wrong')

    def test_archive_rejects_traversal_links_and_unexpected_files(self):
        for name, kind in [('../escape', tarfile.REGTYPE), ('/etc/passwd', tarfile.REGTYPE),
                           ('vendor/link', tarfile.SYMTYPE), ('vendor/link', tarfile.LNKTYPE),
                           ('vendor/pipe', tarfile.FIFOTYPE), ('vendor\\escape', tarfile.REGTYPE),
                           ('.env', tarfile.REGTYPE)]:
            with self.subTest(name=name,kind=kind), tempfile.TemporaryDirectory() as folder:
                archive = Path(folder)/'bad.tar'
                with tarfile.open(archive, 'w') as tar:
                    entry = tarfile.TarInfo(name)
                    entry.type = kind
                    entry.linkname = '/etc/passwd'
                    tar.addfile(entry)
                with self.assertRaises(ValueError):
                    gateway.safe_extract(archive, Path(folder)/'output', prefix='vendor')

    def test_archive_accepts_normal_files(self):
        with tempfile.TemporaryDirectory() as folder:
            archive = Path(folder)/'good.tar'
            with tarfile.open(archive,'w') as tar:
                entry = tarfile.TarInfo('vendor/autoload.php')
                entry.size = 3
                tar.addfile(entry,io.BytesIO(b'php'))
            gateway.safe_extract(archive, Path(folder)/'output', prefix='vendor')
            self.assertEqual((Path(folder)/'output/vendor/autoload.php').read_bytes(),b'php')

    def test_production_gate_fails_closed(self):
        gate = {'protection_rules':[{'type':'required_reviewers','reviewers':[{'id':1}]}],
                'can_admins_bypass':False,'deployment_branch_policy':{'custom_branch_policies':True}}
        branches = {'branch_policies':[{'name':'main','type':'branch'}]}
        validation.check_gate(gate, branches)
        for modified in [{**gate,'protection_rules':[]}, {**gate,'can_admins_bypass':True},
                         {**gate,'can_admins_bypass':None}, {**gate,'deployment_branch_policy':None}]:
            with self.assertRaises(ValueError):
                validation.check_gate(modified,branches)
        for modified in [{'branch_policies':[]}, {'branch_policies':[{'name':'*','type':'branch'}]},
                         {'branch_policies':[{'name':'main','type':'tag'}]}]:
            with self.assertRaises(ValueError):
                validation.check_gate(gate,modified)

    @unittest.skipIf(gateway.fcntl is None, 'Linux filesystem deployment check')
    def test_dirty_server_stops_before_package_or_database_changes(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(gateway,'ROOT',Path(folder)), \
                patch.object(gateway,'dirty_files',return_value=' M routes/api.php'), \
                patch.object(gateway,'receive') as receive, patch.object(gateway,'database_backup') as backup:
            with self.assertRaisesRegex(RuntimeError,'Reconcile live source'):
                gateway.deploy(self.request(),io.BytesIO(b'abc'))
            receive.assert_not_called()
            backup.assert_not_called()

    @unittest.skipIf(gateway.fcntl is None, 'Linux release integration check')
    def test_release_activation_and_failure_recovery(self):
        for failure in [None, 'health', 'migration']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as folder, ExitStack() as stack:
                base = Path(folder)
                api, web, releases = base/'live-api', base/'live-web', base/'releases'
                for path in [api/'storage', web/'dist']:
                    path.mkdir(parents=True)
                (api/'.env').write_text('PRODUCTION_CONFIGURATION')
                (api/'storage/record.txt').write_text('KEEP_PRODUCTION_RECORD')
                (web/'dist/index.html').write_text('previous frontend')
                packet = io.BytesIO()
                with tarfile.open(fileobj=packet,mode='w') as outer:
                    for name, files in [('backend-vendor.tar.gz',{'vendor/autoload.php':'php'}),
                                        ('frontend-dist.tar.gz',{'dist/index.html':'new frontend','dist/admin/index.html':'admin'})]:
                        inner = io.BytesIO()
                        with tarfile.open(fileobj=inner,mode='w:gz') as archive:
                            for filename, content in files.items():
                                entry = tarfile.TarInfo(filename)
                                entry.size = len(content)
                                archive.addfile(entry,io.BytesIO(content.encode()))
                        entry = tarfile.TarInfo(name)
                        entry.size = len(inner.getvalue())
                        outer.addfile(entry,io.BytesIO(inner.getvalue()))
                payload = packet.getvalue()
                request = self.request(size=len(payload),sha256=hashlib.sha256(payload).hexdigest(),allow_migrations=True)
                calls = []
                def command(args, **kwargs):
                    calls.append(args)
                    if args[:2] == ['git','clone']:
                        target = Path(args[-1])
                        (target/'public').mkdir(parents=True)
                        (target/'storage').mkdir()
                    if failure == 'migration' and 'migrate' in args:
                        raise RuntimeError('simulated migration failure')
                    return ''
                def git(root,*args):
                    if args[0] == 'ls-tree':
                        return 'database/migrations/2026_01_01_change.php' if failure == 'migration' else ''
                    return 'a'*40 if args[0] == 'rev-parse' else ''
                for name,value in [('ROOT',releases),('API',api),('WEB',web),('LOG',None)]:
                    stack.enter_context(patch.object(gateway,name,value))
                stack.enter_context(patch.object(gateway,'dirty_files',return_value=''))
                stack.enter_context(patch.object(gateway,'git',side_effect=git))
                stack.enter_context(patch.object(gateway,'run',side_effect=command))
                stack.enter_context(patch.object(gateway,'laravel',return_value=[]))
                stack.enter_context(patch.object(gateway,'chown_tree'))
                stack.enter_context(patch.object(gateway.subprocess,'run',return_value=SimpleNamespace(returncode=0)))
                stack.enter_context(patch.object(gateway,'database_backup',side_effect=lambda p:p.write_bytes(b'backup')))
                stack.enter_context(patch.object(gateway,'health',side_effect=RuntimeError('simulated health failure') if failure=='health' else None))
                if failure:
                    with self.assertRaises(RuntimeError):
                        gateway.deploy(request,io.BytesIO(payload))
                else:
                    gateway.deploy(request,io.BytesIO(payload))
                manifest = json.loads((releases/'123-1/manifest.json').read_text())
                self.assertEqual((api/'storage/record.txt').read_text(),'KEEP_PRODUCTION_RECORD')
                self.assertEqual((api/'.env').read_text(),'PRODUCTION_CONFIGURATION')
                if failure == 'health':
                    self.assertEqual(manifest['status'],'failed_application_restored')
                    self.assertEqual((web/'dist/index.html').read_text(),'previous frontend')
                elif failure == 'migration':
                    self.assertEqual(manifest['status'],'failed_database_review_required')
                    self.assertTrue(manifest['database_changed'])
                    self.assertFalse(any(c[:2] == ['systemctl','start'] for c in calls))
                else:
                    self.assertEqual(manifest['status'],'successful')
                    self.assertEqual((web/'dist/index.html').read_text(),'new frontend')
    @unittest.skipIf(gateway.fcntl is None, 'Linux filesystem deployment check')
    def test_pending_migrations_require_opt_in_before_changes(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(gateway,'ROOT',Path(folder)), \
                patch.object(gateway,'dirty_files',return_value=''), \
                patch.object(gateway,'git',side_effect=['','','','','database/migrations/2026_01_01_change.php']), \
                patch.object(gateway,'laravel',return_value=[]), patch.object(gateway,'receive') as receive:
            with self.assertRaisesRegex(RuntimeError,'compatibility review'):
                gateway.deploy(self.request(),io.BytesIO(b'abc'))
            receive.assert_not_called()


if __name__ == '__main__':
    unittest.main()
