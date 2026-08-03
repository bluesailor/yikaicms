<?php
declare(strict_types=1);

if (!function_exists('lumisignHomeDefaults')) {
    function lumisignHomeDefaults(): array
    {
        return [
            'banner' => ['enabled' => true, 'shortcode' => 'home'],
            'services' => ['enabled' => true, 'title' => '', 'description' => '', 'items' => [
                ['title' => 'レーザー刻印', 'description' => '金属製品、銘板、タンブラー、キーホルダーなどへの文字・ロゴ・QRコードの刻印。', 'icon' => 'fa-solid fa-bolt'],
                ['title' => 'レーザー加工', 'description' => 'アクリル、木材、皮革などの彫刻・切断・造形。プレート、サイン、小物の製作に対応します。', 'icon' => 'fa-solid fa-shapes'],
                ['title' => '法人・小ロット加工', 'description' => '企業記念品、サイン、表彰楯、ロゴ入り製品、小ロット生産。数量に応じてお見積りします。', 'icon' => 'fa-solid fa-building'],
            ]],
            'entry' => ['enabled' => true, 'eyebrow' => 'START WITH YOUR NEED', 'title' => '相談の入口を選ぶ', 'description' => '素材や用途が決まっている方も、まだイメージ段階の方も、近い入口からご相談ください。', 'image' => '/images/custom-signage-combination.jpg', 'image_alt' => '素材と用途に合わせたサイン製作', 'caption' => '写真・素材・数量が分かれば、最初の方向性をご案内します。', 'note' => 'データが未完成でも相談可能です。', 'links' => [
                ['label' => '加工内容から探す', 'url' => '/service.html', 'icon' => 'fa-solid fa-crosshairs'], ['label' => '実際の加工事例を見る', 'url' => '/products.php', 'icon' => 'fa-solid fa-images'], ['label' => '料金と納期を確認する', 'url' => '/pricing.html', 'icon' => 'fa-solid fa-yen-sign'], ['label' => '加工機の導入を検討する', 'url' => '/machine.html', 'icon' => 'fa-solid fa-industry'],
            ]],
            'cases' => ['enabled' => true, 'title' => '加工事例', 'description' => '素材・加工方法・数量を添えてご紹介しています。持ち込み品の事例もあります。', 'limit' => 8],
            'materials' => ['enabled' => true, 'title' => '対応できる素材', 'items' => [
                ['name' => '金属', 'process' => '文字・ロゴ・QRコード・番号の刻印'], ['name' => 'アルミ', 'process' => '表面処理やカラーの確認後に加工'], ['name' => 'アクリル', 'process' => '彫刻・切断'], ['name' => '木材', 'process' => '彫刻・切断'], ['name' => '皮革', 'process' => '文字・ロゴ・柄の刻印'], ['name' => '塗装金属', 'process' => '表面を除去して下地を出す加工'],
            ]],
            'reasons' => ['enabled' => true, 'title' => '選ばれる理由', 'items' => ['1点からご相談いただけます', '小ロットのご注文に対応します', 'お客様の持ち込み品も加工できます', '事前のテスト加工が可能です', '金属・非金属のどちらにも対応します', '加工と機械導入を同時に相談できます', '実際に機械を操作するスタッフが対応します']],
            'pricing' => ['enabled' => true, 'title' => '参考価格', 'description' => '正式なお見積りは、写真・素材・数量を確認してご案内します。', 'items' => [
                ['name' => '1点のレーザー刻印', 'price' => '1,500円〜'], ['name' => 'アクリル卓上プレート', 'price' => '3,000円〜'], ['name' => '透明アクリル記念品', 'price' => 'サイズ・デザインによりお見積り'], ['name' => '法人・小ロットのご注文', 'price' => '数量によりお見積り'], ['name' => '持ち込み加工', 'price' => '素材・サイズに応じてご相談'],
            ], 'link_label' => '料金と納期の詳細を見る', 'link_url' => '/pricing.html'],
            'flow' => ['enabled' => true, 'title' => 'ご依頼の流れ', 'steps' => ['写真・素材・数量・希望内容を送る', '加工可否とデータ要否を確認', 'お見積りと納期目安をご提示', '内容確認後、加工開始', '検品・梱包して発送']],
            'machines' => ['enabled' => true, 'title' => 'レーザー加工機の販売・導入相談', 'description' => '取扱いは CO₂レーザー加工機とデュアル光源レーザーマーカーの2種類。実加工の経験をもとに、用途に合う機種をご案内します。', 'items' => [
                ['title' => 'CO₂レーザー加工機', 'description' => 'アクリル・木材・皮革など非金属素材の彫刻・切断に。看板やギフト製作の内製化に適しています。'], ['title' => 'デュアル光源レーザーマーカー', 'description' => '金属や一部工業製品へのマーキングに。銘板、番号、ロゴの刻印を高速・高精度に行えます。'],
            ], 'features' => ['実機での試加工が可能', '導入後の加工効果を確認できる', '操作トレーニングを提供', '導入前のご相談に対応', '保守・保証条件は個別にご案内'], 'list_label' => '2種類の加工機を見る', 'list_url' => '/machine.html', 'contact_label' => '加工機について相談する', 'contact_url' => '/public/machine.php'],
            'faq' => ['enabled' => true, 'title' => 'よくあるご質問', 'items' => [
                ['question' => '1点でも依頼できますか？', 'answer' => 'はい。1点から承ります。試作やプレート類もお気軽にご相談ください。'], ['question' => '持ち込み品にも刻印できますか？', 'answer' => 'はい。お客様の支給品や購入済み製品への刻印も承ります。素材によっては事前テストをお願いします。'], ['question' => 'どのようなデータが必要ですか？', 'answer' => 'AI・SVG・DXF・PDFのほか、JPGやPNGの参考画像でもご相談いただけます。'], ['question' => 'データがなくても相談できますか？', 'answer' => 'はい。現物や画像をもとに、加工に必要な形へ整理することも可能です。'], ['question' => '納期はどのくらいですか？', 'answer' => '内容確認とお見積りの後、通常3〜7営業日を目安にご案内します。'], ['question' => '加工前にテストできますか？', 'answer' => 'はい。素材やリスクに応じてテスト加工を行ってから本加工へ進めます。'],
            ], 'link_label' => 'その他のご質問はこちら', 'link_url' => '/faq.html'],
            'cta' => ['enabled' => true, 'title' => '加工について、まずは写真をお送りください', 'description' => '製品の写真・素材・数量が分かれば、加工可否とお見積りをご案内できます。', 'estimate_label' => '加工見積りを依頼する', 'estimate_url' => '/public/estimate.php', 'machine_label' => '加工機について相談する', 'machine_url' => '/public/machine.php'],
        ];
    }

    function lumisignHomeIsList(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
    function lumisignHomeCleanScalar(mixed $default, mixed $value, string $key = ''): mixed {
        if (is_bool($default)) return !empty($value);
        if (is_int($default)) return max(0, min(24, (int) $value));
        $text = trim(strip_tags((string) $value));
        $limit = str_contains($key, 'description') || $key === 'answer' || $key === 'process' ? 800 : 240;
        if ($key === 'shortcode') { $text = preg_replace('/[^a-zA-Z0-9_:\-.\[\]=" ]/', '', $text) ?? ''; $limit = 80; }
        if (str_ends_with($key, 'url') || $key === 'url' || $key === 'image') {
            if ($text !== '' && !str_starts_with($text, '/') && !str_starts_with($text, '#') && !preg_match('#^https?://#i', $text)) return (string) $default;
            $limit = 1000;
        }
        return mb_substr($text, 0, $limit);
    }
    function lumisignHomeCleanValue(mixed $default, mixed $value, string $key = ''): mixed {
        if (!is_array($default)) return lumisignHomeCleanScalar($default, $value, $key);
        $value = is_array($value) ? $value : [];
        if (lumisignHomeIsList($default)) { $out = []; foreach ($default as $index => $defaultItem) $out[] = lumisignHomeCleanValue($defaultItem, $value[$index] ?? [], $key); return $out; }
        $out = []; foreach ($default as $childKey => $defaultValue) $out[$childKey] = lumisignHomeCleanValue($defaultValue, $value[$childKey] ?? $defaultValue, (string) $childKey); return $out;
    }
    function lumisignHomeNormalize(array $value): array { return lumisignHomeCleanValue(lumisignHomeDefaults(), $value); }
    function lumisignHomeLoadForAdmin(): array {
        $raw = trim((string) config('home_lumisign_content', '')); $decoded = $raw !== '' ? json_decode($raw, true) : null; return lumisignHomeNormalize(is_array($decoded) ? $decoded : []);
    }
    function lumisignHomeSave(array $value): array {
        $normalized = lumisignHomeNormalize($value); settingModel()->set('home_lumisign_content', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'home'); do_action('data_changed', DB_PREFIX . 'settings', 0); return $normalized;
    }
}
if (!function_exists('getLumiSignHomeContent')) {
    function getLumiSignHomeContent(): array
    {
        static $content = null;
        if (is_array($content)) return $content;
        $raw = trim((string) config('home_lumisign_content', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $content = lumisignHomeNormalize(is_array($decoded) ? $decoded : []);
        return $content;
    }
}