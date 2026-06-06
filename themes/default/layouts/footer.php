    </main>

<?php
// 页脚设置
$footerColumns = json_decode(configJsonLang('footer_columns') ?: '[]', true) ?: [];
$footerBgColor = config('footer_bg_color', '#1f2937');
$footerBgImage = config('footer_bg_image', '');
$footerTextColor = config('footer_text_color', '#9ca3af');

// 计算总列数
$totalCols = 0;
foreach ($footerColumns as $col) {
    $totalCols += (int)($col['col_span'] ?? 1);
}
if ($totalCols < 1) $totalCols = 4;

// SNS图标渲染
function renderSocialIcons(): string {
    $socialLinks = json_decode(config('social_links', '[]'), true) ?: [];
    if (empty($socialLinks)) return '';

    // SVG图标（简洁通用路径）
    $icons = [
        'line'       => '<path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.271.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>',
        'x'          => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
        'instagram'  => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>',
        'facebook'   => '<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>',
        'youtube'    => '<path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
        'tiktok'     => '<path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>',
        'linkedin'   => '<path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>',
        'note'       => '<path d="M22.904 6.839c-.08-.63-.303-1.092-.661-1.46-.463-.47-1.093-.673-1.903-.691-.51-.024-1.725.09-3.047.676a15.23 15.23 0 00-2.14 1.176c-.148-.646-.349-1.158-.607-1.54-.453-.669-1.088-.939-1.758-.939-.403 0-.854.1-1.356.302-.758.302-1.462.813-2.091 1.383a13.2 13.2 0 00-1.088 1.1L8.25 3.5a.5.5 0 00-.5-.5H4.5a.5.5 0 00-.5.5v17a.5.5 0 00.5.5h3.25a.5.5 0 00.5-.5v-9.473c.42-.487 1.077-1.178 1.673-1.59.431-.298.76-.403.96-.403.134 0 .226.04.33.193.155.23.29.685.29 1.651V20.5a.5.5 0 00.5.5h3.25a.5.5 0 00.5-.5v-8.246c.476-.37 1.193-.853 1.832-1.113.353-.143.601-.183.727-.183.122 0 .205.039.307.192.168.253.307.723.307 1.723V20.5a.5.5 0 00.5.5h3.25a.5.5 0 00.5-.5V9.433c0-1.142-.098-1.976-.272-2.594z"/>',
        'threads'    => '<path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.773.776c-1.015-3.663-3.51-5.535-7.416-5.565h-.011c-2.694 0-4.792.876-6.24 2.604-1.362 1.627-2.073 3.96-2.098 6.622.025 2.664.735 4.996 2.098 6.622 1.447 1.728 3.545 2.604 6.24 2.604h.012c2.184-.017 3.9-.6 5.096-1.734.92-.872 1.478-2.053 1.478-3.265 0-1.27-.556-2.2-1.428-2.665-.498-.261-1.112-.4-1.812-.4-.452 0-.912.063-1.36.187.248.577.392 1.227.392 1.937 0 .843-.2 1.636-.565 2.324-.774 1.457-2.275 2.293-4.12 2.293-.86 0-1.682-.215-2.38-.623-1.35-.795-2.155-2.197-2.155-3.749 0-1.826.968-3.453 2.556-4.3.988-.526 2.146-.788 3.422-.789h.06c.79.002 1.532.112 2.228.34l-.004-2.31c-.83-.135-1.648-.175-2.471-.122-2.15.136-4.047.806-5.493 1.938C5.504 11.5 4.5 13.497 4.5 15.875c0 2.547 1.217 4.782 3.248 5.977 1.059.621 2.264.96 3.558 1.032.33.018.678.027 1.043.027 2.473 0 4.534-.638 6.07-1.87 1.69-1.352 2.581-3.371 2.581-5.841 0-1.82-.55-3.346-1.627-4.517l1.93-2.067c1.508 1.648 2.337 3.747 2.337 6.124"/>',
        'pinterest'  => '<path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z"/>',
        'wechat'     => '<path d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 01.213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 00.167-.054l1.903-1.114a.864.864 0 01.717-.098 10.16 10.16 0 002.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 01-1.162 1.178A1.17 1.17 0 014.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 01-1.162 1.178 1.17 1.17 0 01-1.162-1.178c0-.651.52-1.18 1.162-1.18zm3.427 4.002c-1.786-.04-3.68.545-5.058 1.69-1.404 1.166-2.198 2.99-1.783 5.084.36 1.82 1.574 3.198 3.157 4.084 1.502.84 3.315 1.178 5.107.794a.864.864 0 01.717.098l1.201.703a.326.326 0 00.167.054c.16 0 .29-.132.29-.295 0-.072-.03-.143-.048-.213l-.24-.908a.59.59 0 01.213-.665c1.377-1.013 2.209-2.505 2.323-4.093.14-1.95-.86-3.878-2.58-5.065-1.222-.865-2.68-1.246-4.113-1.268h-.353z"/>',
        'weibo'      => '<path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM20.196 9.4a4.83 4.83 0 00-5.07-1.3.73.73 0 01-.92-.462.74.74 0 01.459-.928c2.57-.829 5.42-.15 7.205 1.848.165.185.27.404.29.644.02.301-.13.545-.33.742-.34.33-.85.33-1.16.05a3.28 3.28 0 00-.474-.594zm-1.783 1.79c-.5-.551-1.18-.817-1.85-.76a.515.515 0 01-.547-.473c-.03-.27.17-.52.44-.55 1.02-.09 2.02.319 2.73 1.1.16.175.28.395.28.627 0 .208-.14.39-.32.49-.3.165-.66.09-.86-.14a1.49 1.49 0 00-.073-.094v-.2z"/>',
        'douyin'     => '<path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>',
        'kuaishou'   => '<circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="white" font-size="10" font-weight="bold">快</text>',
        'xiaohongshu'=> '<circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="white" font-size="10" font-weight="bold">红</text>',
        'bilibili'   => '<path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 01-.373-.906c0-.356.124-.658.373-.907l.027-.027c.253-.253.55-.373.907-.373.356 0 .657.12.907.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 01.16-.213l2.853-2.747c.253-.253.554-.373.907-.373.356 0 .658.12.907.373.018.018.027.027.027.027.253.249.373.551.373.907 0 .355-.12.653-.373.906L17.813 4.653zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773H5.333zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z"/>',
        'whatsapp'   => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>',
        'discord'    => '<path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>',
        'wechat_global' => '<path d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 01.213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 00.167-.054l1.903-1.114a.864.864 0 01.717-.098 10.16 10.16 0 002.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348z"/>',
        'zhihu'      => '<path d="M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24h12.56C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm1.964 4.078h6.191c.135 0 .24.105.24.24v1.185c0 .135-.105.24-.24.24H9.18l-.36 2.61h4.118s.131 0 .218.09c.087.09.102.21.102.21l-.66 5.463s-.03.18-.12.27c-.09.09-.21.12-.21.12H10.2s-.18-.03-.27-.12c-.09-.09-.06-.27-.06-.27l.54-4.5h-1.62l-.9 6.75H6.27l1.2-9.001H5.685a.24.24 0 01-.24-.24v-1.185c0-.135.105-.24.24-.24h1.32l.38-1.622zm7.785.12h1.44c.135 0 .24.105.24.24v3.432h1.8c.135 0 .24.105.24.24v1.185c0 .135-.105.24-.24.24h-1.8v5.385c0 .54-.18.96-.54 1.26-.36.3-.9.42-1.56.42h-.96a.24.24 0 01-.24-.24v-1.185c0-.135.105-.24.24-.24h.72c.18 0 .3-.03.39-.09.09-.06.15-.18.15-.36V9.535h-1.86a.24.24 0 01-.24-.24V8.11c0-.135.105-.24.24-.24h1.86V4.438c0-.135.105-.24.24-.24z"/>',
    ];

    $colors = [
        'line'=>'#06C755','x'=>'#000','instagram'=>'#E4405F','facebook'=>'#1877F2',
        'youtube'=>'#FF0000','tiktok'=>'#000','linkedin'=>'#0A66C2','note'=>'#41C9B4',
        'threads'=>'#000','pinterest'=>'#BD081C','wechat'=>'#07C160','weibo'=>'#E6162D',
        'douyin'=>'#000','kuaishou'=>'#FF4906','xiaohongshu'=>'#FE2C55','bilibili'=>'#00A1D6','zhihu'=>'#0066FF',
        'whatsapp'=>'#25D366','discord'=>'#5865F2','wechat_global'=>'#07C160',
    ];

    $html = '<div class="flex flex-wrap gap-3 mt-2">';
    foreach ($socialLinks as $sl) {
        $p = $sl['platform'] ?? '';
        $url = $sl['url'] ?? '';
        if (!$p || !$url || !isset($icons[$p])) continue;
        $color = $colors[$p] ?? '#666';
        $html .= '<a href="' . e($url) . '" target="_blank" rel="nofollow" class="w-8 h-8 rounded-full flex items-center justify-center transition hover:opacity-80" style="background:' . $color . '" title="' . e($p) . '">';
        $html .= '<svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24">' . $icons[$p] . '</svg>';
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

// 占位符替换函数
function renderFooterContent(string $content): string {
    // {{site_description}}
    if (str_contains($content, '{{site_description}}')) {
        $desc = e(configJsonLang('site_description') ?: config('site_description', ''));
        $content = str_replace('{{site_description}}', $desc, $content);
    }

    // {{contact_info}}（lang-aware：phone/email/address 优先读 *_en / *_ja，没有回退到默认）
    if (str_contains($content, '{{contact_info}}')) {
        $html = '<ul class="space-y-2 text-sm">';
        if ($phone = configRawLang('contact_phone')) {
            $html .= '<li class="flex items-center gap-2"><svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>' . e($phone) . '</li>';
        }
        if ($email = configRawLang('contact_email')) {
            $html .= '<li class="flex items-center gap-2"><svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>' . e($email) . '</li>';
        }
        if ($address = configRawLang('contact_address')) {
            $html .= '<li class="flex items-start gap-2"><svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>' . e($address) . '</li>';
        }
        $html .= '</ul>';
        $content = str_replace('{{contact_info}}', $html, $content);
    }

    // {{qrcode}}
    if (str_contains($content, '{{qrcode}}')) {
        $qrcode = config('contact_qrcode');
        $html = $qrcode ? '<img src="' . e($qrcode) . '" alt="QR Code" class="w-24 h-24">' : '';
        $content = str_replace('{{qrcode}}', $html, $content);
    }

    // {{social_icons}}
    if (str_contains($content, '{{social_icons}}')) {
        $content = str_replace('{{social_icons}}', renderSocialIcons(), $content);
    }

    // 如果内容不含HTML标签，则做 nl2br 处理
    if ($content === strip_tags($content)) {
        $content = nl2br(e($content));
    }

    return $content;
}

$footerBgStyle = 'background-color: ' . e($footerBgColor) . ';';
if ($footerBgImage) {
    $footerBgStyle .= ' background-image: url(' . e($footerBgImage) . '); background-size: cover; background-position: center;';
}
?>

    <?php do_action('ik_footer_before'); ?>

    <!-- footer -->
    <footer class="mt-auto" style="<?php echo $footerBgStyle; ?> color: <?php echo e($footerTextColor); ?>">
        <div class="container mx-auto px-4 py-12">
            <?php if (!empty($footerColumns)): ?>
            <div class="grid grid-cols-1 md:grid-cols-<?php echo $totalCols; ?> gap-8">
                <?php foreach ($footerColumns as $col): ?>
                <?php $span = (int)($col['col_span'] ?? 1); ?>
                <div class="<?php echo $span > 1 ? 'md:col-span-' . $span : ''; ?>">
                    <?php if (!empty($col['title'])): ?>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo e($col['title']); ?></h3>
                    <?php endif; ?>
                    <div class="text-sm leading-relaxed">
                        <?php echo renderFooterContent($col['content'] ?? ''); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- Default layout when there are no custom columns -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="md:col-span-2">
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo e(configRawLang('site_name', 'Yikai CMS')); ?></h3>
                    <p class="text-sm leading-relaxed"><?php echo e(configJsonLang('site_description') ?: config('site_description', '')); ?></p>
                </div>
                <div>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo __('footer_contact'); ?></h3>
                    <?php echo renderFooterContent('{{contact_info}}'); ?>
                </div>
                <div>
                    <?php if (config('contact_qrcode')): ?>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo __('footer_follow'); ?></h3>
                    <?php echo renderFooterContent('{{qrcode}}'); ?>
                    <?php endif; ?>
                    <?php
                    $socialIconsHtml = renderSocialIcons();
                    if ($socialIconsHtml): ?>
                    <?php if (!config('contact_qrcode')): ?>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo __('footer_follow'); ?></h3>
                    <?php endif; ?>
                    <?php echo $socialIconsHtml; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Partner（仅首页显示——配置键 home_show_links 已暗示这一意图，
                        以前忘了限定 $isHomePage 导致内页 footer 也露出） -->
            <?php
            $showLinks = (isset($isHomePage) && $isHomePage)
                && config('home_show_links', '1') === '1';
            $links = $showLinks ? linkModel()->getActive() : [];
            if (!empty($links)):
            ?>
            <div class="border-t border-gray-700 mt-8 pt-8">
                <h4 class="text-white font-medium mb-4"><?php echo configLang('home_links_title', 'footer_partners'); ?></h4>
                <div class="flex flex-wrap gap-4">
                    <?php foreach ($links as $link): ?>
                    <a href="<?php echo e($link['url']); ?>" target="_blank" rel="nofollow"
                       class="text-sm hover:text-white transition">
                        <?php echo e($link['name']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- footer navigation -->
        <?php
        $footerNav = json_decode(configJsonLang('footer_nav') ?: '[]', true) ?: [];
        if (!empty($footerNav)):
        ?>
        <div class="border-t border-gray-700">
            <div class="container mx-auto px-4 py-4">
                <div class="flex flex-wrap gap-x-8 gap-y-3 justify-center text-sm">
                    <?php foreach ($footerNav as $group): ?>
                        <?php $groupLinks = $group['links'] ?? []; ?>
                        <?php if (!empty($group['title'])): ?>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span class="text-white font-medium"><?php echo e($group['title']); ?></span>
                            <?php foreach ($groupLinks as $li => $link): ?>
                            <?php if ($li > 0): ?><span class="opacity-40 mx-2">|</span><?php endif; ?>
                            <a href="<?php echo e($link['url']); ?>"
                               <?php echo ($link['target'] ?? '_self') === '_blank' ? 'target="_blank" rel="nofollow"' : ''; ?>
                               class="hover:text-white transition">
                                <?php echo e($link['name']); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <?php foreach ($groupLinks as $li => $link): ?>
                        <?php if ($li > 0): ?><span class="opacity-40 mx-2">|</span><?php endif; ?>
                        <a href="<?php echo e($link['url']); ?>"
                           <?php echo ($link['target'] ?? '_self') === '_blank' ? 'target="_blank" rel="nofollow"' : ''; ?>
                           class="hover:text-white transition">
                            <?php echo e($link['name']); ?>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Copyright Information -->
        <div class="border-t border-gray-700">
            <div class="container mx-auto px-4 py-4 flex flex-wrap gap-4 items-center justify-between text-sm">
                <div>
                    <?php
                    // lang-aware：当前是 en 时读 footer_copyright_text_en，没有则回退
                    $copyrightTpl = configRawLang('footer_copyright_text', '');
                    if ($copyrightTpl) {
                        echo e(str_replace(['{year}', '{site_name}'], [date('Y'), configRawLang('site_name', 'Yikai CMS')], $copyrightTpl));
                    } else {
                        echo '&copy; ' . date('Y') . ' ' . e(configRawLang('site_name', 'Yikai CMS')) . ' ' . __('footer_copyright') . '.';
                    }
                    ?>
                </div>
                <div class="flex flex-wrap gap-4">
                    <?php if (siteLang() === 'zh-CN'): ?>
                        <?php if ($icp = config('site_icp')): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow" class="hover:text-white transition">
                            <?php echo e($icp); ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($police = config('site_police')): ?>
                        <a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow" class="hover:text-white transition flex items-center gap-1">
                            <img src="/images/gaba.png" alt="" class="w-4 h-4">
                            <?php echo e($police); ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // 移动端菜单切换
        document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
            const menu = document.getElementById('mobileMenu');
            const hamburger = document.getElementById('hamburgerIcon');
            menu?.classList.toggle('hidden');
            hamburger?.classList.toggle('active');
        });
    </script>

    <!-- General Lightbox -->
    <div id="ik-lightbox" class="fixed inset-0 z-[200] bg-black/80 hidden items-center justify-center cursor-zoom-out" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');document.body.style.overflow=''}">
        <button onclick="this.parentElement.classList.add('hidden');this.parentElement.classList.remove('flex');document.body.style.overflow=''" class="absolute top-4 right-4 text-white/80 hover:text-white text-4xl leading-none cursor-pointer">&times;</button>
        <img id="ik-lightbox-img" src="" class="max-w-[90vw] max-h-[90vh] rounded-lg shadow-2xl" onclick="event.stopPropagation()">
    </div>
    <script>
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[data-lightbox]');
        if (!link) return;
        if (link.dataset.lightbox === 'album') return;
        e.preventDefault();
        var box = document.getElementById('ik-lightbox');
        document.getElementById('ik-lightbox-img').src = link.href;
        box.classList.remove('hidden');
        box.classList.add('flex');
        document.body.style.overflow = 'hidden';
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var box = document.getElementById('ik-lightbox');
            if (!box.classList.contains('hidden')) {
                box.classList.add('hidden');
                box.classList.remove('flex');
                document.body.style.overflow = '';
            }
        }
    });
    </script>

    <!-- scroll-in animation -->
    <script>
    (function(){
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animated');
                    observer.unobserve(entry.target);

                    entry.target.querySelectorAll('.stat-number[data-count]').forEach(function(el) {
                        animateNumber(el);
                    });
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('[data-animate], [data-stagger]').forEach(function(el) {
            observer.observe(el);
        });

        function animateNumber(el) {
            var text = el.dataset.count;
            var num = parseInt(text.replace(/[^0-9]/g, ''));
            if (!num || num < 2) return;
            var suffix = text.replace(/[0-9]/g, '');
            var duration = Math.min(1500, Math.max(800, num * 2));
            var start = performance.now();

            function tick(now) {
                var progress = Math.min((now - start) / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.floor(num * eased) + suffix;
                if (progress < 1) requestAnimationFrame(tick);
                else el.textContent = text;
            }
            requestAnimationFrame(tick);
        }
    })();
    </script>

    <?php if (!empty($extraJs)): ?>
    <?php echo $extraJs; ?>
    <?php endif; ?>
    <?php do_action('ik_footer_scripts'); ?>
    <?php do_action('render_footer'); ?>
    <?php renderCustomerService(); ?>
    <?php echo config('custom_body_code', ''); ?>
</body>
</html>
