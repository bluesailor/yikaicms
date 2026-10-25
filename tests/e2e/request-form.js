// 把请求体读成 URLSearchParams（get / has 用法不变）。urlencoded 与 multipart 都认：
// 2.1.3 起画布预览改用 FormData 发送（urlencoded 会把 JSON 百分号编码成 3–4 倍大），
// 测试里再用 new URLSearchParams(request.postData()) 就认不出预览请求了。
function requestForm(request) {
  const raw = request.postDataBuffer();
  if (!raw || raw.length === 0) return new URLSearchParams('');
  const type = String((request.headers() || {})['content-type'] || '');
  const match = type.match(/multipart\/form-data;\s*boundary=(?:"([^"]+)"|([^;]+))/i);
  if (!match) return new URLSearchParams(raw.toString('utf8'));
  const boundary = '--' + (match[1] || match[2]).trim();
  const params = new URLSearchParams();
  for (const part of raw.toString('utf8').split(boundary)) {
    const split = part.indexOf('\r\n\r\n');
    if (split < 0) continue;
    const head = part.slice(0, split);
    const name = head.match(/name="([^"]*)"/i);
    if (!name || /filename="/i.test(head)) continue;
    params.append(name[1], part.slice(split + 4).replace(/\r\n$/, ''));
  }
  return params;
}

module.exports = { requestForm };
