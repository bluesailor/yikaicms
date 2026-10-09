#!/usr/bin/env python3
"""
生成随包内置字体的 unicode-range 分片（仅开发机使用，tools/ 不进安装包）。

用法：
  pip install fonttools brotli
  python tools/fonts/build-bundled-fonts.py <InterVariable.woff2> <上游版本号，如 4.1>

输出到 assets/fonts/inter/：每个分片一个 woff2 + manifest.json（上游版本、每片字节数与 SHA256）。
分片范围与 includes/font_presets.php 的 bundledFonts() 必须一致——单测会逐条比对 manifest。
保留 wght 与 opsz 两个轴：标题大字号靠 opsz 自动收紧字距（替代 Inter Tight）。
"""
import hashlib
import json
import os
import sys

from fontTools import subset
from fontTools.ttLib import TTFont

# Google Fonts 惯用划分；cyrillic 与 cyrillic-ext 合并为一片
RANGES = {
    'latin': 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,'
             'U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
    'latin-ext': 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,'
                 'U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
    'cyrillic': 'U+0301,U+0400-052F,U+1C80-1C8A,U+20B4,U+2116,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F',
    'greek': 'U+0370-0377,U+037A-037F,U+0384-038A,U+038C,U+038E-03A1,U+03A3-03FF',
    'vietnamese': 'U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,'
                  'U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB',
}


def parse_range(spec):
    out = set()
    for part in spec.split(','):
        part = part.strip()[2:]
        lo, _, hi = part.partition('-')
        out.update(range(int(lo, 16), int(hi or lo, 16) + 1))
    return out


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    src, upstream = sys.argv[1], sys.argv[2]
    root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    out_dir = os.path.join(root, 'assets', 'fonts', 'inter')
    os.makedirs(out_dir, exist_ok=True)

    with open(src, 'rb') as fh:
        src_sha = hashlib.sha256(fh.read()).hexdigest()

    manifest = {'family': 'Inter', 'upstream': 'rsms/inter v' + upstream, 'source_sha256': src_sha, 'files': {}}
    for key, spec in RANGES.items():
        font = TTFont(src)
        opts = subset.Options()
        opts.flavor = 'woff2'
        opts.layout_features = ['*']      # 保留 tnum/case/cv* 等排版特性
        opts.name_IDs = ['*']             # 保留版权与许可名表
        opts.notdef_outline = True
        sub = subset.Subsetter(opts)
        sub.populate(unicodes=parse_range(spec))
        sub.subset(font)
        name = 'inter-' + key + '.woff2'
        path = os.path.join(out_dir, name)
        font.flavor = 'woff2'
        font.save(path)
        with open(path, 'rb') as fh:
            data = fh.read()
        manifest['files'][key] = {
            'file': name,
            'range': spec,
            'bytes': len(data),
            'sha256': hashlib.sha256(data).hexdigest(),
        }
        print(f'{name}: {len(data)} bytes')

    with open(os.path.join(out_dir, 'manifest.json'), 'w', encoding='utf-8', newline='\n') as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=2)
        fh.write('\n')


if __name__ == '__main__':
    main()
