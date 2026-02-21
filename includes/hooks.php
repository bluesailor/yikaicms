<?php
/**
 * Yikai CMS - 钩子系统
 *
 * 提供 WordPress 风格的 action/filter 机制
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

global $ik_actions, $ik_filters;
$ik_actions = [];
$ik_filters = [];

/**
 * 注册一个 action 回调
 */
function add_action(string $hook, callable $callback, int $priority = 10): void
{
    global $ik_actions;
    $ik_actions[$hook][$priority][] = $callback;
}

/**
 * 触发一个 action
 */
function do_action(string $hook, mixed ...$args): void
{
    global $ik_actions;
    if (empty($ik_actions[$hook])) {
        return;
    }
    ksort($ik_actions[$hook]);
    foreach ($ik_actions[$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback, $args);
        }
    }
}

/**
 * 注册一个 filter 回调
 */
function add_filter(string $hook, callable $callback, int $priority = 10): void
{
    global $ik_filters;
    $ik_filters[$hook][$priority][] = $callback;
}

/**
 * 应用 filter，返回过滤后的值
 */
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    global $ik_filters;
    if (empty($ik_filters[$hook])) {
        return $value;
    }
    ksort($ik_filters[$hook]);
    foreach ($ik_filters[$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = call_user_func_array($callback, [$value, ...$args]);
        }
    }
    return $value;
}

/**
 * 移除一个 action 回调
 */
function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    global $ik_actions;
    if (empty($ik_actions[$hook][$priority])) {
        return false;
    }
    foreach ($ik_actions[$hook][$priority] as $idx => $cb) {
        if ($cb === $callback) {
            unset($ik_actions[$hook][$priority][$idx]);
            return true;
        }
    }
    return false;
}

/**
 * 检查 action 是否有注册的回调
 */
function has_action(string $hook): bool
{
    global $ik_actions;
    return !empty($ik_actions[$hook]);
}

/**
 * 检查 filter 是否有注册的回调
 */
function has_filter(string $hook): bool
{
    global $ik_filters;
    return !empty($ik_filters[$hook]);
}
