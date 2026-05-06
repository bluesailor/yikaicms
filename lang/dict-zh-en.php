<?php
/**
 * 中文→英文 网站常用词典
 *
 * 用于栏目名、菜单名、文章标题等快速翻译
 * AI 翻译前先查字典，命中则直接使用，未命中再调 AI
 *
 * 使用: $dict = require ROOT_PATH . '/lang/dict-zh-en.php';
 *       $en = $dict['关于我们'] ?? null; // 'About Us'
 */

return [
    // ============ 导航/栏目 ============
    '首页' => 'Home',
    '返回列表' => 'Back to List',
    '暂无内容' => 'No Content',
    '暂无数据' => 'No Data',
    '关于我们' => 'About Us',
    '关于' => 'About',
    '公司简介' => 'Company Profile',
    '企业简介' => 'Company Profile',
    '协会简介' => 'About the Association',
    '企业文化' => 'Corporate Culture',
    '发展历程' => 'History',
    '组织架构' => 'Organization',
    '组织机构' => 'Organization',
    '荣誉资质' => 'Honors & Certifications',
    '团队风采' => 'Our Team',

    '产品中心' => 'Products',
    '产品展示' => 'Products',
    '产品列表' => 'Product List',
    '产品详情' => 'Product Details',
    '热门产品' => 'Hot Products',
    '新品推荐' => 'New Products',
    '全部产品' => 'All Products',

    '新闻中心' => 'News',
    '新闻资讯' => 'News',
    '公司新闻' => 'Company News',
    '行业动态' => 'Industry News',
    '行业资讯' => 'Industry News',
    '媒体报道' => 'Media Coverage',
    '通知公告' => 'Announcements',
    '协会通知' => 'Notices',
    '图片新闻' => 'Photo News',
    '工作研讨' => 'Work Seminars',

    '解决方案' => 'Solutions',
    '成功案例' => 'Case Studies',
    '案例展示' => 'Case Studies',
    '行业方案' => 'Industry Solutions',

    '服务支持' => 'Services',
    '服务流程' => 'Service Process',
    '售后服务' => 'After-Sales Service',
    '常见问题' => 'FAQ',
    '技术支持' => 'Technical Support',

    '人才招聘' => 'Careers',
    '招聘信息' => 'Job Openings',
    '加入我们' => 'Join Us',

    '联系我们' => 'Contact Us',
    '在线留言' => 'Leave a Message',
    '联系方式' => 'Contact Information',

    '下载中心' => 'Downloads',
    '资料下载' => 'Downloads',

    '会员之窗' => 'Members',
    '会员风采' => 'Member Showcase',
    '会员服务' => 'Member Services',
    '入会申请' => 'Membership Application',
    '入会程序' => 'How to Join',
    '入会申请表' => 'Application Form',

    '行业品牌' => 'Brands',
    '服务品牌' => 'Service Brands',
    '商品品牌' => 'Product Brands',
    '品牌展示' => 'Brand Showcase',

    '供求信息' => 'Supply & Demand',
    '培训天地' => 'Training',
    '法规标准' => 'Regulations & Standards',
    '协会月刊' => 'Monthly Journal',
    '分支机构' => 'Branches',
    '新年贺词' => 'New Year Greetings',

    '隐私政策' => 'Privacy Policy',
    '服务条款' => 'Terms of Service',
    '网站地图' => 'Sitemap',
    '友情链接' => 'Links',
    '合作伙伴' => 'Partners',

    // ============ 页面元素 ============
    '更多' => 'More',
    '查看更多' => 'View More',
    '了解更多' => 'Learn More',
    '立即咨询' => 'Inquire Now',
    '免费咨询' => 'Free Consultation',
    '在线咨询' => 'Online Inquiry',
    '获取报价' => 'Get a Quote',
    '提交' => 'Submit',
    '发送' => 'Send',
    '搜索' => 'Search',
    '返回顶部' => 'Back to Top',
    '返回首页' => 'Back to Home',
    '上一页' => 'Previous',
    '下一页' => 'Next',
    '上一篇' => 'Previous',
    '下一篇' => 'Next',
    '全部' => 'All',
    '推荐' => 'Featured',
    '热门' => 'Hot',
    '最新' => 'Latest',
    '置顶' => 'Pinned',

    // ============ 表单 ============
    '姓名' => 'Name',
    '您的姓名' => 'Your Name',
    '称呼' => 'Name',
    '电话' => 'Phone',
    '手机' => 'Mobile',
    '联系电话' => 'Phone',
    '邮箱' => 'Email',
    '电子邮箱' => 'Email',
    '公司' => 'Company',
    '公司名称' => 'Company Name',
    '地址' => 'Address',
    '公司地址' => 'Company Address',
    '留言内容' => 'Message',
    '留言' => 'Message',
    '主题' => 'Subject',
    '验证码' => 'Captcha',
    '提交留言' => 'Send Message',
    '必填' => 'Required',

    // ============ 文章/内容 ============
    '阅读全文' => 'Read More',
    '发布时间' => 'Published',
    '作者' => 'Author',
    '来源' => 'Source',
    '浏览量' => 'Views',
    '阅读' => 'Views',
    '分享' => 'Share',
    '标签' => 'Tags',
    '分类' => 'Category',
    '相关文章' => 'Related Articles',
    '相关产品' => 'Related Products',
    '相关推荐' => 'Recommended',

    // ============ 产品 ============
    '价格' => 'Price',
    '型号' => 'Model',
    '规格' => 'Specifications',
    '品牌' => 'Brand',
    '材质' => 'Material',
    '颜色' => 'Color',
    '尺寸' => 'Size',
    '重量' => 'Weight',
    '包装' => 'Packaging',
    '产地' => 'Origin',
    '库存' => 'Stock',
    '起订量' => 'MOQ',
    '交货期' => 'Delivery Time',
    '面议' => 'Negotiable',
    '询价' => 'Inquiry',

    // ============ 页脚 ============
    '快速链接' => 'Quick Links',
    '关注我们' => 'Follow Us',
    '版权所有' => 'All Rights Reserved',
    '备案号' => 'Filing No.',
    '工作时间' => 'Business Hours',
    '传真' => 'Fax',
    '邮编' => 'Zip Code',

    // ============ 行业通用 ============
    '质量保证' => 'Quality Assurance',
    '技术领先' => 'Technology Leadership',
    '专业服务' => 'Professional Service',
    '合作共赢' => 'Win-Win Cooperation',
    '品质保证' => 'Quality Guarantee',
    '诚信经营' => 'Integrity Management',
    '创新发展' => 'Innovation & Development',
    '客户至上' => 'Customer First',
];
