"""Generate the bundled starter component packages (templates/blox/components/*.json).

Run from the repo root: python tools/blox/gen-starter-components.py .
"""
import hashlib
import json
import os
import sys

root = sys.argv[1]
out_dir = os.path.join(root, 'templates', 'blox', 'components')
os.makedirs(out_dir, exist_ok=True)


def uuid(slug):
    return 'cmp_' + hashlib.md5(('yikai-starter:' + slug).encode()).hexdigest()[:16]


def card(children, **extra):
    data = {
        'layout': 'flex', 'direction': {'d': 'column'}, 'align': {'d': 'start'}, 'gap': {'d': 'sm'}, 'padding': {'d': 'lg'},
        'bg_color': '#ffffff', 'radius_token': 'lg', 'shadow_token': 'sm', 'children': children,
    }
    data.update(extra)
    return {'id': 'e_root', 'type': 'container', 'data': data}


def prop(key, ptype, label, default, targets, i18n=None, **extra):
    p = {'key': key, 'type': ptype, 'label': label, 'default': default,
         'targets': [{'node': n, 'field': f} for n, f in targets]}
    if i18n:
        p['localizable'] = True
        p['default_i18n'] = i18n
    p.update(extra)
    return p


COMPONENTS = []

# 1 功能卡片
COMPONENTS.append(dict(
    slug='feature-card', names={'en': 'Feature card', 'ja': '機能カード'}, category='cards',
    name='功能卡片', description='图标、标题、说明和链接，适合列出产品特点或服务项目。',
    root=card([
        {'id': 'e_icon', 'type': 'icon', 'data': {'icon': 'rocket', 'size': 'lg'}},
        {'id': 'e_title', 'type': 'heading', 'data': {'text': '快速部署', 'level': 'h3', 'type_token': 'heading-sm'}},
        {'id': 'e_text', 'type': 'text', 'data': {'html': '<p>从选型到上线全程协助，标准方案一周内交付。</p>'}},
        {'id': 'e_link', 'type': 'button', 'data': {'text': '了解更多', 'url': '/contact.html', 'variant': 'link', 'icon': 'arrow-right', 'icon_position': 'right'}},
    ]),
    props=[
        prop('icon', 'icon', '图标', 'rocket', [('e_icon', 'icon')]),
        prop('title', 'text', '标题', '快速部署', [('e_title', 'text')], {'en': 'Fast Deployment', 'ja': 'スピード導入'}),
        prop('text', 'richtext', '说明', '<p>从选型到上线全程协助，标准方案一周内交付。</p>', [('e_text', 'html')],
             {'en': '<p>We help from selection to launch; standard solutions ship within a week.</p>',
              'ja': '<p>選定から公開までサポートし、標準プランは1週間で納品します。</p>'}),
        prop('link_text', 'text', '链接文字', '了解更多', [('e_link', 'text')], {'en': 'Learn more', 'ja': '詳しく見る'}),
        prop('link_url', 'url', '链接地址', '/contact.html', [('e_link', 'url')]),
    ],
))

# 2 价格卡片
COMPONENTS.append(dict(
    slug='pricing-card', names={'en': 'Pricing card', 'ja': '料金カード'}, category='cards',
    name='价格卡片', description='方案名、价格、包含内容和购买按钮。',
    root=card([
        {'id': 'e_plan', 'type': 'heading', 'data': {'text': '标准版', 'level': 'h3', 'type_token': 'heading-sm'}},
        {'id': 'e_price', 'type': 'text', 'data': {'html': '<p><strong>¥2,980</strong> / 年</p>', 'type_token': 'heading-lg'}},
        {'id': 'e_features', 'type': 'text', 'data': {'html': '<ul><li>5 个站点</li><li>全部模板</li><li>工作日技术支持</li></ul>'}},
        {'id': 'e_cta', 'type': 'button', 'data': {'text': '立即咨询', 'url': '/contact.html', 'variant': 'primary', 'shape': 'pill'}},
    ], gap={'d': 'md'}),
    props=[
        prop('plan', 'text', '方案名', '标准版', [('e_plan', 'text')], {'en': 'Standard', 'ja': 'スタンダード'}),
        prop('price', 'richtext', '价格', '<p><strong>¥2,980</strong> / 年</p>', [('e_price', 'html')],
             {'en': '<p><strong>$399</strong> / year</p>', 'ja': '<p><strong>¥39,800</strong> / 年</p>'}),
        prop('features', 'richtext', '包含内容', '<ul><li>5 个站点</li><li>全部模板</li><li>工作日技术支持</li></ul>', [('e_features', 'html')],
             {'en': '<ul><li>5 sites</li><li>All templates</li><li>Business-day support</li></ul>',
              'ja': '<ul><li>5サイト</li><li>全テンプレート</li><li>平日サポート</li></ul>'}),
        prop('cta_text', 'text', '按钮文字', '立即咨询', [('e_cta', 'text')], {'en': 'Contact sales', 'ja': 'お問い合わせ'}),
        prop('cta_url', 'url', '按钮链接', '/contact.html', [('e_cta', 'url')]),
    ],
))

# 3 评价卡片
COMPONENTS.append(dict(
    slug='testimonial-card', names={'en': 'Testimonial card', 'ja': 'お客様の声カード'}, category='content',
    name='评价卡片', description='客户头像、评价内容、姓名与职位。',
    root=card([
        {'id': 'e_quote', 'type': 'quote', 'data': {'text': '从选型到交付只用了三周，技术团队全程跟进。', 'author': ''}},
        {'id': 'e_person', 'type': 'container', 'data': {
            'layout': 'flex', 'direction': {'d': 'row'}, 'align': {'d': 'center'}, 'gap': {'d': 'sm'}, 'children': [
                {'id': 'e_avatar_box', 'type': 'container', 'data': {'flex_basis_px': {'d': '56px'}, 'flex_shrink': '0', 'children': [
                    {'id': 'e_avatar', 'type': 'image', 'data': {'src': '/assets/images/blox-templates/avatar-1.svg', 'alt': '陈思远', 'image_preset': 'avatar'}},
                ]}},
                {'id': 'e_who', 'type': 'container', 'data': {'layout': 'flex', 'direction': {'d': 'column'}, 'gap': {'d': 'none'}, 'children': [
                    {'id': 'e_name', 'type': 'heading', 'data': {'text': '陈思远', 'level': 'h4', 'type_token': 'text-base'}},
                    {'id': 'e_role', 'type': 'text', 'data': {'html': '<p>采购总监 · 华东制造集团</p>', 'typography_role': 'caption'}},
                ]}},
            ],
        }},
    ]),
    props=[
        prop('quote', 'text', '评价内容', '从选型到交付只用了三周，技术团队全程跟进。', [('e_quote', 'text')],
             {'en': 'From selection to delivery took just three weeks, with engineers on hand throughout.',
              'ja': '選定から納品までわずか3週間。技術チームが最後まで伴走してくれました。'}),
        prop('author', 'text', '姓名', '陈思远', [('e_name', 'text'), ('e_avatar', 'alt')], {'en': 'Siyuan Chen', 'ja': '陳 思遠'}),
        prop('role', 'richtext', '职位', '<p>采购总监 · 华东制造集团</p>', [('e_role', 'html')],
             {'en': '<p>Procurement Director · East China Manufacturing Group</p>', 'ja': '<p>購買部長・華東製造グループ</p>'}),
        prop('avatar', 'image', '头像', '/assets/images/blox-templates/avatar-1.svg', [('e_avatar', 'src')]),
    ],
))

# 4 行动号召条
COMPONENTS.append(dict(
    slug='cta-bar', names={'en': 'Call-to-action bar', 'ja': 'CTAバー'}, category='cta',
    name='行动号召条', description='一行标题、说明和按钮，放在页面段落之间引导咨询。',
    root={'id': 'e_root', 'type': 'container', 'data': {
        'layout': 'flex', 'direction': {'d': 'row', 'm': 'column'}, 'justify': {'d': 'between'}, 'align': {'d': 'center'},
        'gap': {'d': 'md'}, 'padding': {'d': 'lg'}, 'bg_color': '#1e3a8a', 'radius_token': 'lg',
        'children': [
            {'id': 'e_copy', 'type': 'container', 'data': {'layout': 'flex', 'direction': {'d': 'column'}, 'gap': {'d': 'sm'}, 'children': [
                {'id': 'e_title', 'type': 'heading', 'data': {'text': '准备好开始了吗？', 'level': 'h3', 'color': '#ffffff'}},
                {'id': 'e_text', 'type': 'text', 'data': {'html': '<p>留下需求，一个工作日内给出方案与报价。</p>', 'color': '#dbeafe'}},
            ]}},
            {'id': 'e_cta', 'type': 'button', 'data': {'text': '联系我们', 'url': '/contact.html', 'variant': 'soft', 'shape': 'pill'}},
        ],
    }},
    props=[
        prop('title', 'text', '标题', '准备好开始了吗？', [('e_title', 'text')], {'en': 'Ready to get started?', 'ja': 'さっそく始めませんか？'}),
        prop('text', 'richtext', '说明', '<p>留下需求，一个工作日内给出方案与报价。</p>', [('e_text', 'html')],
             {'en': '<p>Tell us what you need and get a proposal with pricing within one business day.</p>',
              'ja': '<p>ご要望をお知らせいただければ、1営業日以内にご提案とお見積りをお送りします。</p>'}),
        prop('button_text', 'text', '按钮文字', '联系我们', [('e_cta', 'text')], {'en': 'Contact us', 'ja': 'お問い合わせ'}),
        prop('button_url', 'url', '按钮链接', '/contact.html', [('e_cta', 'url')]),
        prop('background', 'color', '背景色', '#1e3a8a', [('e_root', 'bg_color')]),
    ],
))

# 5 团队成员
COMPONENTS.append(dict(
    slug='team-member', names={'en': 'Team member', 'ja': 'チームメンバー'}, category='cards',
    name='团队成员', description='照片、姓名、职位和一句介绍。',
    root=card([
        {'id': 'e_photo', 'type': 'image', 'data': {'src': '/assets/images/blox-templates/avatar-2.svg', 'alt': '林晓雯', 'image_ratio': 'square'}},
        {'id': 'e_name', 'type': 'heading', 'data': {'text': '林晓雯', 'level': 'h3', 'type_token': 'heading-sm'}},
        {'id': 'e_role', 'type': 'text', 'data': {'html': '<p>客户成功经理</p>', 'typography_role': 'caption'}},
        {'id': 'e_bio', 'type': 'text', 'data': {'html': '<p>十年企业服务经验，负责项目交付与长期运维。</p>'}},
    ]),
    props=[
        prop('photo', 'image', '照片', '/assets/images/blox-templates/avatar-2.svg', [('e_photo', 'src')]),
        prop('name', 'text', '姓名', '林晓雯', [('e_name', 'text'), ('e_photo', 'alt')], {'en': 'Xiaowen Lin', 'ja': '林 暁雯'}),
        prop('role', 'richtext', '职位', '<p>客户成功经理</p>', [('e_role', 'html')],
             {'en': '<p>Customer Success Manager</p>', 'ja': '<p>カスタマーサクセスマネージャー</p>'}),
        prop('bio', 'richtext', '介绍', '<p>十年企业服务经验，负责项目交付与长期运维。</p>', [('e_bio', 'html')],
             {'en': '<p>Ten years in enterprise services, leading delivery and long-term support.</p>',
              'ja': '<p>法人向けサービス歴10年。導入から長期運用まで担当しています。</p>'}),
    ],
))

# 6 常见问题条目
COMPONENTS.append(dict(
    slug='faq-item', names={'en': 'FAQ item', 'ja': 'FAQ項目'}, category='content',
    name='常见问题条目', description='一个问题和它的回答；多条叠在一起就是问答列表。',
    root={'id': 'e_root', 'type': 'container', 'data': {
        'layout': 'flex', 'direction': {'d': 'column'}, 'gap': {'d': 'sm'}, 'padding': {'d': 'md'},
        'bg_color': '#ffffff', 'radius_token': 'md', 'shadow_token': 'sm',
        'children': [
            {'id': 'e_q', 'type': 'heading', 'data': {'text': '交货周期是多久？', 'level': 'h3', 'type_token': 'text-lg'}},
            {'id': 'e_a', 'type': 'text', 'data': {'html': '<p>标准产品 3 个工作日内发货，定制订单按合同排期。</p>'}},
        ],
    }},
    props=[
        prop('question', 'text', '问题', '交货周期是多久？', [('e_q', 'text')], {'en': 'How long is the lead time?', 'ja': '納期はどのくらいですか？'}),
        prop('answer', 'richtext', '回答', '<p>标准产品 3 个工作日内发货，定制订单按合同排期。</p>', [('e_a', 'html')],
             {'en': '<p>Standard products ship within 3 business days; custom orders follow the contract schedule.</p>',
              'ja': '<p>標準品は3営業日以内に出荷し、特注品は契約のスケジュールに沿って対応します。</p>'}),
    ],
))

# 7 图标列表项
COMPONENTS.append(dict(
    slug='icon-list-item', names={'en': 'Icon list item', 'ja': 'アイコンリスト項目'}, category='content',
    name='图标列表项', description='一个图标加一行文字，适合列优势、资质或服务承诺。',
    root={'id': 'e_root', 'type': 'container', 'data': {
        'layout': 'flex', 'direction': {'d': 'row'}, 'align': {'d': 'center'}, 'gap': {'d': 'sm'},
        'children': [
            {'id': 'e_icon', 'type': 'icon', 'data': {'icon': 'circle-check', 'size': 'md', 'color': '#16a34a'}},
            {'id': 'e_text', 'type': 'text', 'data': {'html': '<p>通过 ISO 9001 质量管理体系认证</p>'}},
        ],
    }},
    props=[
        prop('icon', 'icon', '图标', 'circle-check', [('e_icon', 'icon')]),
        prop('text', 'richtext', '文字', '<p>通过 ISO 9001 质量管理体系认证</p>', [('e_text', 'html')],
             {'en': '<p>ISO 9001 certified quality management</p>', 'ja': '<p>ISO 9001 品質マネジメントシステム認証取得</p>'}),
        prop('icon_color', 'color', '图标颜色', '#16a34a', [('e_icon', 'color')]),
    ],
))

# 8 联系卡片
COMPONENTS.append(dict(
    slug='contact-card', names={'en': 'Contact card', 'ja': '連絡先カード'}, category='cards',
    name='联系卡片', description='联系方式图标、标题、内容和一个直达链接（电话、邮箱或地图）。',
    root=card([
        {'id': 'e_icon', 'type': 'icon', 'data': {'icon': 'phone', 'size': 'lg'}},
        {'id': 'e_title', 'type': 'heading', 'data': {'text': '电话咨询', 'level': 'h3', 'type_token': 'heading-sm'}},
        {'id': 'e_detail', 'type': 'text', 'data': {'html': '<p>400-800-1234（工作日 9:00–18:00）</p>'}},
        {'id': 'e_link', 'type': 'button', 'data': {'text': '立即拨打', 'url': 'tel:4008001234', 'variant': 'outline'}},
    ]),
    props=[
        prop('icon', 'icon', '图标', 'phone', [('e_icon', 'icon')]),
        prop('title', 'text', '标题', '电话咨询', [('e_title', 'text')], {'en': 'Call us', 'ja': 'お電話'}),
        prop('detail', 'richtext', '内容', '<p>400-800-1234（工作日 9:00–18:00）</p>', [('e_detail', 'html')],
             {'en': '<p>+86 400-800-1234 (weekdays 9:00–18:00)</p>', 'ja': '<p>+86 400-800-1234（平日 9:00〜18:00）</p>'}),
        prop('link_text', 'text', '链接文字', '立即拨打', [('e_link', 'text')], {'en': 'Call now', 'ja': '電話する'}),
        prop('link_url', 'url', '链接地址', 'tel:4008001234', [('e_link', 'url')]),
    ],
))

elements = set()


def collect(el):
    elements.add(el['type'])
    for child in el.get('data', {}).get('children', []) or []:
        collect(child)


for c in COMPONENTS:
    elements.clear()
    collect(c['root'])
    package = {
        'format': 'yikaicms-blox-template',
        'version': 1,
        'type': 'component',
        'name': c['name'],
        'requires': {'elements': sorted(elements), 'plugins': []},
        'component': {'uuid': uuid(c['slug']), 'category': c['category'], 'description': c['description']},
        # 组件库名称按站点默认语言取（安装器读，导入器不认这个键）
        'names': {'zh-CN': c['name'], **c['names']},
        'document': {
            'schema': 1,
            'settings': {'component': {'props': c['props']}},
            'sections': [{'id': 's_cmp', 'type': 'section', 'settings': {},
                          'columns': [{'id': 'c_cmp', 'elements': [c['root']]}]}],
        },
    }
    path = os.path.join(out_dir, c['slug'] + '.json')
    with open(path, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(package, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print(path, package['component']['uuid'])
