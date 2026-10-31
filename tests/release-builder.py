#!/usr/bin/env python3
"""Synthetic Git/ZIP tests only. Never changes this repository's Git or the store."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = (ROOT / 'scripts/release-manifest.txt').read_text().splitlines()
ENV = dict(os.environ, GIT_CONFIG_GLOBAL='/dev/null', GIT_CONFIG_NOSYSTEM='1',
           GIT_AUTHOR_NAME='Synthetic Builder Test', GIT_AUTHOR_EMAIL='builder@example.test',
           GIT_COMMITTER_NAME='Synthetic Builder Test', GIT_COMMITTER_EMAIL='builder@example.test',
           GIT_AUTHOR_DATE='2001-02-03T04:05:06Z', GIT_COMMITTER_DATE='2001-02-03T04:05:06Z')

def git(repo, *args):
    return subprocess.check_output(['git', '-C', str(repo), '-c', 'commit.gpgsign=false', *args], env=ENV, stderr=subprocess.PIPE).decode().strip()

def version_files(repo, version):
    (repo / 'jetcredit.php').write_text("<?php\n/**\n * Version: " + version + "\n * License: GPL-3.0-or-later\n * License URI: https://www.gnu.org/licenses/gpl-3.0.html\n */\ndefine( 'JET_VERSION', '" + version + "' );\n")
    (repo / 'readme.txt').write_text('Stable tag: ' + version + '\nLicense: GPL-3.0-or-later\nLicense URI: https://www.gnu.org/licenses/gpl-3.0.html\n')
    (repo / 'CHANGELOG.md').write_text('## [' + version + '] - 2001-02-03\n\nSynthetic release.\n')

class ReleaseBuilder(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        for command in ('git', 'bash', 'php', 'node', 'unzip', 'sha256sum'):
            if not shutil.which(command):
                raise RuntimeError('DEFERRED: required test tool missing: ' + command)
        cls.base_temp = tempfile.TemporaryDirectory(prefix='jet-build-base-')
        cls.base = Path(cls.base_temp.name)
        for name in MANIFEST:
            path = cls.base / name
            path.parent.mkdir(parents=True, exist_ok=True)
            if path.suffix == '.php': data = b'<?php\n// Synthetic runtime fixture.\n'
            elif path.suffix == '.js': data = b"'use strict';\n"
            elif path.suffix == '.png': data = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            elif path.suffix == '.ttf': data = b'\0\1\0\0' + b'\0' * 28
            else: data = b'Synthetic fixture.\n'
            path.write_bytes(data)
        version_files(cls.base, '9.8.7')
        (cls.base / 'LICENSE').write_text('GNU GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007\nSynthetic license test.\n')
        (cls.base / 'composer.json').write_text(json.dumps({'license': 'GPL-3.0-or-later'}))
        (cls.base / 'scripts').mkdir()
        shutil.copy2(ROOT / 'scripts/build-release.sh', cls.base / 'scripts/build-release.sh')
        shutil.copy2(ROOT / 'scripts/release-manifest.txt', cls.base / 'scripts/release-manifest.txt')
        for name in ('tests/private.php', 'vendor/private.php', '.vscode/settings.json', 'debug.log', '.env', 'sbom.cdx.json'):
            path = cls.base / name; path.parent.mkdir(parents=True, exist_ok=True); path.write_text('SYNTHETIC_EXCLUDED\n')
        git(cls.base, 'init', '-q', '-b', 'main')
        git(cls.base, 'add', '.')
        git(cls.base, 'commit', '-qm', 'Synthetic baseline')
        cls.initial = git(cls.base, 'rev-parse', 'HEAD')

    @classmethod
    def tearDownClass(cls):
        cls.base_temp.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='jet-build-test-')
        self.repo = Path(self.temp.name) / 'source'
        shutil.copytree(self.base, self.repo)
        self.out = Path(self.temp.name) / 'output'

    def tearDown(self):
        self.temp.cleanup()

    def commit(self):
        git(self.repo, 'add', '-A'); git(self.repo, 'commit', '-qm', 'Synthetic case')

    def build(self, *args, success=True, env=None, output=None):
        result = subprocess.run(['bash', str(self.repo / 'scripts/build-release.sh'), '--output-dir', str(output or self.out), *args],
                                cwd=self.temp.name, env=dict(ENV, **(env or {})), text=True, capture_output=True, timeout=45)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertFalse(list(self.out.glob('*.zip')))
        return result

    def test_filename_version_root_content_and_exclusions(self):
        result = self.build()
        archive = self.out / 'CC_Woocommerce_JET_v.9.8.7.zip'
        self.assertTrue(archive.is_file())
        with zipfile.ZipFile(archive) as bundle:
            self.assertEqual(bundle.namelist(), ['jetcredit/' + name for name in sorted(MANIFEST)])
            self.assertEqual(len(bundle.namelist()), 42)
            for name in MANIFEST:
                self.assertEqual(bundle.read('jetcredit/' + name), (self.repo / name).read_bytes())
        self.assertIn('Source commit: ' + self.initial, result.stdout)
        self.assertEqual((self.out / (archive.name + '.sha256')).read_text(), hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')

    def test_dirty_and_untracked_files_never_enter_snapshot(self):
        (self.repo / 'jetcredit.php').write_text('BROKEN DIRTY WORKTREE')
        (self.repo / 'includes/untracked.php').write_text('UNTRACKED')
        self.build()
        with zipfile.ZipFile(next(self.out.glob('*.zip'))) as bundle:
            self.assertIn(b'9.8.7', bundle.read('jetcredit/jetcredit.php'))
            self.assertNotIn('jetcredit/includes/untracked.php', bundle.namelist())

    def test_specific_commit_and_annotated_tag(self):
        git(self.repo, 'tag', '-a', 'v9.8.7', self.initial, '-m', 'Synthetic tag')
        version_files(self.repo, '10.12.3'); self.commit()
        self.build('--ref', 'v9.8.7')
        self.build('--ref', self.initial, output=Path(self.temp.name) / 'by-sha')
        self.assertEqual(next(self.out.glob('*.zip')).read_bytes(), next((Path(self.temp.name) / 'by-sha').glob('*.zip')).read_bytes())

    def test_future_version_without_builder_changes(self):
        version_files(self.repo, '10.12.3'); self.commit(); self.build()
        self.assertTrue((self.out / 'CC_Woocommerce_JET_v.10.12.3.zip').is_file())

    def test_repeatable_generation_and_identical_existing_output(self):
        self.build(); original = next(self.out.glob('*.zip')).read_bytes()
        self.build(); other = Path(self.temp.name) / 'repeat'; self.build(output=other)
        self.assertEqual(original, next(other.glob('*.zip')).read_bytes())

    def test_mismatched_version(self):
        path = self.repo / 'readme.txt'; path.write_text(path.read_text().replace('9.8.7', '9.8.6')); self.commit(); self.build(success=False)

    def test_missing_runtime_file(self):
        (self.repo / 'includes/jet-financial-rules.php').unlink(); self.commit(); self.build(success=False)

    def test_empty_file(self):
        (self.repo / 'css/jetcredit_cart.css').write_bytes(b''); self.commit(); self.build(success=False)

    def test_duplicate_manifest(self):
        path = self.repo / 'scripts/release-manifest.txt'; path.write_text(path.read_text() + 'jetcredit.php\n'); self.commit(); self.build(success=False)

    def test_missing_manifest_entry(self):
        path = self.repo / 'scripts/release-manifest.txt'; path.write_text(path.read_text().replace('includes/functions.php\n', '')); self.commit(); self.build(success=False)

    def test_forbidden_manifest_entry(self):
        path = self.repo / 'scripts/release-manifest.txt'; path.write_text(path.read_text() + 'tests/private.php\n'); self.commit(); self.build(success=False)

    def test_unsafe_manifest_path(self):
        path = self.repo / 'scripts/release-manifest.txt'; path.write_text(path.read_text() + '../outside.php\n'); self.commit(); self.build(success=False)

    def test_unexpected_committed_runtime_file(self):
        (self.repo / 'includes/debug.php').write_text('<?php\n'); self.commit(); self.build(success=False)

    def test_symlink(self):
        path = self.repo / 'includes/jet-financial-rules.php'; path.unlink(); path.symlink_to('functions.php'); self.commit(); self.build(success=False)

    def test_corrupt_php(self):
        (self.repo / 'includes/functions.php').write_text('<?php function broken( {'); self.commit(); self.build(success=False)

    def test_corrupt_javascript(self):
        (self.repo / 'js/jetcredit_offers.js').write_text('const = ;'); self.commit(); self.build(success=False)

    def test_corrupt_asset(self):
        (self.repo / 'images/jet.png').write_bytes(b'BROKEN PNG'); self.commit(); self.build(success=False)

    def test_conflicting_license(self):
        path = self.repo / 'jetcredit.php'; path.write_text(path.read_text().replace('GPL-3.0-or-later', 'GPL-2.0-or-later')); self.commit(); self.build(success=False)

    def test_wrong_license_text(self):
        (self.repo / 'LICENSE').write_text('GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n'); self.commit(); self.build(success=False)

    def test_invalid_ref(self):
        self.build('--ref', 'missing-synthetic-tag', success=False)

    def test_existing_corrupt_output_is_not_overwritten(self):
        self.build(); archive = next(self.out.glob('*.zip')); archive.write_bytes(b'CORRUPTED')
        result = subprocess.run(['bash', str(self.repo / 'scripts/build-release.sh'), '--output-dir', str(self.out)], env=ENV, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0); self.assertEqual(archive.read_bytes(), b'CORRUPTED')

    def zip_interceptor(self, mode):
        directory = Path(self.temp.name) / 'tools'; directory.mkdir()
        wrapper = directory / 'unzip'
        real = shutil.which('unzip')
        wrapper.write_text('#!/usr/bin/env python3\nimport os,sys,zipfile\np=sys.argv[-1]\n'
                           + ("open(p,'wb').write(b'CORRUPTED')\n" if mode == 'corrupt' else
                              "with zipfile.ZipFile(p,'a') as z: z.writestr(" + repr('jetcredit/jetcredit.php' if mode == 'duplicate' else 'unexpected/secret.txt') + ",b'UNEXPECTED')\n")
                           + 'os.execv(' + repr(real) + ',[' + repr(real) + ']+sys.argv[1:])\n')
        wrapper.chmod(0o755)
        return {'PATH': str(directory) + os.pathsep + os.environ['PATH']}

    def test_corrupt_zip_rejected(self):
        self.build(env=self.zip_interceptor('corrupt'), success=False)

    def test_unexpected_zip_entry_rejected(self):
        self.build(env=self.zip_interceptor('extra'), success=False)

    def test_duplicate_zip_entry_rejected(self):
        self.build(env=self.zip_interceptor('duplicate'), success=False)

if __name__ == '__main__':
    unittest.main(verbosity=2)
