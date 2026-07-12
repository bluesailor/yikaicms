<?php
/**
 * Yikai CMS — job_detail.php controller（招聘详情，走独立 jobs 表）。
 *
 * 载入已发布职位、自增浏览量、解析所属招聘栏目（取第一个启用的 job 类型栏目）。
 * id<=0 或职位不存在/未发布时返回 null，由 job_detail.php 决定 404 / 跳转。
 */

declare(strict_types=1);

require_once __DIR__ . '/DetailController.php';

final class JobDetailController extends DetailController
{
    /**
     * @return array<string,mixed>|null
     */
    public function prepare(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $job = jobModel()->find($id);
        if (!$job || (int) $job['status'] !== 1) {
            return null;
        }

        // 副作用：每次渲染自增一次浏览量。
        jobModel()->incrementViews($id);

        // 招聘栏目：取第一个启用的 job 类型栏目（招聘通常只有一个）。
        $channel  = null;
        $channels = channelModel()->where(['type' => 'job', 'status' => 1]);
        if (!empty($channels)) {
            $channel = $channels[0];
        }

        return [
            'job'     => $job,
            'channel' => $channel,
        ];
    }
}
