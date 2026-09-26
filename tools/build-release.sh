#!/usr/bin/env bash
#
# Build the distributable plugin archive.
#
#   tools/build-release.sh [version] [output-dir]
#
# Examples:
#   tools/build-release.sh                 # version from kanban.xml
#   tools/build-release.sh 1.2.0 dist
#
# The archive is produced with `git archive`, so the `export-ignore` rules in
# .gitattributes decide what ships: tests/, tools/, docker/, docker-compose files,
# .env.example, phpunit.xml.dist, test-plugin.ps1 and prompt.md are never
# included. That is what keeps the CLI-only seeders and the local docker
# credentials out of the published package.
#
# Output: <output-dir>/glpi-plugin-kanban-<version>.tar.bz2
#         (uncompressed tar with a `kanban/` prefix is kept alongside it)
#
# The tree must be committed first: the archive is built from a git revision, not
# from the working copy.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

version="${1:-}"
outdir="${2:-dist}"

if [ -z "$version" ]; then
    version="$(sed -n 's|.*<num>\(.*\)</num>.*|\1|p' kanban.xml | head -n 1)"
fi

if [ -z "$version" ]; then
    echo "erro: nao foi possivel determinar a versao em kanban.xml" >&2
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "erro: a arvore de trabalho tem alteracoes nao commitadas; faca commit antes de publicar." >&2
    exit 1
fi

# Refuse to build a version that is not the tip of the default branch: the tag
# must point at the reviewed code.
head_ref="$(git rev-parse HEAD)"
tag_ref="refs/tags/$version"
if git rev-parse --verify --quiet "$tag_ref" >/dev/null; then
    tag_ref="$(git rev-list -n 1 "$tag_ref")"
fi
if [ "$tag_ref" != "$head_ref" ]; then
    echo "erro: a versao $version ($tag_ref) nao aponta para o HEAD ($head_ref)." >&2
    exit 1
fi

mkdir -p "$outdir"
name="glpi-plugin-kanban-$version"
tarball="$outdir/$name.tar"
archive="$outdir/$name.tar.bz2"

# `export-ignore` is honoured here, which is the whole point of this script.
git archive --format=tar --prefix=kanban/ -o "$tarball" "$version"

if command -v bzip2 >/dev/null 2>&1; then
    bzip2 -9 -c "$tarball" > "$archive"
elif command -v python3 >/dev/null 2>&1; then
    python3 -c 'import sys,bz2; open(sys.argv[2],"wb").write(bz2.compress(open(sys.argv[1],"rb").read(),9))' "$tarball" "$archive"
elif command -v python >/dev/null 2>&1; then
    python -c 'import sys,bz2; open(sys.argv[2],"wb").write(bz2.compress(open(sys.argv[1],"rb").read(),9))' "$tarball" "$archive"
else
    echo "erro: nenhum compressor bzip2 disponivel (instale bzip2 ou python)." >&2
    exit 1
fi

# Validate the package: a single `kanban/` directory (what the GLPI catalog
# expects) and no development file.
listing="$(tar -tf "$archive")"

if ! printf '%s\n' "$listing" | sed -n '1p' | grep -q '^kanban/$'; then
    echo "erro: o prefixo kanban/ nao esta no arquivo." >&2
    exit 1
fi

if printf '%s\n' "$listing" \
    | grep -Eq '^(tests/|tools/|docker/|docker-compose|create_test_tickets\.php|\.env\.example|phpunit\.xml\.dist|test-plugin\.ps1|prompt\.md)'; then
    echo "erro: arquivos de desenvolvimento no pacote de release." >&2
    exit 1
fi

echo "OK: $archive"
