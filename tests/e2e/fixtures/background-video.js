const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');

// Real, self-generated video: no network, codec package, or playback mocks.
async function createBackgroundVideo(page, root) {
  if (!path.basename(root).startsWith('yikai-e2e-')
      || !fs.existsSync(path.join(root, 'storage/.smoke-state-backup/manifest.json'))) {
    throw new Error('Disposable site required for video fixture');
  }
  const bytes = await page.evaluate(async () => {
    const canvas = document.createElement('canvas');
    canvas.width = 320;
    canvas.height = 180;
    const context = canvas.getContext('2d');
    const stream = canvas.captureStream(20);
    const recorder = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp8' });
    const chunks = [];
    let timer;
    try {
      const recording = new Promise((resolve, reject) => {
        recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
        recorder.onerror = event => reject(new Error(event.error?.message || 'Recording failed'));
        recorder.onstop = () => resolve(new Blob(chunks, { type: 'video/webm' }));
      });
      recorder.start();
      let frame = 0;
      timer = setInterval(() => {
        context.fillStyle = `hsl(${frame * 9}, 70%, 40%)`;
        context.fillRect(0, 0, 320, 180);
        context.fillStyle = '#ffffff';
        context.fillRect(frame * 8, 50, 40, 80);
        if (++frame === 40) {
          clearInterval(timer);
          recorder.stop();
        }
      }, 50);
      return Array.from(new Uint8Array(await (await recording).arrayBuffer()));
    } finally {
      clearInterval(timer);
      if (recorder.state !== 'inactive') recorder.stop();
      stream.getTracks().forEach(track => track.stop());
    }
  });
  const url = `/uploads/e6-background-${randomUUID()}.webm`;
  const file = path.join(root, url.slice(1));
  fs.writeFileSync(file, Buffer.from(bytes), { flag: 'wx' });
  return { url, file, bytes: bytes.length };
}

module.exports = { createBackgroundVideo };
