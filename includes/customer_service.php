<?php
/**
 * 在线客服浮动侧边栏（数据驱动版）
 *
 * 配置存于 settings：
 *   cs_enabled / cs_position / cs_show_mobile / cs_button_text / cs_panel_title
 *   cs_items   — JSON 数组，每项 { type, icon, label, value, enabled }
 *
 * type 枚举:
 *   qq          → 点击打开 wpa.qq.com 网页聊天，value 为 QQ 号
 *   wechat-id   → 显示微信号（点击复制），value 为微信号
 *   wechat-qr   → 悬浮二维码弹窗，value 为图片 URL
 *   work-wechat → 企业微信二维码弹窗（扫码联系客服），value 为图片 URL
 *   phone       → tel: 链接（移动端可拨号），value 为电话号
 *   mobile      → 同 phone
 *   email       → mailto: 链接，value 为邮箱
 *   link        → 自定义链接，value 为 URL
 *   text        → 纯文本，仅展示 value
 */

declare(strict_types=1);

/** 预设图标 SVG —— 24x24 viewBox，currentColor */
function csIconPresets(): array
{
    return [
        'qq'       => '<svg viewBox="0 0 1024 1024" fill="currentColor" class="w-5 h-5"><path d="M512.4096 77.7728c-232.0896 0-420.864 188.8256-420.864 420.864 0 232.0896 188.8256 420.864 420.864 420.864 232.0896 0 420.864-188.8256 420.864-420.864s-188.7744-420.864-420.864-420.864z m0 800.8192c-209.5104 0-379.904-170.4448-379.904-379.904 0-209.5104 170.4448-379.904 379.904-379.904 209.5104 0 379.904 170.4448 379.904 379.904 0 209.4592-170.3936 379.904-379.904 379.904z"/><path d="M683.9296 485.376c-1.8944-2.048-3.1232-5.4272-3.2768-8.2432-0.4096-8.448 0.2048-16.9472-0.256-25.3952-0.5632-10.1376-2.7136-20.6336-10.0352-27.5456-6.144-5.7856-6.8608-11.9296-7.168-19.0464-0.1024-2.56-0.3584-5.0688-0.6144-7.6288-3.9424-34.3552-13.0048-66.9184-36.4032-93.44-36.4544-41.3184-83.1488-57.1392-136.9088-49.2032-47.0528 6.912-84.1216 30.464-107.1616 73.4208-13.5168 25.1904-19.0464 52.5312-20.736 80.7424-0.3072 5.4272-0.8192 10.0352-5.888 13.6704-2.7136 1.9456-4.352 5.6832-5.8368 8.9088-6.5024 13.9264-6.656 28.7744-5.376 43.6736 0.3584 4.5056-0.8192 7.8336-4.0448 11.3152-24.6784 26.4704-42.1888 56.9344-48.384 92.9792-2.5088 14.5408-1.2288 29.2864 4.608 43.1104 3.3792 7.9872 9.216 9.9328 15.616 4.3008 7.2704-6.3488 13.4144-13.9776 19.7632-21.3504 2.9696-3.4816 5.1712-7.5776 7.3216-10.8032 11.5712 21.0944 23.0912 42.0352 34.2528 62.3104-7.2704 5.376-16.2304 10.5472-23.296 17.5616-15.36 15.1552-20.8384 45.6704 10.8544 60.1088 2.8672 1.3312 5.8368 2.56 8.9088 3.4304 29.2864 8.3968 58.5728 7.9872 87.9616 0.2048 15.5136-4.096 30.5152-9.0624 42.1888-20.8896 5.8368-5.9392 18.688-5.3248 25.1904-0.1024 6.5024 5.2736 13.4144 10.6496 21.0432 13.8752 22.3232 9.5232 45.824 13.9776 70.144 12.9536 16.7936-0.7168 33.4848-2.4064 48.896-10.0352 15.2064-7.5264 22.6816-19.2 21.5552-33.5872-1.024-13.3632-7.5264-23.808-17.9712-31.6416-5.888-4.4032-12.3904-7.936-17.6128-11.264 11.4688-20.8896 22.9888-41.8304 34.56-62.8736 2.1504 3.2256 4.4032 7.3216 7.3216 10.8544 5.7856 6.9632 11.4176 14.336 18.176 20.2752 7.9872 7.0144 14.1312 4.9664 17.9712-5.0688 4.9152-12.9536 6.2976-26.7776 4.0448-40.1408-6.3488-36.9664-24.3712-68.096-49.408-95.4368z"/></svg>',
        'wechat'   => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M8.7 11.3a.9.9 0 1 1 0-1.8.9.9 0 0 1 0 1.8zm5.6 0a.9.9 0 1 1 0-1.8.9.9 0 0 1 0 1.8zM9.5 4C5.36 4 2 6.95 2 10.6c0 2.08 1.1 3.92 2.83 5.1l-.65 1.93 2.5-1.27c.71.16 1.46.24 2.22.24l.3-.01a5.7 5.7 0 0 1-.2-1.49C9 11.78 12.3 9 16.5 9c.21 0 .42.01.62.03C16.5 6.2 13.27 4 9.5 4zm10.36 8.95a.7.7 0 1 1 0-1.4.7.7 0 0 1 0 1.4zm-5.04 0a.7.7 0 1 1 0-1.4.7.7 0 0 1 0 1.4zM22 15.1c0-2.93-2.95-5.3-6.5-5.3S9 12.18 9 15.1c0 2.93 2.95 5.3 6.5 5.3.65 0 1.27-.08 1.86-.23l2.1 1.07-.56-1.63A5.43 5.43 0 0 0 22 15.1z"/></svg>',
        // 企业微信：双气泡（WeCom 标志即两枚微信气泡叠加）
        'work-wechat' => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M8.9 3C5.3 3 2.4 5.5 2.4 8.6c0 1.77.96 3.34 2.46 4.37L4.2 15.3l2.5-1.3c.43.1.88.17 1.34.2a5.4 5.4 0 0 1-.18-1.37c0-3.03 2.86-5.4 6.3-5.4.2 0 .4.01.6.03C14.3 4.83 11.86 3 8.9 3z"/><path d="M21.6 13.7c0-2.6-2.55-4.7-5.7-4.7-3.15 0-5.7 2.1-5.7 4.7 0 2.6 2.55 4.7 5.7 4.7.6 0 1.18-.08 1.72-.22L19.4 19.4l-.5-1.7a4.66 4.66 0 0 0 2.7-4zm-7.62-.9a.78.78 0 1 1 0-1.56.78.78 0 0 1 0 1.56zm3.84 0a.78.78 0 1 1 0-1.56.78.78 0 0 1 0 1.56z"/></svg>',
        'phone'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>',
        'mobile'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
        'email'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
        'message'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>',
        'service'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72M18 18.72A9.024 9.024 0 0112 21a9.024 9.024 0 01-6-2.28m12 0v-1.5a2.5 2.5 0 00-2.5-2.5h-7A2.5 2.5 0 003 17.22V18.72M9 11a3 3 0 11-6 0 3 3 0 016 0zm12 0a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
        'whatsapp' => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.486"/></svg>',
        'line'     => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>',
        'telegram' => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
        'location' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
        'clock'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'fax'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>',

        // ---- 客服 / 头像 / 地址 / 聊天 ----
        'kefu'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12v-1a9 9 0 0118 0v1M3 12v5a2 2 0 002 2h2v-7H5a2 2 0 00-2 2zm18 0v5a2 2 0 01-2 2h-2v-7h2a2 2 0 012 2zM10 19h4a2 2 0 002-2v-1"/></svg>',
        'map-pin'  => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M11.54 22.351l.07.04.028.016a.76.76 0 00.723 0l.028-.015.071-.041a16.975 16.975 0 001.144-.742 19.58 19.58 0 002.683-2.282c1.944-1.99 3.963-4.98 3.963-8.827a8.25 8.25 0 00-16.5 0c0 3.846 2.02 6.837 3.963 8.827a19.58 19.58 0 002.682 2.282 16.975 16.975 0 001.145.742zM12 13.5a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>',
        'chat'     => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M4.804 21.644A6.707 6.707 0 006 21.75a6.721 6.721 0 003.583-1.029c.774.182 1.584.279 2.417.279 5.322 0 9.75-3.97 9.75-9 0-5.03-4.428-9-9.75-9s-9.75 3.97-9.75 9c0 2.409 1.025 4.587 2.674 6.192.232.226.277.428.254.543a3.73 3.73 0 01-.814 1.686.75.75 0 00.44 1.223zM8.25 10.875a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25zM10.875 12a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0zm4.875-1.125a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25z" clip-rule="evenodd"/></svg>',

        // ---- 中国社交 / 支付（Simple Icons 风格简化版） ----
        'weibo'    => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.737 5.443zm2.323-2.31c.823-1.769.006-3.673-2.098-4.169-1.957-.512-4.171.46-5.008 2.194-.853 1.769-.027 3.74 1.929 4.219 2.026.564 4.342-.435 5.177-2.244zm5.287-3.488c-.183-.055-.307-.092-.213-.33.208-.506.23-.943.001-1.254-.428-.585-1.591-.554-2.93-.005 0 0-.42.182-.314-.149.205-.667.176-1.225-.146-1.546-.74-.74-2.706.026-4.39 1.71C9.187 10.962 8.46 12.293 8.46 13.44c0 2.196 2.81 3.529 5.563 3.529 3.617 0 6.027-2.099 6.027-3.765 0-1.008-.847-1.575-1.342-1.81zm1.815-3.864c-.973-1.081-2.41-1.491-3.738-1.21a.926.926 0 00-.439.676c.064.31.366.508.673.443a2.654 2.654 0 012.487.806 2.66 2.66 0 01.549 2.566.567.567 0 00.363.717.57.57 0 00.719-.363c.45-1.387.143-2.97-.614-3.635zM22.05 8.667c-1.917-2.131-4.751-2.946-7.368-2.385a.952.952 0 00-.733 1.131c.109.512.611.838 1.123.726a5.71 5.71 0 015.504 1.782 5.722 5.722 0 011.171 5.55.94.94 0 00.606 1.193.95.95 0 001.195-.606c.971-2.965.485-5.561-1.498-7.391z"/></svg>',
        'xiaohongshu' => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M14.4 0H9.6C5.62 0 2.4 3.22 2.4 7.2v9.6c0 3.98 3.22 7.2 7.2 7.2h4.8c3.98 0 7.2-3.22 7.2-7.2V7.2C21.6 3.22 18.38 0 14.4 0zM9.2 17.6H7.4v-7.2c0-.42.34-.76.76-.76s.76.34.76.76v7.2H8.2zm4.4-4.04l1.42 4.04h-1.92l-.96-2.72-.96 2.72H10.06l1.42-4.04-1.42-4.04h1.92l.96 2.72.96-2.72h1.92l-1.42 4.04zm3.4 4.04h-1.8v-7.96h1.8c1.32 0 2.4 1.08 2.4 2.4v3.16c0 1.32-1.08 2.4-2.4 2.4zm.6-5.56c0-.34-.26-.6-.6-.6h-.4v4.36h.4c.34 0 .6-.26.6-.6v-3.16z"/></svg>',
        'douyin'   => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M19.321 5.562a5.122 5.122 0 0 1-.443-.258 6.228 6.228 0 0 1-1.137-.966c-.849-.971-1.166-1.956-1.282-2.645h.004C16.366.46 16.444 0 16.452 0h-3.36v12.989c0 .174 0 .346-.007.515l-.004.063-.001.027v.008a2.762 2.762 0 0 1-1.392 2.193 2.71 2.71 0 0 1-1.347.358c-1.521 0-2.753-1.24-2.753-2.771 0-1.531 1.232-2.772 2.753-2.772.288 0 .574.045.846.135l.004-3.42a6.158 6.158 0 0 0-4.731 1.379c-.583.508-1.07 1.114-1.443 1.793-.142.243-.679 1.227-.744 2.823-.04 1.054.32 2.21.477 2.689v.01c.107.298.523 1.328 1.196 2.184a6.295 6.295 0 0 0 1.957 1.69v-.008l.008.008c1.703.93 3.682.93 5.385 0 .888-.477 1.6-1.184 2.087-2.014.318-.527.519-1.118.626-1.748.038-.231.094-.467.094-.707V6.713c.054.034.797.524.797.524s1.073.679 2.755.985c.43.082.86.13 1.296.137V5.057c-.32-.012-.638-.054-.95-.124a4.97 4.97 0 0 1-.832-.298c-.066-.029-.13-.06-.196-.087Z"/></svg>',
        'taobao'   => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M2 7.5h4.5v9H2v-9zm14 0h6v9h-2v-7h-2v7h-2v-9zM8.5 7.5h6v2h-4v1.5h4v2h-4v1.5h4v2h-6v-9zM3.5 9v6h1.5V9H3.5z"/></svg>',
        'alipay'   => '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M18.689 14.474c-1.78-.6-4.05-1.42-6.78-2.45.62-1.13 1.14-2.45 1.52-3.93h-4.4v-1.4h5.3v-.79H8.99V3.8h2.7c-.18-.45-.43-1.05-.7-1.7l2.43-.5c.32.71.66 1.5.94 2.2h4.21v2.1h-5.34v.79h4.79v1.4h-4.86c-.16.51-.34.99-.51 1.43 1.42.51 2.51.94 3.39 1.34a39.4 39.4 0 0 1 4.16 2.21V5.8c0-2.1-1.7-3.8-3.8-3.8H5.8C3.7 2 2 3.7 2 5.8v12.4c0 2.1 1.7 3.8 3.8 3.8h12.4c1.83 0 3.36-1.29 3.71-3.02-1.56-.67-3.49-1.53-5.6-2.42-1.26 1.5-3.31 2.6-5.55 2.6-3.71 0-5.59-2.32-5.59-4.61 0-2.27 1.55-3.84 4.27-4.1.91-.09 1.91-.04 2.97.21.92.22 1.94.55 2.95.93.45-.79.83-1.7 1.13-2.65H6.32v-.79h4.84V5.8h-5.4V4.71h5.4v-.61h-3.7c-.13-.4-.35-.95-.6-1.55h-.45v17.06h11.18c.61-.45 1.01-1.18 1.01-1.99l-.42-.17v.02zM7.46 12.99c-1.85.32-2.82 1.32-2.82 2.62 0 1.49 1.24 2.61 3.34 2.61 2.07 0 4.04-1.31 4.81-3.4-2.07-.82-3.99-1.34-5.33-1.13v.3z"/></svg>',
    ];
}

function csRenderIcon(string $icon): string
{
    $presets = csIconPresets();
    if (isset($presets[$icon])) return $presets[$icon];

    // 自定义图片 URL（上传的或外链）
    if ($icon !== '' && (str_starts_with($icon, '/') || str_starts_with($icon, 'http'))) {
        return '<img src="' . e($icon) . '" alt="" class="w-5 h-5 object-contain">';
    }

    // 兜底：通用消息图标
    return $presets['message'];
}

function renderCustomerService(): void
{
    if ((string) config('cs_enabled', '0') !== '1') return;

    $rawItems = json_decode((string) config('cs_items', '[]'), true);
    if (!is_array($rawItems)) $rawItems = [];

    // 过滤启用项
    $items = array_values(array_filter($rawItems, fn($it) =>
        is_array($it) && !empty($it['enabled']) && !empty($it['type']) && (string)($it['value'] ?? '') !== ''
    ));

    if (empty($items)) return;

    $position    = (string) config('cs_position', 'right');
    $btnText     = (string) config('cs_button_text', '在线客服');
    $panelTitle  = (string) config('cs_panel_title', '欢迎咨询');
    $showMobile  = (string) config('cs_show_mobile', '1') === '1';
    $primary     = (string) config('primary_color', '#3B82F6');
    $sideStyle   = $position === 'left' ? 'left:0' : 'right:0';
    $hideMobile  = $showMobile ? '' : 'cs-hide-mobile';
    ?>
<style>
.cs-wrap{position:fixed;<?php echo $sideStyle; ?>;top:30vh;z-index:9990;display:flex;align-items:flex-start;font-family:inherit}
.cs-wrap.cs-pos-left{flex-direction:row-reverse}
.cs-tab{background:<?php echo e($primary); ?>;color:#fff;writing-mode:vertical-rl;padding:14px 8px;border-radius:<?php echo $position==='left'?'0 8px 8px 0':'8px 0 0 8px'; ?>;cursor:pointer;font-size:13px;letter-spacing:2px;box-shadow:0 4px 16px rgba(0,0,0,.15);user-select:none;transition:opacity .2s}
.cs-tab:hover{opacity:.92}
.cs-panel{background:#fff;width:260px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.18);display:none}
.cs-wrap.cs-open .cs-panel{display:block}
.cs-wrap.cs-open .cs-tab{<?php echo $position==='left'?'border-radius:0 0 8px 0':'border-radius:0 0 0 8px'; ?>}
.cs-head{background:<?php echo e($primary); ?>;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;font-size:13px;border-radius:8px 8px 0 0}
.cs-close{cursor:pointer;background:none;border:0;color:#fff;font-size:18px;line-height:1;padding:0 4px}
.cs-row{display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid #f3f4f6;color:#1f2937;text-decoration:none;transition:background .15s;position:relative}
.cs-row:last-child{border-bottom:0}
.cs-row:hover{background:#f9fafb;color:<?php echo e($primary); ?>}
.cs-row .cs-icon{flex-shrink:0;color:<?php echo e($primary); ?>;width:20px;height:20px;display:flex;align-items:center;justify-content:center}
.cs-row .cs-icon svg,.cs-row .cs-icon img{width:20px;height:20px}
.cs-row .cs-body{flex:1;min-width:0}
.cs-row .cs-label{font-size:11px;color:#6b7280;display:block;margin-bottom:1px;line-height:1.2}
.cs-row .cs-val{font-size:13px;font-weight:500;word-break:break-all;line-height:1.3}
.cs-row.cs-row-qr{cursor:pointer}
.cs-row.cs-row-qr .cs-qr-pop{position:absolute;<?php echo $position==='left'?'left:100%;margin-left:10px':'right:100%;margin-right:10px'; ?>;top:50%;transform:translateY(-50%);background:#fff;padding:8px;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.2);display:none;z-index:10;width:156px;box-sizing:content-box}
.cs-row.cs-row-qr .cs-qr-pop::after{content:'';position:absolute;<?php echo $position==='left'?'right:100%;border-right-color:#fff':'left:100%;border-left-color:#fff'; ?>;top:50%;transform:translateY(-50%);border:6px solid transparent}
.cs-row.cs-row-qr .cs-qr-pop img{display:block;width:140px!important;height:140px!important;max-width:none!important;object-fit:contain}
.cs-row.cs-row-qr .cs-qr-pop .cs-qr-tip{text-align:center;font-size:11px;color:#6b7280;margin-top:4px}
.cs-row.cs-row-qr:hover .cs-qr-pop,.cs-row.cs-row-qr.cs-qr-active .cs-qr-pop{display:block}
.cs-row-id .cs-copied{position:absolute;<?php echo $position==='left'?'left:100%;margin-left:10px':'right:100%;margin-right:10px'; ?>;top:50%;transform:translateY(-50%);background:#10b981;color:#fff;font-size:11px;padding:4px 10px;border-radius:4px;display:none;white-space:nowrap}
.cs-row-id.cs-just-copied .cs-copied{display:block;animation:csFade 1.5s}
@keyframes csFade{0%{opacity:0;transform:translateY(-50%) scale(.8)}30%,70%{opacity:1;transform:translateY(-50%) scale(1)}100%{opacity:0}}
@media (max-width:767px){
.cs-hide-mobile{display:none}
.cs-tab{padding:10px 6px;font-size:12px;letter-spacing:1px}
.cs-panel{width:220px}
}
</style>
<div class="cs-wrap cs-pos-<?php echo e($position); ?> <?php echo e($hideMobile); ?>" id="csWrap">
  <div class="cs-tab" onclick="document.getElementById('csWrap').classList.toggle('cs-open')">
    <?php echo e($btnText); ?>
  </div>
  <div class="cs-panel">
    <div class="cs-head">
      <span><?php echo e($panelTitle); ?></span>
      <button type="button" class="cs-close" onclick="document.getElementById('csWrap').classList.remove('cs-open')" aria-label="close">&times;</button>
    </div>
    <?php foreach ($items as $it):
        $type  = (string)$it['type'];
        $icon  = (string)($it['icon'] ?? '');
        $label = (string)($it['label'] ?? '');
        $value = (string)$it['value'];
        $iconHtml = csRenderIcon($icon);
    ?>
      <?php if ($type === 'wechat-qr' || $type === 'work-wechat'): $isWork = $type === 'work-wechat'; ?>
      <div class="cs-row cs-row-qr" onclick="this.classList.toggle('cs-qr-active')">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label ?: ($isWork ? '企业微信' : '微信')); ?></span><span class="cs-val"><?php echo $isWork ? '扫码联系客服' : '扫码加微信'; ?></span></div>
        <div class="cs-qr-pop">
          <img src="<?php echo e($value); ?>" alt="<?php echo e($label); ?>">
          <?php if ($label !== ''): ?><div class="cs-qr-tip"><?php echo e($label); ?></div><?php endif; ?>
        </div>
      </div>
      <?php elseif ($type === 'wechat-id'): ?>
      <div class="cs-row cs-row-id" onclick="csCopyValue(this, '<?php echo e($value); ?>')">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label ?: '微信号'); ?></span><span class="cs-val"><?php echo e($value); ?></span></div>
        <span class="cs-copied">已复制</span>
      </div>
      <?php elseif ($type === 'qq'): ?>
      <a href="http://wpa.qq.com/msgrd?v=3&uin=<?php echo e($value); ?>&site=qq&menu=yes" target="_blank" rel="noopener" class="cs-row">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label ?: 'QQ'); ?></span><span class="cs-val"><?php echo e($value); ?></span></div>
      </a>
      <?php elseif ($type === 'phone' || $type === 'mobile'): ?>
      <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $value)); ?>" class="cs-row">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label ?: ($type==='mobile'?'手机':'电话')); ?></span><span class="cs-val"><?php echo e($value); ?></span></div>
      </a>
      <?php elseif ($type === 'email'): ?>
      <a href="mailto:<?php echo e($value); ?>" class="cs-row">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label ?: '邮箱'); ?></span><span class="cs-val"><?php echo e($value); ?></span></div>
      </a>
      <?php elseif ($type === 'link'): ?>
      <a href="<?php echo e($value); ?>" target="_blank" rel="noopener" class="cs-row">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label); ?></span><span class="cs-val">前往 →</span></div>
      </a>
      <?php else: /* text */ ?>
      <div class="cs-row">
        <div class="cs-icon"><?php echo $iconHtml; ?></div>
        <div class="cs-body"><span class="cs-label"><?php echo e($label); ?></span><span class="cs-val"><?php echo e($value); ?></span></div>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<script>
function csCopyValue(el, val){
    try{
        navigator.clipboard.writeText(val).then(()=>{
            el.classList.add('cs-just-copied');
            setTimeout(()=>el.classList.remove('cs-just-copied'),1500);
        });
    }catch(e){
        var ta=document.createElement('textarea');ta.value=val;document.body.appendChild(ta);ta.select();
        try{document.execCommand('copy');el.classList.add('cs-just-copied');setTimeout(()=>el.classList.remove('cs-just-copied'),1500);}catch(_){}
        document.body.removeChild(ta);
    }
}
</script>
    <?php
}
