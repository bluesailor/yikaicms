<?php
/**
 * 返回顶部插件
 *
 * 在前台页面右下角显示返回顶部按钮
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_footer_scripts', function () {
    ?>
    <div id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" style="position:fixed;bottom:30px;right:30px;z-index:9999;width:44px;height:44px;border-radius:50%;background:#3b82f6;color:#fff;display:none;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 12px rgba(59,130,246,0.4);transition:opacity 0.3s,transform 0.3s;opacity:0;transform:translateY(10px)" aria-label="返回顶部">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('backToTop');
        var shown = false;
        window.addEventListener('scroll', function(){
            if (window.scrollY > 400) {
                if (!shown) { btn.style.display='flex'; setTimeout(function(){ btn.style.opacity='1'; btn.style.transform='translateY(0)'; }, 10); shown=true; }
            } else {
                if (shown) { btn.style.opacity='0'; btn.style.transform='translateY(10px)'; setTimeout(function(){ btn.style.display='none'; }, 300); shown=false; }
            }
        });
        btn.addEventListener('mouseenter', function(){ this.style.background='#2563eb'; });
        btn.addEventListener('mouseleave', function(){ this.style.background='#3b82f6'; });
    })();
    </script>
    <?php
});
