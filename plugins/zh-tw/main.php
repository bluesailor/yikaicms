<?php
/**
 * 繁體中文語言包
 *
 * 只提供数据：s2t_maps.php 是 OpenCC 简→繁（台湾用词）映射，由核心的 S2T 按文件位置读取
 * （见 includes/i18n/S2T.php）。2.0.4 起安装包不再自带这份约 1MB 的表，改由本插件按需安装；
 * 更早安装的站点核心目录里仍有一份，升级不会删除。
 *
 * 表的唯一来源是 tools/build-s2t-map.sh 生成的 includes/i18n/s2t_maps.php，
 * 本目录的副本须与之逐字节一致（tests/Unit/S2TPackTest.php 校验）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
