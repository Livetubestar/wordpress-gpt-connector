<?php
/**
 * Plugin Name: Shadowverse Live Streams
 * Description: Displays a curated list of YouTube live streams related to Shadowverse on your WordPress site.
 * Version: 2.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ShadowverseLiveStreams
{
    private const MANUAL_API_KEY = '';
    private const CACHE_KEY = 'shadowverse_youtube_live_streams_v2';
    private const STALE_CACHE_KEY = 'shadowverse_youtube_live_streams_stale_v2';
    private const CACHE_DURATION = HOUR_IN_SECONDS;
    private const STALE_CACHE_DURATION = 6 * HOUR_IN_SECONDS;
    private const CRON_HOOK = 'shadowverse_live_streams_refresh_cache';
    private const STYLE_HANDLE = 'shadowverse-live-streams';
    private const MAX_RESULTS_PER_QUERY = 16;

    public function __construct()
    {
        add_shortcode('shadowverse_live_streams', [$this, 'render_live_streams']);
        add_action(self::CRON_HOOK, [$this, 'refresh_cache']);
        add_action('init', [$this, 'ensure_schedule']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_notices', [$this, 'render_admin_notice']);
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        delete_transient(self::CACHE_KEY);
        delete_transient(self::STALE_CACHE_KEY);
    }

    public function register_assets(): void
    {
        wp_register_style(self::STYLE_HANDLE, false, [], '2.1.0');
        wp_add_inline_style(self::STYLE_HANDLE, $this->get_styles());
        wp_enqueue_style(self::STYLE_HANDLE);
    }

    public function ensure_schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public function render_admin_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ($this->get_api_key() !== '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'Shadowverse Live Streams: YouTube API key is not configured. Define SHADOWVERSE_LIVE_STREAMS_API_KEY or use the shadowverse_live_streams_api_key filter.',
            'shadowverse-live-streams'
        );
        echo '</p></div>';
    }

    public function refresh_cache(): void
    {
        $this->fetch_live_streams(true);
    }

    public function render_live_streams(array $atts = []): string
    {
        $atts = shortcode_atts([
            'limit' => 12,
            'columns' => 2,
            'show_description' => 'no',
        ], $atts, 'shadowverse_live_streams');

        $columns = max(1, min(2, (int) $atts['columns']));
        $limit = max(1, min(24, (int) $atts['limit']));
        $show_description = strtolower((string) $atts['show_description']) !== 'no';
        $streams = array_slice($this->fetch_live_streams(), 0, $limit);

        if (empty($streams)) {
            return $this->render_empty_state();
        }

        $wrapper_class = sprintf(
            'shadowverse-live-streams shadowverse-live-streams--columns-%d',
            $columns
        );

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
            <?php foreach ($streams as $stream) : ?>
                <?php
                $viewer_count = isset($stream['viewer_count']) ? (int) $stream['viewer_count'] : null;
                $is_hidden_viewers = empty($stream['has_viewer_count']);
                $is_support_pick = !$is_hidden_viewers && $viewer_count > 0 && $viewer_count <= 120;
                $detail_text = $this->format_started_at((string) ($stream['started_at'] ?? ''));
                $description = $show_description ? $this->trim_description((string) ($stream['description'] ?? '')) : '';
                $preview_url = $this->get_preview_url((string) $stream['video_id']);
                ?>
                <article class="shadowverse-live-streams__card">
                    <div class="shadowverse-live-streams__thumb-wrap" data-preview-src="<?php echo esc_url($preview_url); ?>">
                        <img
                            class="shadowverse-live-streams__thumb"
                            src="<?php echo esc_url($stream['thumbnail_url']); ?>"
                            alt="<?php echo esc_attr($stream['title']); ?>"
                            loading="lazy"
                        >
                        <iframe
                            class="shadowverse-live-streams__preview"
                            title="<?php echo esc_attr($stream['title']); ?>"
                            loading="lazy"
                            allow="autoplay; encrypted-media; picture-in-picture"
                            allowfullscreen
                            tabindex="-1"
                        ></iframe>
                        <a
                            class="shadowverse-live-streams__thumb-link"
                            href="<?php echo esc_url($stream['watch_url']); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="<?php echo esc_attr($stream['title']); ?>"
                        ></a>
                        <span class="shadowverse-live-streams__live-badge">LIVE</span>
                        <span class="shadowverse-live-streams__viewer-chip">
                            <?php echo esc_html($is_hidden_viewers ? '視聴中 非公開' : sprintf('%s人視聴中', number_format_i18n($viewer_count))); ?>
                        </span>
                    </div>

                    <div class="shadowverse-live-streams__body">
                        <div class="shadowverse-live-streams__meta">
                            <?php if ($is_support_pick) : ?>
                                <span class="shadowverse-live-streams__tag shadowverse-live-streams__tag--support">見つけてほしい配信</span>
                            <?php endif; ?>

                            <?php if (!empty($stream['matched_label'])) : ?>
                                <span class="shadowverse-live-streams__tag"><?php echo esc_html($stream['matched_label']); ?></span>
                            <?php endif; ?>
                        </div>

                        <p class="shadowverse-live-streams__title"><?php echo esc_html($stream['title']); ?></p>

                        <p class="shadowverse-live-streams__channel">
                            <a href="<?php echo esc_url($stream['channel_url']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html($stream['channel_title']); ?>
                            </a>
                        </p>

                        <?php if ($detail_text !== '') : ?>
                            <p class="shadowverse-live-streams__detail"><?php echo esc_html($detail_text); ?></p>
                        <?php endif; ?>

                        <?php if ($description !== '') : ?>
                            <p class="shadowverse-live-streams__description"><?php echo esc_html($description); ?></p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        echo $this->get_preview_script();

        return (string) ob_get_clean();
    }

    private function get_preview_url(string $video_id): string
    {
        return sprintf(
            'https://www.youtube-nocookie.com/embed/%s?autoplay=1&mute=1&controls=0&rel=0&modestbranding=1&playsinline=1',
            rawurlencode($video_id)
        );
    }

    private function get_preview_script(): string
    {
        return <<<'HTML'
<script>
(() => {
    if (window.shadowverseLiveStreamsPreviewInit) {
        return;
    }

    window.shadowverseLiveStreamsPreviewInit = true;

    const canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
    if (!canHover) {
        return;
    }

    const activeClass = 'is-preview-active';

    const activate = (wrap) => {
        if (!wrap) {
            return;
        }

        const frame = wrap.querySelector('.shadowverse-live-streams__preview');
        const previewSrc = wrap.dataset.previewSrc || '';
        if (!frame || !previewSrc) {
            return;
        }

        if (wrap._previewLeaveTimer) {
            clearTimeout(wrap._previewLeaveTimer);
        }

        if (frame.dataset.loaded !== 'true') {
            frame.src = previewSrc;
            frame.dataset.loaded = 'true';
        }

        if (wrap._previewEnterTimer) {
            clearTimeout(wrap._previewEnterTimer);
        }

        wrap._previewEnterTimer = window.setTimeout(() => {
            wrap.classList.add(activeClass);
        }, 90);
    };

    const deactivate = (wrap) => {
        if (!wrap) {
            return;
        }

        if (wrap._previewEnterTimer) {
            clearTimeout(wrap._previewEnterTimer);
        }

        if (wrap._previewLeaveTimer) {
            clearTimeout(wrap._previewLeaveTimer);
        }

        wrap._previewLeaveTimer = window.setTimeout(() => {
            wrap.classList.remove(activeClass);

            const frame = wrap.querySelector('.shadowverse-live-streams__preview');
            if (!frame) {
                return;
            }

            frame.src = '';
            frame.dataset.loaded = 'false';
        }, 140);
    };

    document.addEventListener('pointerenter', (event) => {
        const wrap = event.target.closest('.shadowverse-live-streams__thumb-wrap');
        activate(wrap);
    }, true);

    document.addEventListener('pointerleave', (event) => {
        const wrap = event.target.closest('.shadowverse-live-streams__thumb-wrap');
        deactivate(wrap);
    }, true);

    document.addEventListener('focusin', (event) => {
        const wrap = event.target.closest('.shadowverse-live-streams__thumb-wrap');
        activate(wrap);
    });

    document.addEventListener('focusout', (event) => {
        const wrap = event.target.closest('.shadowverse-live-streams__thumb-wrap');
        if (!wrap || wrap.contains(event.relatedTarget)) {
            return;
        }

        deactivate(wrap);
    });
})();
</script>
HTML;
    }

    private function fetch_live_streams(bool $force_refresh = false): array
    {
        if (!$force_refresh) {
            $cached_data = get_transient(self::CACHE_KEY);
            if (is_array($cached_data)) {
                return $cached_data;
            }
        }

        $api_key = $this->get_api_key();
        if ($api_key === '') {
            return $this->get_stale_cache();
        }

        $video_map = [];
        $has_successful_search = false;

        foreach ($this->get_search_queries() as $query_label => $query) {
            $search_result = $this->fetch_search_results($api_key, $query);
            $results = $search_result['items'];

            if ($search_result['ok']) {
                $has_successful_search = true;
            }

            foreach ($results as $item) {
                $video_id = (string) ($item['id']['videoId'] ?? '');
                if ($video_id === '') {
                    continue;
                }

                if (!isset($video_map[$video_id])) {
                    $video_map[$video_id] = [
                        'video_id' => $video_id,
                        'title' => (string) ($item['snippet']['title'] ?? ''),
                        'description' => (string) ($item['snippet']['description'] ?? ''),
                        'channel_title' => (string) ($item['snippet']['channelTitle'] ?? ''),
                        'channel_id' => (string) ($item['snippet']['channelId'] ?? ''),
                        'thumbnail_url' => $this->get_thumbnail_url($item['snippet']['thumbnails'] ?? [], $video_id),
                        'matched_label' => (string) $query_label,
                    ];
                    continue;
                }

                if ($video_map[$video_id]['matched_label'] === '') {
                    $video_map[$video_id]['matched_label'] = (string) $query_label;
                }
            }
        }

        if (!$has_successful_search) {
            return $this->get_stale_cache();
        }

        if (empty($video_map)) {
            $this->cache_streams([]);
            return [];
        }

        $streams = $this->build_streams_from_video_map($api_key, $video_map);
        $this->cache_streams($streams);

        return $streams;
    }

    private function build_streams_from_video_map(string $api_key, array $video_map): array
    {
        $video_details = $this->fetch_video_details($api_key, array_keys($video_map));
        if (empty($video_details)) {
            return $this->get_stale_cache();
        }

        $streams = [];

        foreach ($video_details as $item) {
            $video_id = (string) ($item['id'] ?? '');
            if ($video_id === '' || !isset($video_map[$video_id])) {
                continue;
            }

            $details = $item['liveStreamingDetails'] ?? [];
            if (!empty($details['actualEndTime'])) {
                continue;
            }

            $base = $video_map[$video_id];
            $title = (string) ($item['snippet']['title'] ?? $base['title']);
            $description = (string) ($item['snippet']['description'] ?? $base['description']);

            if (!$this->is_shadowverse_related($title, $description)) {
                continue;
            }

            $viewer_count = isset($details['concurrentViewers']) ? (int) $details['concurrentViewers'] : null;

            $streams[] = [
                'video_id' => $video_id,
                'title' => $title,
                'description' => $description,
                'channel_title' => (string) ($item['snippet']['channelTitle'] ?? $base['channel_title']),
                'channel_id' => (string) ($item['snippet']['channelId'] ?? $base['channel_id']),
                'channel_url' => sprintf(
                    'https://www.youtube.com/channel/%s',
                    rawurlencode((string) ($item['snippet']['channelId'] ?? $base['channel_id']))
                ),
                'watch_url' => sprintf('https://www.youtube.com/watch?v=%s', rawurlencode($video_id)),
                'thumbnail_url' => $this->get_thumbnail_url($item['snippet']['thumbnails'] ?? [], $video_id, $base['thumbnail_url']),
                'viewer_count' => $viewer_count,
                'has_viewer_count' => $viewer_count !== null,
                'started_at' => (string) ($details['actualStartTime'] ?? ''),
                'matched_label' => (string) $base['matched_label'],
            ];
        }

        usort($streams, static function (array $left, array $right): int {
            $left_viewers = $left['viewer_count'] ?? -1;
            $right_viewers = $right['viewer_count'] ?? -1;

            if ($left_viewers !== $right_viewers) {
                return $right_viewers <=> $left_viewers;
            }

            return strcmp((string) ($right['started_at'] ?? ''), (string) ($left['started_at'] ?? ''));
        });

        return $streams;
    }

    private function fetch_search_results(string $api_key, string $query): array
    {
        $response = wp_remote_get(add_query_arg([
            'part' => 'snippet',
            'type' => 'video',
            'eventType' => 'live',
            'order' => 'date',
            'maxResults' => self::MAX_RESULTS_PER_QUERY,
            'q' => $query,
            'key' => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/search'), [
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'items' => [],
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return [
                'ok' => false,
                'items' => [],
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
            return [
                'ok' => true,
                'items' => [],
            ];
        }

        return [
            'ok' => true,
            'items' => $data['items'],
        ];
    }

    private function fetch_video_details(string $api_key, array $video_ids): array
    {
        $all_items = [];

        foreach (array_chunk($video_ids, 50) as $chunk) {
            $response = wp_remote_get(add_query_arg([
                'part' => 'snippet,liveStreamingDetails',
                'id' => implode(',', array_map('rawurlencode', $chunk)),
                'key' => $api_key,
            ], 'https://www.googleapis.com/youtube/v3/videos'), [
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['items']) || !is_array($data['items'])) {
                continue;
            }

            $all_items = array_merge($all_items, $data['items']);
        }

        return $all_items;
    }

    private function cache_streams(array $streams): void
    {
        set_transient(self::CACHE_KEY, $streams, self::CACHE_DURATION);
        set_transient(self::STALE_CACHE_KEY, $streams, self::STALE_CACHE_DURATION);
    }

    private function get_stale_cache(): array
    {
        $cached_data = get_transient(self::STALE_CACHE_KEY);
        return is_array($cached_data) ? $cached_data : [];
    }

    private function get_api_key(): string
    {
        $api_key = self::MANUAL_API_KEY;

        if (defined('SHADOWVERSE_LIVE_STREAMS_API_KEY')) {
            $api_key = (string) constant('SHADOWVERSE_LIVE_STREAMS_API_KEY');
        }

        /**
         * Filters the YouTube API key used by the Shadowverse Live Streams plugin.
         *
         * @param string $api_key Current API key.
         */
        $api_key = (string) apply_filters('shadowverse_live_streams_api_key', $api_key);

        return trim($api_key);
    }

    private function get_search_queries(): array
    {
        $queries = [
            'シャドバWB' => 'シャドバWB',
            'シャドウバース' => 'シャドウバース',
            'Shadowverse' => 'shadowverse',
        ];

        /**
         * Filters the search queries used to discover live streams.
         *
         * Keys are shown as badges on cards, values are sent to the YouTube search API.
         *
         * @param array<string, string> $queries Search queries.
         */
        $queries = apply_filters('shadowverse_live_streams_search_queries', $queries);

        return is_array($queries) ? $queries : [];
    }

    private function is_shadowverse_related(string $title, string $description): bool
    {
        $text = strtolower(wp_strip_all_tags($title . "\n" . $description));

        $strong_positive_patterns = [
            '/シャドバ\s*wb/u',
            '/シャドウバース\s*wb/u',
            '/shadowverse\s*wb/',
            '/shadowverse[:\s-]*worlds?\s*beyond/',
        ];

        foreach ($strong_positive_patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        $negative_patterns = [
            '/shadowverse\s*evolve/',
            '/シャドウバース\s*エボルヴ/u',
            '/シャドバ\s*エボルヴ/u',
        ];

        foreach ($negative_patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        $base_positive_patterns = [
            '/シャドバ/u',
            '/シャドウバース/u',
            '/shadowverse/',
        ];

        foreach ($base_positive_patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function get_thumbnail_url(array $thumbnails, string $video_id, string $fallback = ''): string
    {
        $candidates = [
            $thumbnails['maxres']['url'] ?? '',
            $thumbnails['standard']['url'] ?? '',
            $thumbnails['high']['url'] ?? '',
            $thumbnails['medium']['url'] ?? '',
            $thumbnails['default']['url'] ?? '',
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return sprintf('https://i.ytimg.com/vi/%s/hqdefault.jpg', rawurlencode($video_id));
    }

    private function trim_description(string $description): string
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($description)));
        if ($description === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($description, 0, 130, '…', 'UTF-8');
        }

        return strlen($description) > 130 ? substr($description, 0, 127) . '...' : $description;
    }

    private function format_started_at(string $started_at): string
    {
        if ($started_at === '') {
            return '';
        }

        $timestamp = strtotime($started_at);
        if (!$timestamp) {
            return '';
        }

        $diff_minutes = (int) floor((time() - $timestamp) / MINUTE_IN_SECONDS);
        if ($diff_minutes < 1) {
            $relative = 'たった今';
        } elseif ($diff_minutes < 60) {
            $relative = sprintf('%d分前に開始', $diff_minutes);
        } else {
            $hours = (int) floor($diff_minutes / 60);
            $minutes = $diff_minutes % 60;
            $relative = $minutes > 0
                ? sprintf('%d時間%d分前に開始', $hours, $minutes)
                : sprintf('%d時間前に開始', $hours);
        }

        return sprintf(
            '配信開始 %s (%s)',
            wp_date('Y/m/d H:i', $timestamp),
            $relative
        );
    }

    private function render_empty_state(): string
    {
        $message = $this->get_api_key() === ''
            ? 'YouTube APIキーが未設定のため、配信一覧を取得できません。'
            : '現在は条件に合うライブ配信が見つかりませんでした。少し時間をおいてもう一度ご確認ください。';

        return sprintf(
            '<div class="shadowverse-live-streams-empty"><p>%s</p></div>',
            esc_html($message)
        );
    }

    private function get_styles(): string
    {
        return <<<CSS
.shadowverse-live-streams {
    display: grid;
    grid-template-columns: 1fr;
    gap: 18px;
    margin: 24px 0;
}

.shadowverse-live-streams__card {
    display: flex;
    flex-direction: column;
    border: 1px solid #dbe5f0;
    border-radius: 18px;
    overflow: hidden;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.07);
    transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
}

.shadowverse-live-streams__card:hover {
    transform: translateY(-2px);
    border-color: #c7d7ea;
    box-shadow: 0 18px 34px rgba(15, 23, 42, 0.1);
}

.shadowverse-live-streams__thumb-wrap {
    position: relative;
    overflow: hidden;
    background: #0f172a;
}

.shadowverse-live-streams__thumb-link {
    position: absolute;
    inset: 0;
    z-index: 3;
    text-decoration: none;
}

.shadowverse-live-streams__thumb {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    transition: opacity 180ms ease, transform 320ms ease;
}

.shadowverse-live-streams__preview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    opacity: 0;
    pointer-events: none;
    transition: opacity 180ms ease;
    background: #0f172a;
}

.shadowverse-live-streams__thumb-wrap.is-preview-active .shadowverse-live-streams__thumb {
    opacity: 0.2;
    transform: scale(1.03);
}

.shadowverse-live-streams__thumb-wrap.is-preview-active .shadowverse-live-streams__preview {
    opacity: 1;
}

.shadowverse-live-streams__live-badge,
.shadowverse-live-streams__viewer-chip {
    position: absolute;
    z-index: 4;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    pointer-events: none;
}

.shadowverse-live-streams__live-badge {
    top: 12px;
    left: 12px;
    padding: 7px 10px;
    color: #fff;
    background: #dc2626;
    letter-spacing: 0.06em;
}

.shadowverse-live-streams__viewer-chip {
    right: 12px;
    bottom: 12px;
    padding: 8px 11px;
    color: #fff;
    background: rgba(15, 23, 42, 0.82);
}

.shadowverse-live-streams__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 10px;
    padding: 16px 16px 18px;
}

.shadowverse-live-streams__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.shadowverse-live-streams__tag {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    background: #e8f0f8;
    color: #48627d;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.2;
}

.shadowverse-live-streams__tag--support {
    background: #fff1c7;
    color: #8f5b00;
}

.shadowverse-live-streams__title {
    margin: 0 !important;
    padding: 0 !important;
    background: none !important;
    border: 0 !important;
    box-shadow: none !important;
    color: #0f172a;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.5;
    letter-spacing: 0.01em;
    text-indent: 0 !important;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    min-height: calc(1.5em * 2);
}

.shadowverse-live-streams__title::before,
.shadowverse-live-streams__title::after {
    display: none !important;
    content: none !important;
}

.shadowverse-live-streams__channel,
.shadowverse-live-streams__detail,
.shadowverse-live-streams__description {
    margin: 0;
    color: #475569;
    line-height: 1.65;
}

.shadowverse-live-streams__channel {
    font-size: 14px;
}

.shadowverse-live-streams__channel a {
    color: #0f172a;
    text-decoration: none;
}

.shadowverse-live-streams__channel a:hover {
    color: #1d4ed8;
}

.shadowverse-live-streams__detail {
    font-size: 13px;
}

.shadowverse-live-streams__description {
    display: none;
    font-size: 14px;
}

.shadowverse-live-streams-empty {
    margin: 24px 0;
    padding: 20px;
    border: 1px solid #d8e2ee;
    border-radius: 18px;
    background: #fff;
    color: #334155;
}

@media (min-width: 860px) {
    .shadowverse-live-streams--columns-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
CSS;
    }
}

register_activation_hook(__FILE__, ['ShadowverseLiveStreams', 'activate']);
register_deactivation_hook(__FILE__, ['ShadowverseLiveStreams', 'deactivate']);

new ShadowverseLiveStreams();
