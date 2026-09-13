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
        $buttonId = $suffix . '-btn';
        $msgId = $suffix . '-msg';

        $timestamp = time();
        $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
        $signature = class_exists('FormSubmissionToken')
            ? FormSubmissionToken::sign('product-inquiry', $timestamp, $secret)
            : '';
        $lang = siteLang();

        // 与原生同款：留言框预填"关于某产品的咨询"
        $defaultMessage = sprintf((string) __('product_default_inq_msg'), $productTitle);

        // 验证码按表单设计器配置渲染；查询失败不能让产品页整块崩掉（服务端仍会照常校验）
        $captchaHtml = '';
        if (function_exists('renderFormCaptcha') && function_exists('formTemplateModel')) {
            try {
                $template = formTemplateModel()->findBySlug('product-inquiry');
                $captchaHtml = renderFormCaptcha(!empty($template['captcha']));
            } catch (Throwable $e) {
                $captchaHtml = '';
            }
        }

        $radiusKey = is_string($data['radius'] ?? null) ? $data['radius'] : 'md';
        $radius = ['none' => '', 'md' => ' rounded-lg', 'xl' => ' rounded-2xl'][$radiusKey] ?? '';

        $html = '<div class="yk-product-inquiry' . $radius . '" data-yk-product-inquiry="' . $productId . '">'
            . '<h3 class="mb-3 flex items-center gap-2 text-sm font-bold text-dark">'
            . '<i class="ti ti-message-chatbot text-primary" aria-hidden="true"></i>' . e(__('product_inquiry')) . '</h3>'
            . '<form id="' . e($formId) . '" class="space-y-3" data-yk-inquiry-endpoint="/form_submit.php?_lang=' . rawurlencode($lang) . '">'
            . '<input type="hidden" name="form_slug" value="product-inquiry">'
            . '<input type="hidden" name="_lang" value="' . e($lang) . '">'
            . '<input type="hidden" name="form_ts" value="' . (int) $timestamp . '">'
            . '<input type="hidden" name="form_sig" value="' . e($signature) . '">'
            . '<input type="hidden" name="product_id" value="' . $productId . '">'
            . '<input type="hidden" name="product_title" value="' . e($productTitle) . '">'
            // 蜜罐：正常用户看不到；机器人填了会被 form_submit.php 静默丢弃
            . '<input type="text" name="hp_url" tabindex="-1" autocomplete="off" aria-hidden="true" '
            . 'style="position:absolute!important;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none">'
            . '<div class="grid grid-cols-2 gap-3">'
            . '<input type="text" name="name" required placeholder="' . e(__('product_field_name_ph')) . '" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">'
            . '<input type="tel" name="phone" required placeholder="' . e(__('product_field_phone_ph')) . '" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">'
            . '</div>'
            . '<div class="grid grid-cols-2 gap-3">'
            . '<input type="email" name="email" placeholder="' . e(__('product_field_email_ph')) . '" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">'
            . '<input type="text" name="company" placeholder="' . e(__('product_field_company_ph')) . '" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">'
            . '</div>'
            . '<textarea name="content" required rows="3" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">' . e($defaultMessage) . '</textarea>'
            . $captchaHtml
            . '<button type="submit" id="' . e($buttonId) . '" class="w-full rounded bg-primary py-2.5 text-sm font-medium text-white hover:bg-secondary transition">'
            . e(__('product_btn_submit_inq')) . '</button>'
            . '<p id="' . e($msgId) . '" class="hidden text-center text-sm" role="status" aria-live="polite"></p>'
            . '</form></div>';

        return $html . $this->script($formId, $buttonId, $msgId);
    }

    /** 提交脚本：只绑定本实例的表单，重复插入不会重复绑定。 */
    private function script(string $formId, string $buttonId, string $msgId): string
    {
        $form = json_encode($formId);
        $button = json_encode($buttonId);
        $message = json_encode($msgId);
        $submitting = json_encode(__('product_submitting'));
        $submitLabel = json_encode(__('product_btn_submit_inq'));
        $networkError = json_encode(__('product_network_error'));

        return '<script>(function(){'
            . 'var form=document.getElementById(' . $form . ');'
            . 'if(!form||form.dataset.ykBound==="1")return;'
            . 'form.dataset.ykBound="1";'
            . 'var btn=document.getElementById(' . $button . ');'
            . 'var msg=document.getElementById(' . $message . ');'
            . 'var endpoint=form.getAttribute("data-yk-inquiry-endpoint")||"/form_submit.php";'
            . 'form.addEventListener("submit",function(e){'
            . 'e.preventDefault();'
            . 'btn.disabled=true;'
            . 'if(msg){msg.classList.add("hidden");}'
            . 'fetch(endpoint,{method:"POST",body:new FormData(form)})'
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
            . 'if(captcha){captcha.src="/captcha.php?"+Date.now();}'
            . 'btn.disabled=false;btn.textContent=' . $submitLabel . ';'
            . '}).catch(function(){'
            . 'if(msg){msg.classList.remove("hidden");msg.className="text-center text-sm text-red-600";msg.textContent=' . $networkError . ';}'
            . 'btn.disabled=false;btn.textContent=' . $submitLabel . ';});'
            . '});'
            . 'if(btn){btn.textContent=btn.textContent||' . $submitLabel . ';}'
            . 'void ' . $submitting . ';'
            . '})();</script>';
    }
}
