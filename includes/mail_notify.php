<?php
/**
 * Yikai CMS - 邮件通知
 *
 * 基于后台可编辑的邮件模板发送通知，支持 {{变量}} 替换
 */

declare(strict_types=1);

/**
 * 渲染邮件模板（替换变量）
 */
function renderMailTemplate(string $template, array $vars): string
{
    // 公共变量
    $vars['site_name'] = $vars['site_name'] ?? config('site_name', 'Yikai CMS');
    $vars['site_url']  = $vars['site_url'] ?? rtrim(config('site_url', ''), '/');
    $vars['date']      = $vars['date'] ?? date('Y-m-d H:i:s');

    foreach ($vars as $key => $value) {
        $template = str_replace('{{' . $key . '}}', (string)$value, $template);
    }
    return $template;
}

/**
 * 发送模板邮件
 *
 * @param string $to         收件人
 * @param string $tplPrefix  模板前缀，如 'inquiry' 对应 mail_tpl_inquiry_subject / mail_tpl_inquiry_body
 * @param array  $vars       模板变量
 */
function sendTemplateMail(string $to, string $tplPrefix, array $vars): bool
{
    $subjectTpl = config("mail_tpl_{$tplPrefix}_subject");
    $bodyTpl    = config("mail_tpl_{$tplPrefix}_body");

    if (empty($subjectTpl) || empty($bodyTpl)) return false;

    $subject = renderMailTemplate($subjectTpl, $vars);
    $body    = renderMailTemplate($bodyTpl, $vars);

    // 过滤器：允许插件修改邮件内容/收件人
    $mail = apply_filters('mail_notify', [
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
        'tpl'     => $tplPrefix,
        'vars'    => $vars,
    ]);

    if (empty($mail) || empty($mail['to'])) return false;

    try {
        $result = sendMail($mail['to'], $mail['subject'], $mail['body']);
        return $result === true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 新询盘邮件通知（发给管理员）
 */
function notifyNewInquiry(array $data): void
{
    if (config('mail_notify_form') !== '1') return;

    $adminEmail = config('mail_admin');
    if (empty($adminEmail)) return;

    sendTemplateMail($adminEmail, 'inquiry', [
        'product_title' => $data['product_title'] ?? '',
        'name'          => $data['name'] ?? '-',
        'phone'         => $data['phone'] ?? '-',
        'email'         => $data['email'] ?? '',
        'company'       => $data['company'] ?? '',
        'content'       => $data['content'] ?? '',
        'ip'            => $data['ip'] ?? '-',
    ]);
}

/**
 * 会员注册确认邮件（发给用户）
 */
function notifyUserRegister(string $email, string $username): void
{
    sendTemplateMail($email, 'register', [
        'username' => $username,
        'email'    => $email,
    ]);
}

/**
 * 找回密码邮件（发给用户）
 */
function notifyForgotPassword(string $email, string $username, string $resetLink): void
{
    sendTemplateMail($email, 'forgot', [
        'username'   => $username,
        'email'      => $email,
        'reset_link' => $resetLink,
    ]);
}

/**
 * 密码已重置通知（发给用户）
 */
function notifyPasswordReset(string $email, string $username): void
{
    sendTemplateMail($email, 'reset', [
        'username' => $username,
        'email'    => $email,
    ]);
}
