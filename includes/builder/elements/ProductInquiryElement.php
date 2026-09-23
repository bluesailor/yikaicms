<?php
/**
 * 产品询价 / 联系操作（product-detail 动态元素）。
 *
 * 为什么单独一个类：它不是"产品行的某个字段"，而是**真实业务动作**——必须复用原生
 * 产品页那条提交链路（form_submit.php + FormSubmissionToken 签名 + 蜜罐 + 限流），
 * 不能只放一个链回产品自身的按钮冒充询价（任务书 §3.3 明确要求）。
 *
 * 边界：
 * - 只在产品模板渲染上下文里输出；无当前产品时整块不渲染。
 * - 所有文案走既有 product_* 键；所有输出 e() 转义。
 * - 脚本按实例唯一 id 绑定，重复插入不会重复绑定。
 */
declare(strict_types=1);

final class ProductInquiryElement extends AbstractElement
{
    /** 表单唯一后缀：同一页面多次插入时不产生重复 id。 */
    private static int $seq = 0;

    public function type(): string { return 'product-inquiry'; }
    public function label(): string { return __('blox_product_inquiry'); }
    public function icon(): string { return 'message-chatbot'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'product-detail'; }

    /** 只暴露外观类控件，不给"改绑定"的口子。 */
    public function controls(): array
    {
        return [
            ['key' => 'radius', 'type' => 'select', 'label' => __('blox_radius'), 'default' => 'md', 'tab' => 'style',
                'options' => ['none' => __('blox_spacing_none'), 'md' => __('blox_spacing_md'), 'xl' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $product = ProductTemplateDocument::currentProduct();
        if ($product === null || (int) ($product['id'] ?? 0) <= 0) {
            return '';
        }
        $productId = (int) $product['id'];
        $productTitle = (string) ($product['title'] ?? '');

        self::$seq++;
        $suffix = 'blox-inquiry-' . self::$seq;
        $formId = $suffix . '-form';
        $msgId = $suffix . '-msg';

        $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
        $productSignature = class_exists('FormSubmissionToken')
            ? FormSubmissionToken::contextSign('product-inquiry', $productId, $secret)
            : '';
        $lang = siteLang();
        $fields = renderProductInquiryFields($productTitle);
        if ($fields === '') return '';

        $radiusKey = is_string($data['radius'] ?? null) ? $data['radius'] : 'md';
        $radius = ['none' => '', 'md' => ' rounded-lg', 'xl' => ' rounded-2xl'][$radiusKey] ?? '';

        // 预览态（画布样本预览）不得落真实数据：与原生「主题默认预览」同款做法——
        // product.php 也是用 fieldset disabled 包住询价表单。字段被禁用后表单从结构上
        // 就提交不出内容，脚本再拦一层，避免有人在开发者工具里放开后误提交。
        $isPreview = ProductTemplateDocument::isPreview();
        $endpoint = '/form_submit.php?_lang=' . rawurlencode($lang);

        // 显式 POST + 受控提交地址：脚本没跑起来时（被拦/加载失败）浏览器按 POST 提交到
        // form_submit.php，姓名电话落在请求体里，不会像默认 GET 那样拼进当前页查询串。
        $formAttrs = $isPreview
            ? ' data-yk-preview="1"'
            : ' method="post" action="' . e($endpoint) . '"';

        $html = '<div class="yk-product-inquiry' . $radius . '" data-yk-product-inquiry="' . $productId . '">'
            . '<h3 class="mb-3 flex items-center gap-2 text-sm font-bold text-dark">'
            . '<i class="ti ti-message-chatbot text-primary" aria-hidden="true"></i>' . e(__('product_inquiry')) . '</h3>'
            . ($isPreview ? '<p class="mb-2 text-xs text-amber-600">' . e(__('blox_product_inquiry_preview')) . '</p>' : '')
            . '<form id="' . e($formId) . '" class="space-y-3" enctype="multipart/form-data"' . $formAttrs . '>'
            . renderFormSecurityFields('product-inquiry')
            . '<input type="hidden" name="product_id" value="' . $productId . '">'
            // Retained for old integrations/display only; form_submit.php never trusts this value.
            . '<input type="hidden" name="product_title" value="' . e($productTitle) . '">'
            . '<input type="hidden" name="product_sig" value="' . e($productSignature) . '">'
            . ($isPreview ? '<fieldset disabled class="space-y-3">' . $fields . '</fieldset>' : $fields)
            . '<p id="' . e($msgId) . '" class="hidden text-center text-sm" role="status" aria-live="polite"></p>'
            . '</form></div>';

        return $html . renderFormNonceClientScript() . $this->script($formId, $msgId, $isPreview);
    }

    /** 提交脚本：只绑定本实例的表单，重复插入不会重复绑定。 */
    private function script(string $formId, string $msgId, bool $isPreview): string
    {
        $form = json_encode($formId);
        $message = json_encode($msgId);
        $submitting = json_encode(__('product_submitting'));
        $submitFallback = json_encode(__('product_btn_submit_inq'));
        $networkError = json_encode(__('product_network_error'));
        $previewNotice = json_encode(__('blox_product_inquiry_preview'));

        return '<script>(function(){'
            . 'var form=document.getElementById(' . $form . ');'
            . 'if(!form||form.dataset.ykBound==="1")return;'
            . 'form.dataset.ykBound="1";'
            . 'var btn=form.querySelector("button[type=submit]");if(!btn)return;'
            . 'var submitLabel=btn.textContent||' . $submitFallback . ';'
            . 'var msg=document.getElementById(' . $message . ');'
            // 提交目标读表单自己的 action（服务端已写成 POST + form_submit.php）；
            // 预览态没有 action，脚本也不该找备用地址出去
            . 'var endpoint=form.getAttribute("action")||"";'
            . 'var preview=' . ($isPreview ? 'true' : 'form.getAttribute("data-yk-preview")==="1"') . ';'
            . 'form.addEventListener("submit",function(e){'
            . 'e.preventDefault();'
            // 预览态：只提示，不发请求——预览永远不能产生真实询价
            . 'if(preview||endpoint===""){'
            . 'if(msg){msg.classList.remove("hidden");msg.className="text-center text-sm text-amber-600";msg.textContent=' . $previewNotice . ';}'
            . 'return;}'
            . 'btn.disabled=true;btn.textContent=' . $submitting . ';'
            . 'if(msg){msg.classList.add("hidden");}'
            . 'window.ykFormNonce(form).then(function(){var body=new FormData(form);var nonce=form.elements.namedItem("form_nonce");if(nonce)nonce.value="";'
            . 'return fetch(endpoint,{method:"POST",body:body});})'
            . '.then(function(r){return r.json();})'
            . '.then(function(data){'
            . 'if(msg){msg.classList.remove("hidden");'
            . 'msg.className="text-center text-sm "+(data.code===0?"text-green-600":"text-red-600");'
            . 'msg.textContent=data.msg||"";}'
            . 'if(data.code===0){form.reset();}'
            // 令牌刷新：服务器返回新签名时换掉隐藏字段，避免第二次提交因过期失败
            . 'if(data.refresh_token){["form_ts","form_sig"].forEach(function(k){'
            . 'var f=form.elements.namedItem(k);if(f){f.value=String(data.refresh_token[k]);f.defaultValue=f.value;}});}'
            . 'var captcha=form.querySelector(\'img[src*="captcha.php"]\');'
            . 'if(captcha){captcha.src="' . BasePath::url('/captcha.php') . '?"+Date.now();}'
            . 'btn.disabled=false;btn.textContent=submitLabel;'
            . '}).catch(function(){'
            . 'if(msg){msg.classList.remove("hidden");msg.className="text-center text-sm text-red-600";msg.textContent=' . $networkError . ';}'
            . 'btn.disabled=false;btn.textContent=submitLabel;});'
            . '});'
            . 'btn.textContent=submitLabel;'
            . '})();</script>';
    }
}
