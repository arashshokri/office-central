"""Owner source handling: reject unsafe archives and preserve original files."""
import importlib.util
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('office_helper', ROOT/'scripts/office-helper.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class OfficeSourceTest(unittest.TestCase):
    def test_standalone_integration_kit_contains_the_reactivation_view(self):
        kit = ROOT/'integrations/office'
        if not kit.is_dir():
            kit = ROOT/'agent/integration'
        self.assertTrue((kit/'resources/views/office-agent/locked.blade.php').is_file())

    def test_zip_accepts_single_github_style_root(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            with zipfile.ZipFile(root/'source.zip', 'w') as bundle:
                bundle.writestr('office-v1/artisan', '<?php')
                bundle.writestr('office-v1/Dockerfile', 'FROM scratch')
            destination = root/'out'
            destination.mkdir()
            self.assertEqual(helper.extract_source(root/'source.zip', destination), destination/'office-v1')

    def test_traversal_windows_path_and_symlink_are_rejected(self):
        for name in ['../escape', '/escape', 'C:/escape', 'root\\escape', 'a/./b', 'a//b', 'file:stream']:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                with zipfile.ZipFile(root/'bad.zip', 'w') as bundle:
                    entry = zipfile.ZipInfo()
                    entry.filename = entry.orig_filename = name
                    bundle.writestr(entry, 'bad')
                with self.assertRaises(ValueError):
                    helper.extract_source(root/'bad.zip', root/'out')
                self.assertFalse((root/'out').exists())
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            with zipfile.ZipFile(root/'bad.zip', 'w') as bundle:
                entry = zipfile.ZipInfo('link')
                entry.external_attr = (stat.S_IFLNK | 0o777) << 16
                bundle.writestr(entry, '/etc/passwd')
            with self.assertRaises(ValueError):
                helper.extract_source(root/'bad.zip', root/'out')

    def test_folder_source_is_snapshotted_without_existing_credentials(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root/'owner'
            source.mkdir()
            (source/'VERSION').write_text('3.8.19')
            (source/'.env').write_text('SECRET=fixture')
            (source/'.env.docker').write_text('SECRET=fixture')
            (source/'.env.example').write_text('SECRET=')
            stage = helper.resolve_source(str(source), None, root/'work')
            self.assertFalse((stage/'.env').exists())
            self.assertFalse((stage/'.env.docker').exists())
            self.assertTrue((stage/'.env.example').is_file())
            (stage/'VERSION').write_text('changed')
            self.assertEqual((source/'VERSION').read_text(), '3.8.19')

    def test_paths_that_collide_on_case_insensitive_filesystems_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            with zipfile.ZipFile(root/'bad.zip', 'w') as bundle:
                bundle.writestr('app/User.php', 'first')
                bundle.writestr('app/user.php', 'second')
            with self.assertRaises(ValueError):
                helper.extract_source(root/'bad.zip', root/'out')
            self.assertFalse((root/'out').exists())

    def test_remote_source_requires_explicit_ref_and_github_origin(self):
        for source, ref in [('https://github.com/owner/office', None),
                            ('https://127.0.0.1/owner/office', 'v1.0.0'),
                            ('https://github.com/owner/office', '--upload-pack=command')]:
            with self.subTest(source=source, ref=ref), tempfile.TemporaryDirectory() as temp:
                with self.assertRaises(ValueError):
                    helper.resolve_source(source, ref, Path(temp))


if __name__ == '__main__':
    unittest.main()
