<?php
/**
 * 网站公告插件 —— 前端弹窗
 *
 * 在 ik_footer_scripts 钩子注入公告弹窗 + JS。配置存于 settings（group=plugin）：
 *   ann_enabled   是否启用（1/0）
 *   ann_title     弹窗标题
 *   ann_content   弹窗正文（HTML）
 *   ann_cooldown  弹出冷却天数（同一访客 N 天内只弹一次）
 *   ann_home_only 是否仅首页（1/0）
 *
 * cookie `ik_ann_seen` 存内容指纹；正文改动后指纹变化 → 对所有访客再弹一次。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_footer_scripts', function () {
    if ((string) config('ann_enabled', '0') !== '1') return;

    $content = (string) config('ann_content', '');
    if (trim($content) === '') return;

    // 仅首页开关
    if ((string) config('ann_home_only', '0') === '1' && empty($GLOBALS['isHomePage'])) return;

    $title    = (string) config('ann_title', __('ann_default_title'));
    $button   = (string) config('ann_button', __('ann_default_btn'));
    $cooldown = max(0, (int) config('ann_cooldown', '1'));
    $primary  = (string) config('primary_color', '#005090');
    $token    = substr(md5($content . '|' . $title . '|' . $button), 0, 10); // 指纹：内容/标题/按钮改动即重弹
    ?>
    <div id="ik-ann-overlay" style="display:none;position:fixed;inset:0;z-index:999999;justify-content:center;align-items:center;background:rgba(0,0,0,0.5);">
      <div id="ik-ann-box" style="background:#fff;max-width:600px;width:90%;border-radius:16px;padding:32px;position:relative;box-shadow:0 25px 60px rgba(0,0,0,0.3);max-height:80vh;overflow-y:auto;animation:ikAnnIn .3s ease;">
        <button id="ik-ann-x" aria-label="<?php echo e(__('ann_close')); ?>" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
        <h2 style="margin:0 0 16px;font-size:22px;color:#1e293b;"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <div style="color:#374151;line-height:1.8;font-size:15px;"><?php echo $content; /* 管理员提供的 HTML，原样输出 */ ?></div>
        <div style="text-align:center;margin-top:24px;">
          <button id="ik-ann-ok" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" style="padding:10px 40px;background:<?php echo htmlspecialchars($primary, ENT_QUOTES); ?>;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:transform .2s;"><?php echo htmlspecialchars($button, ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
      </div>
    </div>
    <style>@keyframes ikAnnIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}#ik-ann-box p{margin:0 0 12px}#ik-ann-box img{max-width:100%;height:auto}</style>
    <script>
    (function(){
      var COOKIE='ik_ann_seen',DAYS=<?php echo $cooldown; ?>,TOKEN='<?php echo $token; ?>';
      function getCookie(n){var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?m[1]:'';}
      function setCookie(n,v,d){var s='';if(d>0){var e=new Date();e.setTime(e.getTime()+d*86400000);s=';expires='+e.toUTCString();}document.cookie=n+'='+v+s+';path=/;SameSite=Lax';}
      if(getCookie(COOKIE)===TOKEN)return; // 已看过当前版本
      var ov=document.getElementById('ik-ann-overlay');
      if(!ov)return;
      ov.style.display='flex';
      function close(){ov.style.display='none';setCookie(COOKIE,TOKEN,DAYS);}
      document.getElementById('ik-ann-x').onclick=close;
      document.getElementById('ik-ann-ok').onclick=close;
      ov.onclick=function(e){if(e.target===ov)close();};
    })();
    </script>
    <?php
});
