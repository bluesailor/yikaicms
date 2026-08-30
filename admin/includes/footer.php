            </main>

            <!-- 底部 -->
            <footer class="p-6 text-center text-gray-500 text-sm">
                <?php $adminCopyright = config('admin_copyright', ''); ?>
                <?php if ($adminCopyright): ?>
                    <?php echo e($adminCopyright); ?>
                <?php else: ?>
                    &copy; <?php echo date('Y'); ?> <?php echo e(adminBrandName()); ?>
                <?php endif; ?>
                <?php // ★ 许可声明：本调用受《YikaiCMS 软件许可协议》第二条保护，免费使用时
                      //   禁止移除（删除违反许可协议，且删除核心函数会导致后台运行错误）。
                      //   需要白标请购买商业授权——授权后标识自动隐藏，无需改代码。 ?>
                <?php echo adminPoweredBy(); ?>
            </footer>
        </div>
    </div>

    <!-- 通用CSS -->
    <style>button, a[href], [onclick] { cursor: pointer; }</style>

    <!-- 通用脚本 -->
    <!-- 外部 swiper / tinymce 包大，移到下面；先把全局 helper（showMessage/safeJson/CSRF）
         注入到 window，避免用户在 tinymce 还没下载完就点按钮触发 "showMessage is not defined". -->
    <script>
    // CSRF Token 自动注入
    (function() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) return;

        // 拦截 fetch，自动为 POST 请求附加 CSRF token
        const _fetch = window.fetch;
        window.fetch = function(url, options = {}) {
            if (options.method && options.method.toUpperCase() === 'POST' && options.body) {
                if (options.body instanceof FormData) {
                    if (!options.body.has('_token')) {
                        options.body.append('_token', csrfToken);
                    }
                } else if (options.body instanceof URLSearchParams) {
                    if (!options.body.has('_token')) {
                        options.body.append('_token', csrfToken);
                    }
                }
            }
            return _fetch.call(this, url, options);
        };

        // 拦截 XMLHttpRequest，自动为 POST 的 FormData 附加 CSRF token
        // （带进度条的上传用 XHR 而非 fetch，否则会被 verifyCsrf 拦成「非法请求」）
        const _open = XMLHttpRequest.prototype.open;
        const _send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function(method, ...rest) {
            this._csrfMethod = (method || '').toUpperCase();
            return _open.call(this, method, ...rest);
        };
        XMLHttpRequest.prototype.send = function(body) {
            if (this._csrfMethod === 'POST' && body instanceof FormData && !body.has('_token')) {
                body.append('_token', csrfToken);
            }
            return _send.call(this, body);
        };
    })();

    // 密码显示/隐藏切换
    function togglePassword(el) {
        var wrap = el.closest('.pwd-toggle');
        var input = wrap.querySelector('input');
        var isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        wrap.querySelector('.eye-open').classList.toggle('hidden', !isHidden);
        wrap.querySelector('.eye-closed').classList.toggle('hidden', isHidden);
    }

    // 安全解析 JSON 响应
    async function safeJson(response) {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse failed:', text);
            return { code: -1, msg: <?php echo json_encode(__('admin_server_error'), JSON_UNESCAPED_UNICODE); ?> };
        }
    }

    // 通用 AJAX 函数
    async function fetchApi(url, data = {}) {
        const formData = new FormData();
        for (const key in data) {
            formData.append(key, data[key]);
        }

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        return safeJson(response);
    }

    // 通用上传函数
    async function safeUpload(file, type) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type || 'images');
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        return safeJson(response);
    }

    /**
     * 后台统一保存 helper：消除每个页面的 fetch/safeJson/showMessage/try-catch 样板。
     *
     *   adminSave(input, opts)
     *     input: HTMLFormElement | FormData | object（普通对象会被序列化成 FormData）
     *     opts: {
     *       url:        请求地址（默认 ''，即当前页 location）
     *       successMsg: 成功 toast 文案（默认读 admin_saved；传 false 则不弹）
     *       errorMsg:   网络异常 toast 文案（默认 "请求失败"）
     *       onSuccess:  function(data) 服务端 code===0 时调用
     *       onError:    function(data|err) 失败时调用（覆盖默认 toast）
     *       reload:     boolean | number  true=保存后立即 reload；数值=延时(ms) 后 reload
     *       button:     可选 <button> 元素；保存期间会被 disabled 防重复点击
     *     }
     *   返回：Promise<{code, msg, data}>
     *
     * CSRF token 由本文件上面的 window.fetch 拦截器自动注入，无需调用方关心。
     */
    async function adminSave(input, opts) {
        opts = opts || {};
        const url = opts.url || '';
        let body;
        if (input instanceof HTMLFormElement) {
            body = new FormData(input);
        } else if (input instanceof FormData) {
            body = input;
        } else if (input && typeof input === 'object') {
            body = new FormData();
            for (const k in input) {
                if (Object.prototype.hasOwnProperty.call(input, k)) body.append(k, input[k]);
            }
        } else {
            body = new FormData();
        }

        const btn = opts.button;
        let oldText = null;
        if (btn && !btn.disabled) {
            btn.disabled = true;
            oldText = btn.textContent;
            btn.dataset._adminSaveLock = '1';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await safeJson(response);
            if (data && data.code === 0) {
                if (opts.successMsg !== false) {
                    showMessage(opts.successMsg || (window._ADMIN_SAVED_MSG || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>));
                }
                if (typeof opts.onSuccess === 'function') opts.onSuccess(data);
                if (opts.reload) {
                    const delay = typeof opts.reload === 'number' ? opts.reload : 600;
                    setTimeout(() => location.reload(), delay);
                }
            } else {
                if (typeof opts.onError === 'function') {
                    opts.onError(data);
                } else {
                    showMessage((data && data.msg) || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
                }
            }
            return data;
        } catch (err) {
            console.error('adminSave error:', err);
            if (typeof opts.onError === 'function') {
                opts.onError(err);
            } else {
                showMessage((opts.errorMsg || <?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>) + (err && err.message ? ': ' + err.message : ''), 'error');
            }
            return { code: -1, msg: err && err.message };
        } finally {
            if (btn && btn.dataset._adminSaveLock === '1') {
                btn.disabled = false;
                if (oldText !== null) btn.textContent = oldText;
                delete btn.dataset._adminSaveLock;
            }
        }
    }

    /**
     * 后台状态切换 helper：toggle 行状态（show/hide、enable/disable 等）的样板代码。
     *
     *   adminToggle(action, id, btn, opts)
     *     action: POST 的 action 字段值（例如 "toggle_status"）
     *     id:     行 id
     *     btn:    被点击的 <button>，用于更新 className/text
     *     opts: {
     *       url:    请求地址（默认 ''）
     *       extra:  附加 POST 字段对象
     *       onOk:   function(data, btn) 服务端 code===0 时调用，自定义按钮样式更新
     *     }
     */
    async function adminToggle(action, id, btn, opts) {
        opts = opts || {};
        const fd = new FormData();
        fd.append('action', action);
        fd.append('id', String(id));
        if (opts.extra) {
            for (const k in opts.extra) fd.append(k, opts.extra[k]);
        }
        return adminSave(fd, {
            url: opts.url,
            successMsg: opts.successMsg,
            button: btn,
            onSuccess: (data) => { if (typeof opts.onOk === 'function') opts.onOk(data, btn); },
        });
    }

    // 暴露到 window，供 inline onclick 调用
    window.adminSave   = adminSave;
    window.adminToggle = adminToggle;

    // 提示消息
    function showMessage(message, type = 'success') {
        const div = document.createElement('div');
        div.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white ${
            type === 'success' ? 'bg-green-500' : 'bg-red-500'
        }`;
        div.textContent = message;
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 3000);
    }

    // 确认删除
    function confirmDelete(message = <?php echo json_encode(__('admin_confirm_delete'), JSON_UNESCAPED_UNICODE); ?>) {
        return confirm(message);
    }

    /**
     * 初始化 TinyMCE 编辑器
     * @param {string} selector - textarea 选择器
     * @param {object} options - 配置项 { height, placeholder, uploadUrl }
     */
    function initTinyEditor(selector, options = {}) {
        var lang = document.documentElement.lang || 'zh-CN';
        var tinymceLang = lang === 'ja' ? 'ja' : 'zh_CN';

        tinymce.init({
            selector: selector,
            language: tinymceLang,
            height: options.height || 500,
            menubar: 'file edit view insert format tools table',
            plugins: 'autolink lists link image charmap preview anchor searchreplace visualblocks code codesample fullscreen insertdatetime media table help wordcount',
            toolbar: 'undo redo | styles fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media codesample | table | removeformat code fullscreen',
            font_size_formats: '12px 14px 16px 18px 20px 24px 28px 32px 36px 48px',
            images_upload_handler: function(blobInfo) {
                return new Promise(function(resolve, reject) {
                    var fd = new FormData();
                    fd.append('file', blobInfo.blob(), blobInfo.filename());
                    fd.append('type', 'images');
                    fetch(options.uploadUrl || '/admin/upload.php', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.code === 0) resolve(d.data.url);
                            else reject(d.msg || 'Upload failed');
                        })
                        .catch(function() { reject('Upload failed'); });
                });
            },
            // 图片对话框的"来源"旁提供浏览按钮 → 直接从媒体库选图（复用全局 openMediaPicker）
            file_picker_types: 'image',
            file_picker_callback: function(callback, value, meta) {
                if (meta.filetype === 'image' && typeof openMediaPicker === 'function') {
                    openMediaPicker(function(url) {
                        callback(url, { alt: '' });
                    }, { type: 'image' });
                }
            },
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Kaku Gothic ProN", "PingFang SC", "Microsoft YaHei", sans-serif; font-size: 16px; line-height: 1.8; } img { max-width: 100%; height: auto; }',
            branding: false, promotion: false, convert_urls: false,
            setup: function(editor) {
                editor.on('change', function() {
                    tinymce.triggerSave();
                });
            }
        });
    }

    /**
     * 兼容旧代码：initWangEditor 映射到 initTinyEditor
     * 返回一个 wangEditor 兼容门面，调用方仍可用 editor.getHtml() / setHtml()
     * （TinyMCE 异步初始化，门面在调用时再向 tinymce 取实例，故 init 顺序无关）。
     */
    function initWangEditor(toolbarSelector, editorSelector, options = {}) {
        // 将 wangEditor 容器转换为 textarea
        var container = document.querySelector(editorSelector);
        var textarea = null;
        if (container && container.tagName !== 'TEXTAREA') {
            textarea = document.createElement('textarea');
            textarea.name = container.getAttribute('data-name') || 'content';
            textarea.className = 'tinymce-auto';
            // 赋予唯一 id，门面据此精确取回对应的 TinyMCE 实例（页面可能有多个编辑器）
            textarea.id = (container.id || 'wecompat_' + Math.random().toString(36).slice(2)) + '_ta';
            textarea.innerHTML = options.html || container.innerHTML;
            container.parentNode.replaceChild(textarea, container);

            var toolbar = document.querySelector(toolbarSelector);
            if (toolbar) toolbar.remove();

            initTinyEditor('#' + textarea.id, options);
        } else if (container) {
            textarea = container;
        }

        function _ed() {
            if (!window.tinymce) return null;
            return (textarea && textarea.id ? tinymce.get(textarea.id) : null) || tinymce.activeEditor;
        }

        // wangEditor 兼容门面
        return {
            getHtml: function() {
                var ed = _ed();
                return ed ? ed.getContent() : (textarea ? textarea.value : '');
            },
            setHtml: function(html) {
                var ed = _ed();
                if (ed) ed.setContent(html || '');
                else if (textarea) textarea.value = html || '';
            },
            getText: function() {
                var ed = _ed();
                return ed ? ed.getContent({ format: 'text' }) : '';
            }
        };
    }
    </script>

    <!-- 体积大的外部库放在 helper 后，让 showMessage 等先就绪可被任何按钮调用 -->
    <script src="/assets/swiper/swiper-bundle.min.js"></script>
    <script src="/assets/tinymce/tinymce.min.js"></script>
    <script src="/assets/js/official-media-client.js?v=<?php echo (int) filemtime(ROOT_PATH . '/assets/js/official-media-client.js'); ?>"></script>

    <!-- 媒体库选择弹窗 -->
    <div id="mediaPickerModal" class="fixed inset-0 hidden" style="z-index:9999">
        <div class="absolute inset-0 bg-black/50" onclick="_mpClose()"></div>
        <div class="relative mx-auto my-6 bg-white rounded-lg shadow-xl w-full max-w-5xl flex flex-col" style="max-height:calc(100vh - 3rem)">
            <div class="px-6 py-4 border-b flex justify-between items-center flex-shrink-0">
                <h3 class="font-bold text-gray-800"><?php echo e(__('mp_pick_title')); ?></h3>
                <button onclick="_mpClose()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div id="mpSourceTabs" class="px-6 pt-3 flex items-center gap-1 flex-shrink-0" role="tablist" aria-label="<?php echo e(__('official_media_source_label')); ?>">
                <button type="button" id="mpSourceLocal" onclick="_mpSetSource('local')" role="tab"
                        class="px-3 py-1.5 rounded text-sm font-medium transition">
                    <i class="ti ti-photo mr-1"></i><?php echo e(__('official_media_source_local')); ?>
                </button>
                <button type="button" id="mpSourceOfficial" onclick="_mpSetSource('official')" role="tab"
                        class="px-3 py-1.5 rounded text-sm font-medium transition">
                    <i class="ti ti-cloud-download mr-1"></i><?php echo e(__('official_media_source_official')); ?>
                </button>
            </div>
            <div class="px-6 py-3 border-b flex flex-wrap gap-3 items-center flex-shrink-0">
                <input type="text" id="mpKeyword" class="border rounded px-3 py-1.5 text-sm w-48" placeholder="<?php echo e(__('mp_search_ph')); ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();_mpLoad(1)}">
                <button onclick="_mpLoad(1)" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm"><?php echo e(__('admin_search')); ?></button>
                <div class="flex-1"></div>
                <button id="mpUploadBtn" onclick="document.getElementById('mpFileInput').click()" class="bg-primary hover:bg-secondary text-white px-3 py-1.5 rounded text-sm inline-flex items-center gap-1">
                    <i class="ti ti-upload text-base"></i>
                    <?php echo e(__('mp_upload_new')); ?>
                </button>
                <input type="file" id="mpFileInput" class="hidden" accept="image/*" onchange="_mpUpload(this)">
            </div>
            <div class="flex-1 overflow-y-auto p-6" id="mpContent">
                <div class="text-center text-gray-400 py-12"><?php echo e(__('admin_loading')); ?></div>
            </div>
            <div class="px-6 py-3 border-t flex items-center justify-between flex-shrink-0">
                <div id="mpPager" class="flex items-center gap-2 text-sm text-gray-500"></div>
                <div class="flex gap-2">
                    <button onclick="_mpClose()" class="px-4 py-2 border rounded hover:bg-gray-100 text-sm"><?php echo e(__('cancel')); ?></button>
                    <button onclick="_mpConfirm()" id="mpConfirmBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm disabled:opacity-50" disabled><?php echo e(__('mp_confirm_pick')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ===== 媒体库选择器 =====
    (function() {
        var _mpCallback = null;
        var _mpSelected = null;
        var _mpType = 'image';
        var _mpPage = 1;
        var _mpSource = 'local';
        var _mpUsage = '';
        var _mpImporting = '';
        var _mpEntitlement = { canImport: false, reason: '' };
        var _mpOfficialText = <?php echo json_encode([
            'preview' => __('official_media_preview'),
            'import' => __('official_media_import_use'),
            'importing' => __('official_media_importing'),
            'upgrade' => __('official_media_upgrade_hint'),
            'empty' => __('official_media_empty'),
            'failed' => __('official_media_unavailable'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        window.openMediaPicker = function(callback, options) {
            options = options || {};
            _mpCallback = callback;
            _mpSelected = null;
            _mpType = options.type || 'image';
            _mpUsage = String(options.usage || '');
            _mpSource = 'local';
            _mpImporting = '';
            _mpEntitlement = { canImport: false, reason: '' };
            _mpPage = 1;
            document.getElementById('mpKeyword').value = '';
            document.getElementById('mpConfirmBtn').disabled = true;
            document.getElementById('mediaPickerModal').classList.remove('hidden');
            _mpSyncSourceUi();
            _mpLoad(1);
        };

        window._mpClose = function() {
            document.getElementById('mediaPickerModal').classList.add('hidden');
            _mpCallback = null;
            _mpSelected = null;
            _mpImporting = '';
        };

        window._mpSetSource = function(source) {
            if (source === 'official' && _mpType !== 'image') return;
            _mpSource = source === 'official' ? 'official' : 'local';
            _mpSelected = null;
            _mpImporting = '';
            document.getElementById('mpConfirmBtn').disabled = true;
            _mpSyncSourceUi();
            _mpLoad(1);
        };

        function _mpSyncSourceUi() {
            var local = document.getElementById('mpSourceLocal');
            var official = document.getElementById('mpSourceOfficial');
            var upload = document.getElementById('mpUploadBtn');
            var confirm = document.getElementById('mpConfirmBtn');
            var showOfficial = _mpType === 'image';
            document.getElementById('mpSourceTabs').classList.toggle('hidden', !showOfficial);
            official.classList.toggle('hidden', !showOfficial);
            local.className = 'px-3 py-1.5 rounded text-sm font-medium transition ' + (_mpSource === 'local' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:bg-gray-100');
            official.className = 'px-3 py-1.5 rounded text-sm font-medium transition ' + (_mpSource === 'official' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:bg-gray-100') + (showOfficial ? '' : ' hidden');
            local.setAttribute('aria-selected', _mpSource === 'local' ? 'true' : 'false');
            official.setAttribute('aria-selected', _mpSource === 'official' ? 'true' : 'false');
            upload.classList.toggle('hidden', _mpSource === 'official');
            confirm.classList.toggle('hidden', _mpSource === 'official');
        }

        window._mpLoad = async function(page) {
            if (_mpSource === 'official') {
                await _mpLoadOfficial(page);
                return;
            }
            _mpPage = page;
            var keyword = document.getElementById('mpKeyword').value.trim();
            var url = '/admin/media_api.php?action=list&type=' + encodeURIComponent(_mpType)
                    + '&page=' + page
                    + (keyword ? '&keyword=' + encodeURIComponent(keyword) : '');

            document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">' + <?php echo json_encode(__('admin_loading'), JSON_UNESCAPED_UNICODE); ?> + '</div>';

            try {
                var resp = await fetch(url);
                var data = await resp.json();
                if (data.code !== 0) { document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">' + <?php echo json_encode(__('admin_load_failed'), JSON_UNESCAPED_UNICODE); ?> + '</div>'; return; }

                var items = data.data.items;
                if (!items.length) {
                    document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">' + <?php echo json_encode(__('mp_empty'), JSON_UNESCAPED_UNICODE); ?> + '</div>';
                    _renderPager(data.data);
                    return;
                }

                var html = '<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">';
                for (var i = 0; i < items.length; i++) {
                    var it = items[i];
                    var isSel = (_mpSelected === it.url);
                    html += '<div class="mp-item relative border-2 rounded-lg overflow-hidden cursor-pointer transition'
                         + (isSel ? ' border-primary ring-2 ring-primary/30' : ' border-transparent hover:border-gray-300')
                         + '" data-url="' + _escAttr(it.url).replace(/'/g, '&#39;') + '" onclick="_mpSelect(this)">'
                         + '<div class="aspect-square bg-gray-100 flex items-center justify-center">';
                    if (it.type === 'image') {
                        html += '<img src="' + _escAttr(it.url) + '" class="w-full h-full object-cover" loading="lazy">';
                    } else {
                        html += '<div class="text-3xl text-gray-400">\uD83D\uDCC4</div>';
                    }
                    html += '</div>';
                    html += '<div class="p-1.5"><div class="text-xs text-gray-600 truncate">' + _escHtml(it.name) + '</div></div>';
                    // 选中遮罩 + 大勾
                    html += '<div class="mp-check absolute inset-0 bg-primary/20 flex items-center justify-center pointer-events-none' + (isSel ? '' : ' hidden') + '">'
                         + '<div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center shadow-lg">'
                         + '<i class="ti ti-check text-xl text-white"></i>'
                         + '</div></div>';
                    html += '</div>';
                }
                html += '</div>';

                document.getElementById('mpContent').innerHTML = html;
                _renderPager(data.data);
            } catch (e) {
                document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">' + <?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + '</div>';
            }
        };

        async function _mpLoadOfficial(page) {
            _mpPage = page;
            var keyword = document.getElementById('mpKeyword').value.trim();
            document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">' + <?php echo json_encode(__('admin_loading'), JSON_UNESCAPED_UNICODE); ?> + '</div>';

            try {
                var result = await window.OfficialMediaClient.list('/admin/media_api.php', page, keyword, { usage: _mpUsage });
                if (!result.ok) {
                    document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">' + _escHtml(result.message || _mpOfficialText.failed) + '</div>';
                    _renderPager({ page: 1, pages: 0, total: 0 });
                    return;
                }

                _mpEntitlement = result.entitlement;
                if (!result.items.length) {
                    document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">' + _escHtml(_mpOfficialText.empty) + '</div>';
                    _renderPager(result);
                    return;
                }

                var html = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';
                for (var i = 0; i < result.items.length; i++) {
                    var it = result.items[i] || {};
                    var preview = String(it.preview_url || '');
                    var previewLarge = String(it.preview_large_url || preview);
                    var assetId = String(it.id || '');
                    var name = _mpOfficialName(it);
                    var dimensions = Number(it.width || 0) > 0 && Number(it.height || 0) > 0
                        ? Math.round(Number(it.width)) + '×' + Math.round(Number(it.height))
                        : '';
                    html += '<article class="border border-gray-200 rounded-lg overflow-hidden bg-white">'
                         + '<div class="aspect-[12/5] bg-gray-100"><img src="' + _escAttr(preview) + '" class="w-full h-full object-cover" loading="lazy" alt=""></div>'
                         + '<div class="p-3"><div class="flex items-start gap-2"><div class="min-w-0 flex-1">'
                         + '<div class="text-sm font-medium text-gray-800 truncate">' + _escHtml(name) + '</div>'
                         + '<div class="mt-0.5 text-xs text-gray-400">' + _escHtml(dimensions) + '</div></div>'
                         + '<span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">Yikai</span></div>'
                         + '<div class="mt-3 flex items-center gap-2">'
                         + '<button type="button" data-preview="' + _escAttr(previewLarge) + '" onclick="_mpPreviewOfficial(this)" class="px-3 py-1.5 border border-gray-200 rounded text-xs text-gray-600 hover:bg-gray-50">' + _escHtml(_mpOfficialText.preview) + '</button>';
                    if (_mpEntitlement.canImport) {
                        html += '<button type="button" data-asset-id="' + _escAttr(assetId) + '" onclick="_mpImportOfficial(this)" class="flex-1 px-3 py-1.5 rounded bg-gray-900 text-white text-xs hover:bg-black disabled:opacity-50">' + _escHtml(_mpOfficialText.import) + '</button>';
                    } else {
                        html += '<span class="flex-1 text-right text-xs leading-5 text-amber-700">' + _escHtml(_mpOfficialText.upgrade) + '</span>';
                    }
                    html += '</div></div></article>';
                }
                html += '</div>';
                document.getElementById('mpContent').innerHTML = html;
                _renderPager(result);
            } catch (e) {
                document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">' + _escHtml(_mpOfficialText.failed) + '</div>';
            }
        }

        function _mpOfficialName(item) {
            var lang = String(document.documentElement.lang || '').toLowerCase();
            if (lang.indexOf('ja') === 0 && item.name_ja) return String(item.name_ja);
            if (lang.indexOf('en') === 0 && item.name_en) return String(item.name_en);
            return String(item.name || item.name_en || item.name_ja || item.id || '');
        }

        window._mpPreviewOfficial = function(button) {
            var url = String(button.getAttribute('data-preview') || '');
            if (!/^https:\/\/(update|media)\.yikaicms\.com\//i.test(url)
                && !/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//i.test(url)) {
                showMessage(_mpOfficialText.failed, 'error');
                return;
            }
            window.open(url, '_blank', 'noopener,noreferrer');
        };

        window._mpImportOfficial = async function(button) {
            var assetId = String(button.getAttribute('data-asset-id') || '');
            if (!assetId || _mpImporting) return;
            _mpImporting = assetId;
            var original = button.textContent;
            button.disabled = true;
            button.textContent = _mpOfficialText.importing;
            try {
                var token = document.querySelector('meta[name="csrf-token"]');
                var result = await window.OfficialMediaClient.importAsset('/admin/media_api.php', assetId, {
                    csrf: token ? token.content : '',
                });
                if (!result.ok) {
                    showMessage(result.message || _mpOfficialText.failed, 'error');
                    return;
                }
                if (_mpCallback) _mpCallback(result.url, result.data);
                _mpClose();
            } catch (e) {
                showMessage(_mpOfficialText.failed, 'error');
            } finally {
                _mpImporting = '';
                if (document.body.contains(button)) {
                    button.disabled = false;
                    button.textContent = original;
                }
            }
        };

        function _renderPager(d) {
            var pager = document.getElementById('mpPager');
            if (d.pages <= 1) { pager.innerHTML = '<span>' + <?php echo json_encode(__('mp_total_files'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', d.total) + '</span>'; return; }
            var html = '<span>' + <?php echo json_encode(__('mp_total_files'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', d.total) + '</span>';
            if (d.page > 1) html += '<button onclick="_mpLoad(' + (d.page - 1) + ')" class="px-2 py-1 border rounded hover:bg-gray-100 text-xs">' + <?php echo json_encode(__('admin_prev_page'), JSON_UNESCAPED_UNICODE); ?> + '</button>';
            html += '<span class="text-xs">' + d.page + '/' + d.pages + '</span>';
            if (d.page < d.pages) html += '<button onclick="_mpLoad(' + (d.page + 1) + ')" class="px-2 py-1 border rounded hover:bg-gray-100 text-xs">' + <?php echo json_encode(__('admin_next_page'), JSON_UNESCAPED_UNICODE); ?> + '</button>';
            pager.innerHTML = html;
        }

        window._mpSelect = function(el) {
            var url = el.getAttribute('data-url');
            // 取消之前选中
            var prev = document.querySelector('.mp-item.border-primary');
            if (prev) {
                prev.classList.remove('border-primary', 'ring-2', 'ring-primary/30');
                prev.classList.add('border-transparent');
                var prevCheck = prev.querySelector('.mp-check');
                if (prevCheck) prevCheck.classList.add('hidden');
            }
            if (_mpSelected === url) {
                // 取消选中
                _mpSelected = null;
                document.getElementById('mpConfirmBtn').disabled = true;
            } else {
                // 选中当前
                _mpSelected = url;
                el.classList.remove('border-transparent');
                el.classList.add('border-primary', 'ring-2', 'ring-primary/30');
                var check = el.querySelector('.mp-check');
                if (check) check.classList.remove('hidden');
                document.getElementById('mpConfirmBtn').disabled = false;
            }
        };

        window._mpConfirm = function() {
            if (_mpSelected && _mpCallback) {
                _mpCallback(_mpSelected);
            }
            _mpClose();
        };

        window._mpUpload = async function(input) {
            if (!input.files[0]) return;
            var file = input.files[0];
            var formData = new FormData();
            formData.append('file', file);
            formData.append('type', 'images');

            try {
                var resp = await fetch('/admin/media_api.php?action=upload', { method: 'POST', body: formData });
                var data = await resp.json();
                if (data.code === 0) {
                    _mpSelected = data.data.url;
                    document.getElementById('mpConfirmBtn').disabled = false;
                    showMessage(<?php echo json_encode(__('admin_upload_ok'), JSON_UNESCAPED_UNICODE); ?>);
                    _mpLoad(1);
                } else {
                    showMessage(data.msg || <?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
                }
            } catch (e) {
                showMessage(<?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            }
            input.value = '';
        };

        function _escAttr(s) { return _escHtml(String(s || '')).replace(/'/g, '&#39;'); }
        function _escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    })();
    </script>

    <?php if (!empty($extraJs)) echo $extraJs; ?>
    <?php
    // AI 助手 JS（编辑页面 + 已配置 API Key + 面板 HTML 已嵌入）
    if (config('ai_api_key') && isset($GLOBALS['_ai_panel_loaded'])) {
        include __DIR__ . '/ai_panel_js.php';
    }
    ?>
    <script>
function switchAdminLang(lang) {
    var fd = new FormData();
    fd.append('settings[admin_lang]', lang);
    fetch('/admin/setting.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.code === 0) location.reload(); else showMessage(d.msg || 'Error', 'error'); })
        .catch(function() { showMessage('Error', 'error'); });
}
</script>
    <script>window.YK_SO_I18N = <?php echo json_encode([
        'screen_options' => __('so_screen_options'),
        'columns'        => __('so_columns'),
    ], JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="/assets/js/screen-options.js?v=2"></script>
    <!-- flatpickr：统一美化后台所有日期/时间输入（跨浏览器一致 + 本地化日历） -->
    <link rel="stylesheet" href="/assets/flatpickr/flatpickr.min.css">
    <script src="/assets/flatpickr/flatpickr.min.js"></script>
    <?php $__fpLang = str_starts_with((string) config('site_lang', 'zh-CN'), 'ja') ? 'ja' : (str_starts_with((string) config('site_lang', 'zh-CN'), 'zh') ? 'zh' : ''); ?>
    <?php if ($__fpLang): ?><script src="/assets/flatpickr/l10n-<?php echo $__fpLang; ?>.js"></script><?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.flatpickr) return;
        <?php if ($__fpLang === 'zh'): ?>flatpickr.localize(flatpickr.l10ns.zh);<?php elseif ($__fpLang === 'ja'): ?>flatpickr.localize(flatpickr.l10ns.ja);<?php endif; ?>
        // 日期时间输入（发布时间等）：先转 text，否则原生控件会拒绝「Y-m-d H:i」空格格式而清空
        document.querySelectorAll('input[type="datetime-local"]').forEach(function (el) {
            el.value = (el.value || '').replace('T', ' ');   // 原生 ISO 值归一，服务端 strtotime 兼容
            el.type = 'text';
            flatpickr(el, { enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i', allowInput: true });
        });
        // 纯日期输入（日志筛选等）
        document.querySelectorAll('input[type="date"]').forEach(function (el) {
            el.type = 'text';
            flatpickr(el, { dateFormat: 'Y-m-d', allowInput: true });
        });
    });
    </script>
    <?php do_action('ik_admin_footer_scripts'); ?>
</body>
</html>
