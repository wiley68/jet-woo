#!/usr/bin/env bash
# Build committed sources only. See RELEASE.md for dependencies and examples.
set -Eeuo pipefail
umask 077
export LC_ALL=C TZ=UTC GIT_OPTIONAL_LOCKS=0

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo="$(git -C "$script_dir/.." rev-parse --show-toplevel)"
ref=HEAD
output_dir="$repo/dist"
while (( $# )); do
    case "$1" in
        --ref|--output-dir)
            (( $# >= 2 )) || { echo "Missing value for $1" >&2; exit 1; }
            if [[ "$1" == --ref ]]; then ref="$2"; else output_dir="$2"; fi
            shift 2 ;;
        -h|--help)
            echo 'Usage: scripts/build-release.sh [--ref COMMIT_OR_TAG] [--output-dir DIRECTORY]'
            echo 'Default: committed HEAD, output to dist/. Dirty/untracked source files are never packaged.'
            exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done
for tool in git python3 unzip sha256sum "${PHP_BIN:-php}" "${NODE_BIN:-node}"; do
    command -v "$tool" >/dev/null || { echo "Required tool missing: $tool" >&2; exit 1; }
done
commit="$(git -C "$repo" rev-parse --verify --end-of-options "${ref}^{commit}")"
staging="$(mktemp -d "${TMPDIR:-/tmp}/jetcredit-release.XXXXXXXX")"
trap 'rm -rf -- "$staging"' EXIT
python3 - "$repo" "$commit" "$output_dir" "$staging" "${PHP_BIN:-php}" "${NODE_BIN:-node}" <<'PY'
import datetime
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import zipfile

repo, commit, destination, temporary, php, node = sys.argv[1:]
stage = Path(temporary)

def git(*args):
    return subprocess.check_output(['git', '-C', repo, *args], stderr=subprocess.PIPE)

def require(condition, message):
    if not condition:
        raise ValueError(message)

def one(pattern, text, label):
    matches = re.findall(pattern, text, re.MULTILINE)
    require(len(matches) == 1, 'Missing or ambiguous ' + label)
    return matches[0]

try:
    tree = {}
    for record in git('ls-tree', '-rz', commit).split(b'\0'):
        if record:
            fields, name = record.split(b'\t', 1)
            mode, kind, oid = fields.decode('ascii').split()
            tree[name.decode('utf-8')] = (mode, kind, oid)

    def blob(name):
        require(name in tree, 'Missing committed file: ' + name)
        mode, kind, oid = tree[name]
        require(mode in ('100644', '100755') and kind == 'blob', 'Not a regular committed file: ' + name)
        return git('cat-file', 'blob', oid)

    manifest = [line.strip() for line in blob('scripts/release-manifest.txt').decode('utf-8').splitlines()
                if line.strip() and not line.lstrip().startswith('#')]
    require(manifest and len(manifest) == len(set(manifest)), 'Empty manifest or duplicate paths')
    root_files = {'jetcredit.php', 'uninstall.php', 'class-jet-payment-gateway-blocks.php',
                  'class-jet-card-payment-gateway-blocks.php', 'checkout.js', 'checkout_card.js',
                  'readme.txt', 'CHANGELOG.md', 'LICENSE'}
    runtime_dirs = {'includes', 'js', 'css', 'fonts', 'images'}
    for name in manifest:
        path = PurePosixPath(name)
        require(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/-]*', name) and '..' not in path.parts
                and str(path) == name and not path.is_absolute(), 'Unsafe manifest path: ' + name)
        require(name in root_files or (len(path.parts) > 1 and path.parts[0] in runtime_dirs),
                'Forbidden manifest entry: ' + name)
        allowed_suffixes = {'.php', '.js', '.css', '.ttf', '.png'}
        require(name in root_files or path.suffix in allowed_suffixes, 'Unexpected manifest file: ' + name)
    required = root_files | {'includes/jet-financial-rules.php', 'includes/jet-financial-order.php',
                            'includes/jet-verified-offer.php', 'js/jetcredit_offers.js', 'js/jetcredit_blocks.js'}
    require(required <= set(manifest), 'Required release files missing from manifest')
    for name in tree:
        path = PurePosixPath(name)
        is_runtime = path.parts[0] in runtime_dirs or (len(path.parts) == 1 and path.suffix in ('.php', '.js', '.css'))
        require(not is_runtime or name in manifest, 'Unexpected committed runtime file: ' + name)

    contents = {name: blob(name) for name in manifest}
    require(all(contents.values()), 'Empty release file')
    header = contents['jetcredit.php'].decode('utf-8')
    readme = contents['readme.txt'].decode('utf-8')
    version = one(r'^\s*\*\s*Version:\s*(\S+)\s*$', header, 'plugin Version')
    constant = one(r"^\s*define\(\s*'JET_VERSION'\s*,\s*'([^']+)'\s*\);", header, 'JET_VERSION')
    stable = one(r'^Stable tag:\s*(\S+)\s*$', readme, 'Stable tag')
    require(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?', version), 'Invalid release version')
    require(version == constant == stable, 'Inconsistent release versions')
    require(re.search(r'^## \[' + re.escape(version) + r'\] - \d{4}-\d{2}-\d{2}\s*$',
                      contents['CHANGELOG.md'].decode('utf-8'), re.MULTILINE), 'Missing version changelog')

    license_id = one(r'^\s*\*\s*License:\s*(\S+)\s*$', header, 'plugin License')
    require(license_id == one(r'^License:\s*(\S+)\s*$', readme, 'readme License'), 'Conflicting license declarations')
    require(license_id in ('GPL-2.0-or-later', 'GPL-3.0-or-later'), 'Unrecognized license declaration')
    license_version = '2' if license_id == 'GPL-2.0-or-later' else '3'
    require(re.search(r'Version ' + license_version + r',', contents['LICENSE'].decode('utf-8')[:1024]),
            'LICENSE text disagrees with metadata')
    uri = 'https://www.gnu.org/licenses/gpl-' + license_version + '.0.html'
    require(one(r'^\s*\*\s*License URI:\s*(\S+)\s*$', header, 'plugin License URI') == uri
            and one(r'^License URI:\s*(\S+)\s*$', readme, 'readme License URI') == uri,
            'License URI disagrees with metadata')
    if 'composer.json' in tree:
        import json
        require(json.loads(blob('composer.json'))['license'] == license_id, 'Composer license disagrees with release')

    for name, data in contents.items():
        target = stage / 'jetcredit' / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        suffix = target.suffix
        if suffix in ('.php', '.js'):
            if suffix == '.php':
                require(data.startswith(b'<?php'), 'Invalid PHP opening: ' + name)
            command = [php, '-l', str(target)] if suffix == '.php' else [node, '--check', str(target)]
            result = subprocess.run(command, capture_output=True, text=True)
            require(result.returncode == 0, 'Syntax check failed: ' + name + '\n' + result.stdout + result.stderr)
        elif suffix == '.png':
            require(len(data) > 20 and data.startswith(b'\x89PNG\r\n\x1a\n'), 'Invalid PNG: ' + name)
        elif suffix == '.ttf':
            require(len(data) > 12 and data[:4] in (b'\0\1\0\0', b'OTTO', b'true'), 'Invalid font: ' + name)

    epoch = max(315532800, min(4354819198, int(git('show', '-s', '--format=%ct', commit))))
    timestamp = datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc).timetuple()[:6]
    filename = 'CC_Woocommerce_JET_v.' + version + '.zip'
    archive = stage / filename
    expected = ['jetcredit/' + name for name in sorted(manifest)]
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        for name in sorted(manifest):
            info = zipfile.ZipInfo('jetcredit/' + name, date_time=timestamp)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            bundle.writestr(info, contents[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    subprocess.run(['unzip', '-t', str(archive)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    with zipfile.ZipFile(archive) as bundle:
        require(bundle.namelist() == expected, 'Unexpected ZIP entries or root structure')
        require(bundle.testzip() is None, 'Corrupted ZIP content')
        for name in manifest:
            require(bundle.read('jetcredit/' + name) == contents[name], 'ZIP differs from source snapshot: ' + name)

    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    checksum = digest + '  ' + filename + '\n'
    output = Path(destination).expanduser().resolve()
    output.mkdir(parents=True, exist_ok=True)
    final = output / filename
    final_sum = output / (filename + '.sha256')
    if final.exists() or final_sum.exists() or final.is_symlink() or final_sum.is_symlink():
        require(not final.is_symlink() and not final_sum.is_symlink() and final.is_file() and final_sum.is_file(),
                'Incomplete or unsafe existing release output')
        require(final.read_bytes() == archive.read_bytes() and final_sum.read_text() == checksum,
                'Existing release output differs; use another output directory')
    else:
        created = []
        try:
            for path, data in ((final, archive.read_bytes()), (final_sum, checksum.encode('ascii'))):
                with path.open('xb') as handle:
                    created.append(path)
                    handle.write(data)
        except BaseException:
            for path in created:
                path.unlink()
            raise
    subprocess.run(['sha256sum', '--check', final_sum.name], cwd=output, check=True, stdout=subprocess.PIPE)
    print('Source commit: ' + commit)
    print('Version: ' + version)
    print('Files: ' + str(len(manifest)))
    print('ZIP: ' + str(final))
    print('Bytes: ' + str(final.stat().st_size))
    print('SHA-256: ' + digest)
except (ValueError, OSError, KeyError, subprocess.CalledProcessError, UnicodeError, zipfile.BadZipFile) as error:
    print('Release build failed: ' + str(error), file=sys.stderr)
    sys.exit(1)
PY
