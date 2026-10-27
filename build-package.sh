#!/bin/bash
#
# 配布用 zip を作成する。
#
# 重要: ステージング後に必ずパーミッションを正規化する。
# 作業環境のファイルが 600 のまま zip に記録されると、展開してサーバーへ
# アップロードした利用者側で PHP がファイルを読めず、
# 「Failed opening required .../vendor/autoload.php」で停止する。
#
# 使い方: ./build-package.sh
#
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(grep -o "public \$version = '[^']*'" extension/plugins/Passkey/ServiceProvider.php | head -1 | sed "s/.*'\\(.*\\)'/\\1/")
if [ -z "$VERSION" ]; then
  echo "バージョンを取得できませんでした" >&2
  exit 1
fi
NAME="Passkey-${VERSION}"
echo "バージョン: ${VERSION}"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "${STAGE}/${NAME}"
cp README.md "${STAGE}/${NAME}/"
cp -R extension "${STAGE}/${NAME}/"

# 同梱ライブラリのテスト用フィクスチャは実行時に未参照（約 1.7MB）
rm -rf "${STAGE}/${NAME}/extension/plugins/Passkey/vendor/lbuchs/webauthn/_test"

# OS が生成するゴミファイル
find "${STAGE}" -name ".DS_Store" -delete
find "${STAGE}" -name "Thumbs.db" -delete
find "${STAGE}" -name "*.log" -delete

# パーミッションの正規化（これを省くと利用者側で読み込めなくなる）
find "${STAGE}" -type f -exec chmod 644 {} \;
find "${STAGE}" -type d -exec chmod 755 {} \;

rm -f Passkey-*.zip
( cd "${STAGE}" && zip -r -q -X "${OLDPWD}/${NAME}.zip" "${NAME}" )

# 検証: 644 以外のファイルが混ざっていないか
BAD=$(unzip -Z "${NAME}.zip" | awk '/^-/ {print substr($1,1,10)}' | grep -cv '^-rw-r--r--$' || true)
if [ "${BAD}" -ne 0 ]; then
  echo "NG: 権限が 644 でないファイルが ${BAD} 件あります" >&2
  exit 1
fi

echo "OK: ${NAME}.zip を作成しました（全ファイル 644）"
ls -lh "${NAME}.zip"
