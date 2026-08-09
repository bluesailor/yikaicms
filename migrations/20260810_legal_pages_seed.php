<?php
/**
 * 隐私政策 / 服务条款：给存量站补上样板正文。
 *
 * 安装器一直建了这两个栏目却不给正文，客户站上线就挂着两个空白页——比没有更糟。
 * 本迁移只填**正文为空**的栏目：已经自己写过条款的站点一个字都不动。
 *
 * 正文顶部带「模板提示」横幅，明确要求站长按实际业务与所在地法律修改后再发布。
 * 刻意不写任何法域（GDPR / CCPA / 个人信息保护法）的合规承诺——那需要律师按
 * 具体业务定，我们只给结构和常见条目。
 */

declare(strict_types=1);

return [
    'id' => '20260810_legal_pages_seed',
    'title' => '隐私政策与服务条款样板正文',
    'title_en' => 'Starter text for Privacy Policy and Terms of Service',
    'title_ja' => 'プライバシーポリシー・利用規約のひな形',
    'desc' => '给「隐私政策」「服务条款」两个栏目补上三语样板正文（含模板提示横幅，请按实际业务修改后发布）。只填正文为空的栏目，已自行撰写的站点不受影响。',
    'desc_en' => 'Fills the Privacy Policy and Terms of Service pages with starter text in Chinese, English and Japanese, each carrying a notice to review and adapt it before publishing. Only pages that are currently empty are filled; anything you have already written is left alone.',
    'desc_ja' => '「プライバシーポリシー」「利用規約」に日中英のひな形本文を追加します（公開前に自社の実態に合わせて修正する旨の注意書き付き）。本文が空のページのみ対象で、すでに作成済みの内容には触れません。',
    'check' => static function (): bool {
        try {
            $row = db()->fetchOne(
                'SELECT content FROM ' . DB_PREFIX . 'channels WHERE slug = ? LIMIT 1',
                ['privacy']
            );
            if ($row === null) {
                return true;   // 没有这个栏目（老版本或自建结构），不属于本迁移的事
            }
            return trim((string) ($row['content'] ?? '')) !== '';
        } catch (Throwable) {
            return true;       // 探测失败不阻塞升级链
        }
    },
    'sqls' => [],
    'php' => static function (): string {
        $texts = [
            'privacy'    => <<<'HTML'
<div class="yk-tpl-notice"><strong>模板提示：</strong>以下为通用样板，请根据贵司实际的数据收集与使用情况、以及所在地适用法律修改后再发布。如涉及跨境业务或敏感个人信息，建议咨询法律顾问。</div>
<h2>我们收集的信息</h2>
<p>当您访问本网站、填写表单或与我们联系时，我们可能会收集以下信息：您主动提供的姓名、公司、电话、邮箱等联系方式；以及浏览器自动发送的 IP 地址、设备与浏览器类型、访问时间与页面路径等技术信息。</p>
<h2>信息的使用</h2>
<ul>
<li>回复您的咨询、提供报价与售后支持</li>
<li>改进网站内容与用户体验</li>
<li>在您同意的前提下，向您发送产品与服务信息</li>
<li>履行法律法规要求的义务</li>
</ul>
<h2>Cookie</h2>
<p>本网站使用 Cookie 以维持会话、记忆偏好设置并统计访问情况。您可以在浏览器中禁用 Cookie，但部分功能可能因此无法正常使用。</p>
<h2>信息的共享</h2>
<p>除以下情形外，我们不会向第三方出售或出租您的个人信息：为完成您的请求而必须共享给服务提供方（如物流、支付、邮件服务）；法律法规要求或政府主管部门依法要求。</p>
<h2>数据安全</h2>
<p>我们采取合理的技术与管理措施保护您的信息，防止未经授权的访问、披露或损毁。但请理解，任何通过互联网传输的数据都无法保证绝对安全。</p>
<h2>您的权利</h2>
<p>您有权查询、更正或删除我们持有的您的个人信息，也可以随时撤回此前给予的同意。请通过下方联系方式与我们联系。</p>
<h2>政策更新</h2>
<p>本政策如有修改，我们将在本页面公布更新后的版本。请定期查阅。</p>
<h2>联系我们</h2>
<p>如对本隐私政策有任何疑问，请通过网站「联系我们」页面提供的方式与我们联系。</p>
HTML,
            'terms'      => <<<'HTML'
<div class="yk-tpl-notice"><strong>模板提示：</strong>以下为通用样板，请根据贵司实际业务与所在地适用法律修改后再发布。涉及在线交易、会员或订阅服务时，建议咨询法律顾问。</div>
<h2>条款的接受</h2>
<p>访问和使用本网站，即表示您已阅读、理解并同意接受本条款的约束。如不同意，请停止使用本网站。</p>
<h2>网站内容</h2>
<p>本网站所载的产品资料、技术参数与图片仅供参考，不构成要约。实际产品规格以双方签署的合同或订单确认书为准。我们保留随时修改网站内容而不另行通知的权利。</p>
<h2>知识产权</h2>
<p>本网站的文字、图片、标识、版面设计及其它内容，除另有说明外，均归本公司或相应权利人所有。未经书面许可，不得复制、传播或用于商业用途。</p>
<h2>使用限制</h2>
<ul>
<li>不得以任何方式干扰或破坏本网站的正常运行</li>
<li>不得使用自动化程序大量抓取网站内容</li>
<li>不得发布违法、侵权或含有恶意代码的信息</li>
</ul>
<h2>免责声明</h2>
<p>本网站按「现状」提供。在法律允许的最大范围内，我们不对网站内容的准确性、完整性或持续可用性作出明示或默示的保证。</p>
<h2>责任限制</h2>
<p>在法律允许的最大范围内，对于因使用或无法使用本网站而产生的任何间接、偶然或后果性损失，我们不承担责任。</p>
<h2>第三方链接</h2>
<p>本网站可能包含指向第三方网站的链接。这些网站的内容与隐私政策由其各自运营者负责，我们不对其承担责任。</p>
<h2>条款变更</h2>
<p>我们可能不时更新本条款，更新后的版本自在本页面公布之日起生效。</p>
<h2>联系我们</h2>
<p>如对本条款有任何疑问，请通过网站「联系我们」页面提供的方式与我们联系。</p>
HTML,
            'privacy-en' => <<<'HTML'
<div class="yk-tpl-notice"><strong>Template notice:</strong> the text below is a generic starting point. Please revise it to match how your organisation actually collects and uses data, and the laws that apply where you operate. If you handle cross-border transfers or sensitive personal data, seek legal advice.</div>
<h2>Information We Collect</h2>
<p>When you browse this website, submit a form or contact us, we may collect: the details you provide, such as your name, company, phone number and email address; and technical information sent automatically by your browser, such as IP address, device and browser type, and the pages and times you visited.</p>
<h2>How We Use Information</h2>
<ul>
<li>To answer your enquiries and provide quotations and after-sales support</li>
<li>To improve the content and usability of this website</li>
<li>To send you product and service information, where you have agreed to receive it</li>
<li>To meet obligations imposed by applicable law</li>
</ul>
<h2>Cookies</h2>
<p>This website uses cookies to maintain your session, remember preferences and measure traffic. You can disable cookies in your browser, though some features may then stop working correctly.</p>
<h2>Sharing of Information</h2>
<p>We do not sell or rent your personal information. We share it only where necessary to fulfil your request, for example with logistics, payment or email service providers, or where required by law or by a competent authority.</p>
<h2>Data Security</h2>
<p>We apply reasonable technical and organisational measures to protect your information against unauthorised access, disclosure or loss. Please note that no method of transmission over the internet can be guaranteed to be completely secure.</p>
<h2>Your Rights</h2>
<p>You may request access to, correction of, or deletion of the personal information we hold about you, and you may withdraw consent you previously gave. Please contact us using the details below.</p>
<h2>Changes to This Policy</h2>
<p>If we revise this policy, the updated version will be published on this page. Please review it from time to time.</p>
<h2>Contact Us</h2>
<p>If you have any questions about this privacy policy, please reach us through the details on our Contact page.</p>
HTML,
            'terms-en'   => <<<'HTML'
<div class="yk-tpl-notice"><strong>Template notice:</strong> the text below is a generic starting point. Please revise it to match your business and the laws that apply where you operate. If you sell online or run member or subscription services, seek legal advice.</div>
<h2>Acceptance of Terms</h2>
<p>By accessing and using this website you confirm that you have read, understood and agree to be bound by these terms. If you do not agree, please stop using this website.</p>
<h2>Website Content</h2>
<p>Product descriptions, technical data and images on this website are provided for reference only and do not constitute an offer. Actual specifications are those set out in the contract or order confirmation signed by both parties. We may change the content of this website at any time without notice.</p>
<h2>Intellectual Property</h2>
<p>Unless stated otherwise, the text, images, logos, layout and other content on this website belong to us or to the respective rights holders. They may not be copied, redistributed or used commercially without written permission.</p>
<h2>Acceptable Use</h2>
<ul>
<li>Do not interfere with or disrupt the normal operation of this website</li>
<li>Do not use automated tools to harvest content at scale</li>
<li>Do not submit unlawful or infringing material, or material containing malicious code</li>
</ul>
<h2>Disclaimer</h2>
<p>This website is provided on an as-is basis. To the fullest extent permitted by law, we make no express or implied warranty as to the accuracy, completeness or continuous availability of its content.</p>
<h2>Limitation of Liability</h2>
<p>To the fullest extent permitted by law, we are not liable for any indirect, incidental or consequential loss arising from the use of, or inability to use, this website.</p>
<h2>Third-Party Links</h2>
<p>This website may link to third-party sites. Their content and privacy practices are the responsibility of their respective operators, and we accept no liability for them.</p>
<h2>Changes to These Terms</h2>
<p>We may update these terms from time to time. The updated version takes effect when published on this page.</p>
<h2>Contact Us</h2>
<p>If you have any questions about these terms, please reach us through the details on our Contact page.</p>
HTML,
            'privacy-ja' => <<<'HTML'
<div class="yk-tpl-notice"><strong>テンプレートについて：</strong>以下は汎用のひな形です。実際の情報の取得・利用状況および適用される法令に合わせて修正のうえ公開してください。越境移転や要配慮個人情報を扱う場合は、専門家にご相談ください。</div>
<h2>取得する情報</h2>
<p>本サイトの閲覧、フォームの送信、お問い合わせの際に、お客様がご提供になるお名前・会社名・電話番号・メールアドレスなどの連絡先、およびブラウザーから自動的に送信される IP アドレス、端末・ブラウザーの種類、閲覧日時とページなどの技術情報を取得することがあります。</p>
<h2>情報の利用目的</h2>
<ul>
<li>お問い合わせへの回答、見積りおよびアフターサポートの提供</li>
<li>本サイトの内容と使いやすさの改善</li>
<li>ご同意をいただいた場合の、製品・サービス情報のご案内</li>
<li>法令上の義務の履行</li>
</ul>
<h2>Cookie について</h2>
<p>本サイトでは、セッションの維持、設定の記憶、アクセス状況の把握のために Cookie を使用しています。ブラウザーの設定で無効にできますが、一部の機能が正しく動作しなくなる場合があります。</p>
<h2>情報の共有</h2>
<p>お客様の個人情報を第三者に販売または貸与することはありません。ご依頼の履行に必要な範囲で配送・決済・メール配信などの委託先と共有する場合、および法令や公的機関の要請に基づく場合を除きます。</p>
<h2>安全管理</h2>
<p>不正アクセス、漏えい、滅失を防ぐため、合理的な技術的・組織的措置を講じています。ただし、インターネットを通じた通信について完全な安全性を保証することはできません。</p>
<h2>お客様の権利</h2>
<p>当社が保有するお客様の個人情報について、開示・訂正・削除をご請求いただけます。また、いただいた同意はいつでも撤回できます。下記の連絡先までお問い合わせください。</p>
<h2>本ポリシーの改定</h2>
<p>本ポリシーを改定した場合は、改定後の内容を本ページに掲載します。随時ご確認ください。</p>
<h2>お問い合わせ</h2>
<p>本プライバシーポリシーに関するご質問は、「お問い合わせ」ページに記載の方法でご連絡ください。</p>
HTML,
            'terms-ja'   => <<<'HTML'
<div class="yk-tpl-notice"><strong>テンプレートについて：</strong>以下は汎用のひな形です。実際の事業内容および適用される法令に合わせて修正のうえ公開してください。オンライン取引や会員・定期サービスを扱う場合は、専門家にご相談ください。</div>
<h2>本規約への同意</h2>
<p>本サイトをご利用になることで、本規約をお読みになり、理解し、これに従うことに同意されたものとみなします。同意いただけない場合は、本サイトのご利用をお控えください。</p>
<h2>掲載内容について</h2>
<p>本サイトに掲載する製品情報・技術仕様・画像は参考情報であり、申込みの誘引ではありません。実際の仕様は、両当事者が締結した契約または注文請書の内容によります。掲載内容は予告なく変更する場合があります。</p>
<h2>知的財産権</h2>
<p>本サイトの文章、画像、ロゴ、レイアウトその他の内容は、特段の記載がない限り当社または各権利者に帰属します。書面による許諾なく複製、再配布、商用利用することはできません。</p>
<h2>禁止事項</h2>
<ul>
<li>本サイトの正常な運営を妨げる行為</li>
<li>自動化されたプログラムによる大量の情報取得</li>
<li>違法な情報、権利を侵害する情報、悪意あるコードを含む情報の送信</li>
</ul>
<h2>免責事項</h2>
<p>本サイトは現状有姿で提供されます。法令が認める最大限の範囲において、掲載内容の正確性、完全性、継続的な利用可能性について明示・黙示を問わず保証しません。</p>
<h2>責任の制限</h2>
<p>法令が認める最大限の範囲において、本サイトの利用または利用不能から生じた間接損害、付随的損害、結果的損害について、当社は責任を負いません。</p>
<h2>外部リンク</h2>
<p>本サイトには第三者のサイトへのリンクが含まれる場合があります。それらの内容および個人情報の取扱いは各運営者の責任であり、当社は責任を負いません。</p>
<h2>本規約の変更</h2>
<p>本規約は随時改定することがあります。改定後の内容は本ページに掲載した時点から効力を生じます。</p>
<h2>お問い合わせ</h2>
<p>本規約に関するご質問は、「お問い合わせ」ページに記載の方法でご連絡ください。</p>
HTML,
        ];

        $filled = [];
        foreach ($texts as $slug => $html) {
            try {
                $row = db()->fetchOne(
                    'SELECT id, content FROM ' . DB_PREFIX . 'channels WHERE slug = ? LIMIT 1',
                    [$slug]
                );
                if ($row === null) {
                    continue;
                }
                // 已有正文就跳过——客户自己写的条款绝不能被我们的样板盖掉
                if (trim((string) ($row['content'] ?? '')) !== '') {
                    continue;
                }
                db()->execute(
                    'UPDATE ' . DB_PREFIX . 'channels SET content = ? WHERE id = ?',
                    [$html, (int) $row['id']]
                );
                $filled[] = $slug;
            } catch (Throwable $e) {
                error_log('[20260810_legal_pages_seed] ' . $slug . ': ' . $e->getMessage());
            }
        }

        return $filled === []
            ? '两个页面均已有正文，未做改动'
            : ('已补上样板正文：' . implode('、', $filled) . '（请按实际业务修改后发布）');
    },
];
