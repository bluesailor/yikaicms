<?php
/**
 * 后台菜单排序插件
 *
 * 通过 JS 在页面加载后根据保存的排序配置重排侧栏 DOM 元素
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
    echo 'var order=JSON.parse(' . $orderJson . ');';
    echo 'if(!order||!order.groups)return;';
    // Collect all group header divs and their content divs
    echo 'var sidebar=document.querySelector("nav.flex-1");';
    echo 'if(!sidebar)return;';
    // Each group consists of: group-header div + content div (2 consecutive elements)
    // We identify groups by the @click="toggle('groupname')" pattern
    echo 'var groupMap={};';
    echo 'var groupDivs=sidebar.querySelectorAll(".sidebar-group");';
    echo 'groupDivs.forEach(function(hdr){';
    echo '  var onclick=hdr.getAttribute("@click")||"";';
    echo '  var m=onclick.match(/toggle\\([\'"]([^\'"]+)[\'"]/);';
    echo '  if(m){';
    echo '    var name=m[1];';
    echo '    var content=hdr.nextElementSibling;';
    echo '    groupMap[name]={header:hdr,content:content};';
    echo '  }';
    echo '});';
    // Reorder groups
    echo 'order.groups.forEach(function(g){';
    echo '  if(groupMap[g]){';
    echo '    sidebar.appendChild(groupMap[g].header);';
    echo '    sidebar.appendChild(groupMap[g].content);';
    echo '  }';
    echo '});';
    // Reorder items within each group
    echo 'if(order.items){';
    echo '  Object.keys(order.items).forEach(function(groupName){';
    echo '    if(!groupMap[groupName])return;';
    echo '    var container=groupMap[groupName].content;';
    echo '    var itemOrder=order.items[groupName];';
    echo '    var linkMap={};';
    echo '    container.querySelectorAll("a.sidebar-link").forEach(function(a){';
    echo '      var href=a.getAttribute("href")||"";';
    echo '      var match=href.match(/\\/admin\\/([^\\.]+)\\.php/);';
    echo '      if(match)linkMap[match[1]]=a;';
    echo '    });';
    echo '    itemOrder.forEach(function(key){';
    echo '      if(linkMap[key])container.appendChild(linkMap[key]);';
    echo '    });';
    echo '  });';
    echo '}';
    echo '}catch(e){}';
    echo '})();';
    echo '</script>';
});
