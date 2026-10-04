/* 自定义 CSS 的即时检查：与 BloxCustomCode::checkCss（服务端，保存时的最终把关）同一套规则，
 * 让作者在输入时就看到原因，而不是保存失败才知道。返回错误码，空串表示通过。 */
(function (root) {
    'use strict';

    var FORBIDDEN = ['@import', '@charset', '@namespace', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', 'src('];

    function check(value, max) {
        if (value === undefined || value === null) return '';
        if (typeof value !== 'string') return 'invalid';
        var css = value.replace(/\r\n/g, '\n').trim();
        if (css === '') return '';
        if (Array.from(css).length > (max || 10000)) return 'too_long';
        if (css.indexOf('<') !== -1 || /[\x00-\x08\x0B\x0E-\x1F\x7F]/.test(css)) return 'markup';
        // 转义解码后再查：\75 rl( → url(，\2f\2f → //
        var decoded = css.replace(/\\([0-9a-fA-F]{1,6})\s?/g, function (_, hex) {
            var code = parseInt(hex, 16);
            return code > 0 && code < 0x110000 ? String.fromCodePoint(code) : '';
        }).replace(/\\([\s\S])/g, '$1');
        var plain = decoded.replace(/\/\*[\s\S]*?\*\//g, '');
        if (plain.indexOf('/*') !== -1) return 'comment';
        var compact = plain.replace(/\s+/g, '').toLowerCase();
        for (var i = 0; i < FORBIDDEN.length; i++) {
            if (compact.indexOf(FORBIDDEN[i]) !== -1) return 'forbidden';
        }
        if (compact.indexOf('//') !== -1) return 'external';
        var depth = 0;
        var withoutStrings = plain.replace(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, '""');
        for (var j = 0; j < withoutStrings.length; j++) {
            if (withoutStrings[j] === '{') depth++;
            else if (withoutStrings[j] === '}') {
                depth--;
                if (depth < 0) return 'braces';
            }
        }
        return depth === 0 ? '' : 'braces';
    }

    // 与 BloxCustomCode::classList 同一规则：合法类名、去重、最多 20 个，yk- 前缀留给系统
    function classList(value) {
        var clean = [];
        String(value || '').trim().split(/\s+/).forEach(function (token) {
            if (!token || clean.length >= 20 || clean.indexOf(token) !== -1) return;
            if (!/^[A-Za-z_!-][A-Za-z0-9_:.\/\[\]!-]{0,63}$/.test(token) || token.toLowerCase().indexOf('yk-') === 0) return;
            clean.push(token);
        });
        return clean.join(' ');
    }

    var api = { check: check, classList: classList };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxCustomCode = api;
})(typeof window !== 'undefined' ? window : null);
