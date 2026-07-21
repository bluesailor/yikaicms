#!/bin/bash
# ============================================================
# 生成 includes/i18n/s2t_maps.php —— 简体→繁体(台湾用词) 转换映射
#
# 数据源：OpenCC 词典（Apache-2.0）
#   STCharacters / STPhrases  → 简→繁（p1）
#   TWPhrases / TWVariants / TWVariantsPhrases → 繁→台湾用词（p2）
# 运行期由 includes/i18n/S2T.php 做两趟 strtr。
#
# 依赖：curl + python3（联网拉取 OpenCC 原始词典）
# 用法：bash tools/build-s2t-map.sh
# ============================================================
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT_DIR/includes/i18n/s2t_maps.php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

BASE="https://raw.githubusercontent.com/BYVoid/OpenCC/master/data/dictionary"
echo "下载 OpenCC 词典…"
for f in STCharacters STPhrases TWPhrases TWVariants TWVariantsPhrases; do
    curl -fsS -o "$TMP/$f.txt" "$BASE/$f.txt"
done

echo "编译映射…"
python3 - "$TMP" "$OUT" <<'PY'
import sys, os
tmp, out = sys.argv[1], sys.argv[2]

def load(name):
    d = {}
    for line in open(os.path.join(tmp, name), encoding='utf-8'):
        line = line.rstrip('\n')
        if not line or line.startswith('#'):
            continue
        p = line.split('\t')
        if len(p) < 2:
            continue
        k = p[0].strip()
        v = p[1].split(' ')[0].strip()   # 一对多取首选值（OpenCC 默认）
        if k and v and k != v:
            d[k] = v
    return d

stc  = load('STCharacters.txt')
stp  = load('STPhrases.txt')
twp  = load('TWPhrases.txt')
twv  = load('TWVariants.txt')
twvp = load('TWVariantsPhrases.txt')

# 歧义简体字（一简对多繁）——只有含歧义字的 STPhrases 才有消歧价值，其余与单字表冗余
ambiguous = set()
for line in open(os.path.join(tmp, 'STCharacters.txt'), encoding='utf-8'):
    if line.startswith('#') or '\t' not in line:
        continue
    k, v = line.rstrip('\n').split('\t', 1)
    if len(v.split(' ')) > 1:
        ambiguous.add(k.strip())
stp = {k: v for k, v in stp.items() if any(c in ambiguous for c in k)}

p1 = {}; p1.update(stc); p1.update(stp)          # 简→繁（词组 strtr 自动最长匹配优先）
p2 = {}; p2.update(twv); p2.update(twvp); p2.update(twp)   # 繁→台湾用词

def emit(m):
    parts = []
    for k, v in m.items():
        k = k.replace('\\', '\\\\').replace("'", "\\'")
        v = v.replace('\\', '\\\\').replace("'", "\\'")
        parts.append(f"'{k}'=>'{v}'")
    return '[' + ','.join(parts) + ']'

with open(out, 'w', encoding='utf-8') as f:
    f.write("<?php\n")
    f.write("/**\n * 简→繁(台湾)转换映射 —— 由 OpenCC 词典编译，勿手改。\n")
    f.write(" * 重新生成：bash tools/build-s2t-map.sh\n")
    f.write(" * p1: 简→繁（STCharacters + 歧义 STPhrases）  p2: 繁→台湾用词（TWPhrases/TWVariants*）\n */\n")
    f.write("return ['p1'=>" + emit(p1) + ",'p2'=>" + emit(p2) + "];\n")

print(f"  p1(简→繁)={len(p1)}  p2(繁→台)={len(p2)}  文件={os.path.getsize(out)} bytes")
PY
echo "已写入 $OUT"
