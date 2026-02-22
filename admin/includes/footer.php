            </main>

            <!-- 底部 -->
            <footer class="p-6 text-center text-gray-500 text-sm">
                &copy; <?php echo date('Y'); ?> Yikai CMS
            </footer>
        </div>
    </div>

    <!-- 通用脚本 -->
    <script src="/assets/swiper/swiper-bundle.min.js"></script>
    <script src="/assets/wangeditor/index.js"></script>
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
            console.error('JSON解析失败:', text);
            return { code: -1, msg: '服务器返回异常' };
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
    function confirmDelete(message = '确定要删除吗？') {
        return confirm(message);
    }

    /**
     * 初始化 wangEditor 编辑器
     * @param {string} toolbarSelector - 工具栏容器选择器
     * @param {string} editorSelector - 编辑区容器选择器
     * @param {object} options - 配置项 { placeholder, html, uploadUrl, onChange }
     * @returns {object} editor 实例
     */
    function initWangEditor(toolbarSelector, editorSelector, options = {}) {
        const { createEditor, createToolbar } = window.wangEditor;

        const editor = createEditor({
            selector: editorSelector,
            html: options.html || '',
            config: {
                placeholder: options.placeholder || '请输入内容...',
                MENU_CONF: {
                    uploadImage: {
                        server: options.uploadUrl || '/admin/upload.php',
                        fieldName: 'file',
                        meta: { type: 'images' },
                        customInsert(res, insertFn) {
                            if (res.code === 0) {
                                insertFn(res.data.url, '', '');
                            }
                        }
                    }
                },
                onChange(editor) {
                    if (options.onChange) options.onChange(editor);
                }
            }
        });

        const toolbar = createToolbar({
            editor,
            selector: toolbarSelector,
            config: {}
        });

        return editor;
    }
    </script>

    <!-- 媒体库选择弹窗 -->
    <div id="mediaPickerModal" class="fixed inset-0 hidden" style="z-index:9999">
        <div class="absolute inset-0 bg-black/50" onclick="_mpClose()"></div>
        <div class="relative mx-auto my-6 bg-white rounded-lg shadow-xl w-full max-w-5xl flex flex-col" style="max-height:calc(100vh - 3rem)">
            <div class="px-6 py-4 border-b flex justify-between items-center flex-shrink-0">
                <h3 class="font-bold text-gray-800">选择媒体文件</h3>
                <button onclick="_mpClose()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-3 border-b flex flex-wrap gap-3 items-center flex-shrink-0">
                <input type="text" id="mpKeyword" class="border rounded px-3 py-1.5 text-sm w-48" placeholder="搜索文件名..." onkeydown="if(event.key==='Enter'){event.preventDefault();_mpLoad(1)}">
                <button onclick="_mpLoad(1)" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm">搜索</button>
                <div class="flex-1"></div>
                <button onclick="document.getElementById('mpFileInput').click()" class="bg-primary hover:bg-secondary text-white px-3 py-1.5 rounded text-sm inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    上传新文件
                </button>
                <input type="file" id="mpFileInput" class="hidden" accept="image/*" onchange="_mpUpload(this)">
            </div>
            <div class="flex-1 overflow-y-auto p-6" id="mpContent">
                <div class="text-center text-gray-400 py-12">加载中...</div>
            </div>
            <div class="px-6 py-3 border-t flex items-center justify-between flex-shrink-0">
                <div id="mpPager" class="flex items-center gap-2 text-sm text-gray-500"></div>
                <div class="flex gap-2">
                    <button onclick="_mpClose()" class="px-4 py-2 border rounded hover:bg-gray-100 text-sm">取消</button>
                    <button onclick="_mpConfirm()" id="mpConfirmBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm disabled:opacity-50" disabled>确定选择</button>
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

        window.openMediaPicker = function(callback, options) {
            options = options || {};
            _mpCallback = callback;
            _mpSelected = null;
            _mpType = options.type || 'image';
            _mpPage = 1;
            document.getElementById('mpKeyword').value = '';
            document.getElementById('mpConfirmBtn').disabled = true;
            document.getElementById('mediaPickerModal').classList.remove('hidden');
            _mpLoad(1);
        };

        window._mpClose = function() {
            document.getElementById('mediaPickerModal').classList.add('hidden');
            _mpCallback = null;
            _mpSelected = null;
        };

        window._mpLoad = async function(page) {
            _mpPage = page;
            var keyword = document.getElementById('mpKeyword').value.trim();
            var url = '/admin/media_api.php?action=list&type=' + encodeURIComponent(_mpType)
                    + '&page=' + page
                    + (keyword ? '&keyword=' + encodeURIComponent(keyword) : '');

            document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">加载中...</div>';

            try {
                var resp = await fetch(url);
                var data = await resp.json();
                if (data.code !== 0) { document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">加载失败</div>'; return; }

                var items = data.data.items;
                if (!items.length) {
                    document.getElementById('mpContent').innerHTML = '<div class="text-center text-gray-400 py-12">暂无媒体文件</div>';
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
                         + '<svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>'
                         + '</div></div>';
                    html += '</div>';
                }
                html += '</div>';

                document.getElementById('mpContent').innerHTML = html;
                _renderPager(data.data);
            } catch (e) {
                document.getElementById('mpContent').innerHTML = '<div class="text-center text-red-400 py-12">请求失败</div>';
            }
        };

        function _renderPager(d) {
            var pager = document.getElementById('mpPager');
            if (d.pages <= 1) { pager.innerHTML = '<span>共 ' + d.total + ' 个文件</span>'; return; }
            var html = '<span>共 ' + d.total + ' 个</span>';
            if (d.page > 1) html += '<button onclick="_mpLoad(' + (d.page - 1) + ')" class="px-2 py-1 border rounded hover:bg-gray-100 text-xs">上一页</button>';
            html += '<span class="text-xs">' + d.page + '/' + d.pages + '</span>';
            if (d.page < d.pages) html += '<button onclick="_mpLoad(' + (d.page + 1) + ')" class="px-2 py-1 border rounded hover:bg-gray-100 text-xs">下一页</button>';
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
                    showMessage('上传成功');
                    _mpLoad(1);
                } else {
                    showMessage(data.msg || '上传失败', 'error');
                }
            } catch (e) {
                showMessage('上传失败', 'error');
            }
            input.value = '';
        };

        function _escAttr(s) { return s.replace(/'/g, "\\'").replace(/"/g, '&quot;'); }
        function _escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    })();
    </script>

    <?php if (!empty($extraJs)) echo $extraJs; ?>
    <?php do_action('ik_admin_footer_scripts'); ?>
</body>
</html>
