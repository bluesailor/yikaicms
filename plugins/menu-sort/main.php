<?php
/**
 * 后台菜单排序 & 显示/隐藏插件
 *
 * 通过 JS 在页面加载后根据保存的配置重排并隐藏侧栏 DOM 元素
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_admin_footer_scripts', function () {
    $order = config('admin_menu_order', '');
    if (empty($order)) return;

    $orderJson = json_encode($order, JSON_HEX_TAG | JSON_HEX_AMP);
    echo '<script>';
    echo '(function(){';
    echo 'try{';
    echo 'var cfg=JSON.parse(' . $orderJson . ');';
    echo 'if(!cfg||!cfg.groups)return;';
    echo 'var sidebar=document.querySelector("nav[x-data]");';
    echo 'if(!sidebar)return;';
    // 收集所有分组：header(.sidebar-group) + content(下一个兄弟div)
    echo 'var groupMap={};';
    echo 'sidebar.querySelectorAll(".sidebar-group").forEach(function(hdr){';
    echo '  var attr=hdr.getAttribute("@click")||"";';
    echo '  var m=attr.match(/toggle\\([\'"]([^\'"]+)[\'"]/);';
    echo '  if(m){';
    echo '    groupMap[m[1]]={header:hdr,content:hdr.nextElementSibling};';
    echo '  }';
    echo '});';
    // 隐藏的分组和菜单项
    echo 'var hidden=cfg.hidden||[];';
    echo 'var hiddenItems=cfg.hiddenItems||[];';
    // 创建容器，把排序后的元素放进去（避免空白残留）
    echo 'var dashLink=sidebar.querySelector("a.sidebar-link");';
    echo 'var frag=document.createDocumentFragment();';
    echo 'cfg.groups.forEach(function(g){';
    echo '  if(!groupMap[g])return;';
    echo '  if(hidden.indexOf(g)>=0){';
    echo '    groupMap[g].header.style.display="none";';
    echo '    groupMap[g].content.style.display="none";';
    echo '  }';
    echo '  frag.appendChild(groupMap[g].header);';
    echo '  frag.appendChild(groupMap[g].content);';
    echo '});';
    // 清空侧边栏，保留控制台链接，然后插入排序后的内容
    echo 'sidebar.innerHTML="";';
    echo 'if(dashLink)sidebar.appendChild(dashLink);';
    echo 'sidebar.appendChild(frag);';
    // 重排分组内的菜单项 + 隐藏
    echo 'if(cfg.items){';
    echo '  Object.keys(cfg.items).forEach(function(gn){';
    echo '    if(!groupMap[gn])return;';
    echo '    var ct=groupMap[gn].content;';
    echo '    var linkMap={};';
    echo '    ct.querySelectorAll("a.sidebar-link").forEach(function(a){';
    echo '      var m=(a.getAttribute("href")||"").match(/\\/admin\\/([^\\.]+)\\.php/);';
    echo '      if(m)linkMap[m[1]]=a;';
    echo '    });';
    echo '    cfg.items[gn].forEach(function(key){';
    echo '      if(!linkMap[key])return;';
    echo '      if(hiddenItems.indexOf(key)>=0){';
    echo '        linkMap[key].style.display="none";';
    echo '      }else{';
    echo '        ct.appendChild(linkMap[key]);';
    echo '      }';
    echo '    });';
    echo '  });';
    echo '}';
    echo '}catch(e){console.error("menu-sort:",e)}';
    echo '})();';
    echo '</script>';
});
