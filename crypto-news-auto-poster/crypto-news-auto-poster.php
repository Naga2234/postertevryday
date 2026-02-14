<?php
/**
 * Plugin Name: Crypto News Auto Poster
 * Version: 3.5.0
 * Description: Clean Edition - без тегов и хэштегов
 * Text Domain: crypto-news-auto-poster
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CNAP_VERSION', '3.5.0');

add_action('init', 'cnap_load_textdomain');
function cnap_load_textdomain() {
    load_plugin_textdomain('crypto-news-auto-poster', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

register_activation_hook(__FILE__, 'cnap_install');
function cnap_install() {
    add_option('cnap_enabled', 0);
    add_option('cnap_interval', 'five_minutes');
    add_option('cnap_count', 3);
    add_option('cnap_sources', array('cryptonewsz'));
    add_option('cnap_stopwords', "Subscribe\nFollow us\nJoin us\nSign up\nNewsletter\nDisclaimer\nAdvertisement");
    add_option('cnap_cache_ttl', 300);
    add_option('cnap_fresh_window_hours', 36);
    add_option('cnap_log_errors', 0);
    add_option('cnap_stats', array(
        'total_checked' => 0,
        'total_published' => 0,
        'total_filtered' => 0,
        'last_run' => '',
        'start_time' => 0,
        'uptime' => 0
    ));
}

function cnap_get_fresh_window_hours() {
    $hours = intval(get_option('cnap_fresh_window_hours', 36));
    if ($hours < 6) {
        $hours = 6;
    }
    if ($hours > 168) {
        $hours = 168;
    }

    return apply_filters('cnap_fresh_window_hours', $hours);
}

add_filter('cron_schedules', 'cnap_cron_schedules');
function cnap_cron_schedules($schedules) {
    $schedules['five_minutes'] = array('interval' => 300, 'display' => __('Каждые 5 минут', 'crypto-news-auto-poster'));
    $schedules['ten_minutes'] = array('interval' => 600, 'display' => __('Каждые 10 минут', 'crypto-news-auto-poster'));
    return $schedules;
}

register_deactivation_hook(__FILE__, 'cnap_uninstall');
function cnap_uninstall() {
    wp_clear_scheduled_hook('cnap_cron');
}

add_action('init', 'cnap_maybe_schedule_cron');
function cnap_maybe_schedule_cron() {
    $enabled = get_option('cnap_enabled', 0);
    $interval = get_option('cnap_interval', 'five_minutes');

    $current_schedule = wp_get_schedule('cnap_cron');
    $next_scheduled = wp_next_scheduled('cnap_cron');

    if ($enabled) {
        if ($current_schedule !== $interval) {
            wp_clear_scheduled_hook('cnap_cron');
            $next_scheduled = wp_next_scheduled('cnap_cron');
        }
        if (!$next_scheduled) {
            wp_schedule_event(time() + 60, $interval, 'cnap_cron');
        }
    } elseif ($next_scheduled) {
        wp_clear_scheduled_hook('cnap_cron');
    }
}

add_action('admin_menu', 'cnap_menu');
function cnap_menu() {
    global $cnap_admin_hook;
    $cnap_admin_hook = add_menu_page(
        __('Crypto News', 'crypto-news-auto-poster'),
        __('Crypto News', 'crypto-news-auto-poster'),
        'manage_options',
        'crypto-news',
        'cnap_page',
        'dashicons-rss'
    );
}

add_action('wp_enqueue_scripts', 'cnap_front_assets');
function cnap_front_assets() {
    if (is_single()) {
        wp_enqueue_style(
            'cnap-post',
            plugin_dir_url(__FILE__) . 'css/front.css',
            array(),
            CNAP_VERSION
        );
    }
}

add_action('admin_enqueue_scripts', 'cnap_admin_assets');
function cnap_admin_assets($hook_suffix) {
    global $cnap_admin_hook;
    if (!isset($cnap_admin_hook) || $hook_suffix !== $cnap_admin_hook) {
        return;
    }

    wp_enqueue_style(
        'cnap-admin',
        plugin_dir_url(__FILE__) . 'css/admin.css',
        array(),
        CNAP_VERSION
    );

    wp_enqueue_script(
        'cnap-admin',
        plugin_dir_url(__FILE__) . 'js/admin.js',
        array('jquery'),
        CNAP_VERSION,
        true
    );

    wp_localize_script('cnap-admin', 'cnapAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cnap_ajax'),
        'messages' => array(
            'toggleError' => __('Не удалось выполнить действие. Попробуйте позже.', 'crypto-news-auto-poster')
        )
    ));
}

function cnap_get_available_sources() {
    return array(
        'cryptonewsz' => array(
            'name' => 'CryptoNewsZ',
            'feed' => 'https://www.cryptonewsz.com/feed/'
        ),
        'coindesk' => array(
            'name' => 'CoinDesk',
            'feed' => 'https://www.coindesk.com/arc/outboundfeeds/rss/'
        ),
        'cointelegraph' => array(
            'name' => 'Cointelegraph',
            'feed' => 'https://cointelegraph.com/rss'
        ),
        'u-today' => array(
            'name' => 'U.Today',
            'feed' => 'https://u.today/rss'
        )
    );
}

function cnap_get_selected_sources() {
    $selected = get_option('cnap_sources', array('cryptonewsz'));
    if (!is_array($selected)) {
        $selected = array($selected);
    }

    $available = cnap_get_available_sources();
    $valid = array_values(array_intersect($selected, array_keys($available)));

    if (empty($valid)) {
        $valid = array('cryptonewsz');
    }

    return $valid;
}

function cnap_get_cache_ttl() {
    $ttl = intval(get_option('cnap_cache_ttl', 300));
    if ($ttl < 60) {
        $ttl = 60;
    }
    if ($ttl > 300) {
        $ttl = 300;
    }
    return apply_filters('cnap_cache_ttl', $ttl);
}

function cnap_log_error($message, $context = array()) {
    $log_enabled = get_option('cnap_log_errors', 0);
    if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
        if (!$log_enabled) {
            return;
        }
    }

    $prefix = '[CNAP] ';
    $details = '';
    if (!empty($context)) {
        $details = ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($prefix . $message . $details);
}

function cnap_get_cached_feed_items($source_key, $feed_url) {
    $transient_key = 'cnap_rss_' . $source_key;
    $cached = get_transient($transient_key);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $rss = fetch_feed($feed_url);
    if (is_wp_error($rss)) {
        cnap_log_error('fetch_feed error', array(
            'source' => $source_key,
            'feed_url' => $feed_url,
            'error' => $rss->get_error_message()
        ));
        return array();
    }

    $items = $rss->get_items(0, 20);
    $data = array();
    foreach ($items as $item) {
        $timestamp = intval($item->get_date('U'));
        $data[] = array(
            'title' => $item->get_title(),
            'link' => $item->get_permalink(),
            'published_at' => $timestamp > 0 ? $timestamp : time()
        );
    }

    set_transient($transient_key, $data, cnap_get_cache_ttl());
    return $data;
}

function cnap_page() {
    $enabled = get_option('cnap_enabled', 0);
    $count = get_option('cnap_count', 3);
    $interval = get_option('cnap_interval', 'five_minutes');
    $stopwords = get_option('cnap_stopwords', '');
    $selected_sources = cnap_get_selected_sources();
    $available_sources = cnap_get_available_sources();

    $stats = get_option('cnap_stats', array(
        'total_checked' => 0,
        'total_published' => 0,
        'total_filtered' => 0,
        'last_run' => '',
        'start_time' => 0,
        'uptime' => 0
    ));

    $start_time = isset($stats['start_time']) ? $stats['start_time'] : 0;
    $uptime = isset($stats['uptime']) ? $stats['uptime'] : 0;

    $uptime_seconds = 0;
    if ($enabled && $start_time > 0) {
        $uptime_seconds = time() - $start_time;
    } elseif (!$enabled && $uptime > 0) {
        $uptime_seconds = $uptime;
    }

    $uptime_hours = floor($uptime_seconds / 3600);
    $uptime_minutes = floor(($uptime_seconds % 3600) / 60);
    /* translators: 1: hours, 2: minutes */
    $uptime_display = sprintf(__('%1$dч %2$dмин', 'crypto-news-auto-poster'), $uptime_hours, $uptime_minutes);

    ?>
    <div class="cnap-admin-wrap">
        <div class="cnap-hero">
            <h1 class="cnap-hero__title"><?php echo esc_html(sprintf(__('🚀 Crypto News Auto Poster v%s', 'crypto-news-auto-poster'), CNAP_VERSION)); ?></h1>
            <p class="cnap-hero__subtitle"><?php esc_html_e('Clean Edition - без тегов и хэштегов', 'crypto-news-auto-poster'); ?></p>
        </div>

        <?php if ($enabled): ?>
        <div class="cnap-status">
            <div class="cnap-status__row">
                <div>
                    <h3 class="cnap-status__title"><?php esc_html_e('⏱️ АВТОПОСТИНГ РАБОТАЕТ', 'crypto-news-auto-poster'); ?></h3>
                    <p class="cnap-status__text">
                        <?php
                        printf(
                            esc_html__('Время работы: %s', 'crypto-news-auto-poster'),
                            '<strong>' . esc_html($uptime_display) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
                <div class="cnap-status__icon">🟢</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="cnap-highlight">
            <h3 class="cnap-highlight__title"><?php esc_html_e('🆕 Новое в v3.5.0 CLEAN', 'crypto-news-auto-poster'); ?></h3>
            <p class="cnap-highlight__text">
                <?php esc_html_e('✅ ПОЛНОСТЬЮ УДАЛЕНЫ теги', 'crypto-news-auto-poster'); ?><br>
                <?php esc_html_e('✅ ПОЛНОСТЬЮ УДАЛЕНЫ хэштеги', 'crypto-news-auto-poster'); ?><br>
                <?php esc_html_e('✅ Чистые посты без меток', 'crypto-news-auto-poster'); ?><br>
                <?php esc_html_e('✅ Идеальное форматирование', 'crypto-news-auto-poster'); ?><br>
                <?php esc_html_e('✅ Без "Также читайте"', 'crypto-news-auto-poster'); ?><br>
                <?php esc_html_e('✅ Без дублей фото', 'crypto-news-auto-poster'); ?>
            </p>
        </div>

        <div class="cnap-stats">
            <div class="cnap-card">
                <h3 class="cnap-card__label"><?php esc_html_e('📊 Проверено', 'crypto-news-auto-poster'); ?></h3>
                <p class="cnap-card__value cnap-card__value--positive"><?php echo esc_html(number_format($stats['total_checked'])); ?></p>
            </div>
            <div class="cnap-card">
                <h3 class="cnap-card__label"><?php esc_html_e('✅ Опубликовано', 'crypto-news-auto-poster'); ?></h3>
                <p class="cnap-card__value cnap-card__value--positive"><?php echo esc_html(number_format($stats['total_published'])); ?></p>
            </div>
            <div class="cnap-card">
                <h3 class="cnap-card__label"><?php esc_html_e('🚫 Отфильтровано', 'crypto-news-auto-poster'); ?></h3>
                <p class="cnap-card__value cnap-card__value--negative"><?php echo esc_html(number_format($stats['total_filtered'])); ?></p>
            </div>
        </div>

        <div class="cnap-settings">
            <div class="cnap-card cnap-card--section">
                <h2 class="cnap-card__title"><?php esc_html_e('⚙️ Автопостинг', 'crypto-news-auto-poster'); ?></h2>
                <p class="cnap-card__text">
                    <strong><?php esc_html_e('Статус:', 'crypto-news-auto-poster'); ?></strong>
                    <span class="cnap-status-indicator"><?php echo $enabled ? '🟢' : '🔴'; ?></span>
                    <?php echo esc_html($enabled ? __('Включен', 'crypto-news-auto-poster') : __('Выключен', 'crypto-news-auto-poster')); ?>
                </p>
                <?php if ($enabled): ?>
                <p class="cnap-card__text">
                    <strong><?php esc_html_e('Время:', 'crypto-news-auto-poster'); ?></strong><br>
                    <?php echo esc_html($uptime_display); ?>
                </p>
                <?php endif; ?>
                <form method="post" class="cnap-form">
                    <?php wp_nonce_field('cnap_save_settings', 'cnap_settings_nonce'); ?>
                    <label class="cnap-form__label"><strong><?php esc_html_e('Интервал:', 'crypto-news-auto-poster'); ?></strong></label><br>
                    <select name="interval" class="cnap-form__field">
                        <option value="five_minutes" <?php selected($interval, 'five_minutes'); ?>><?php esc_html_e('5 минут', 'crypto-news-auto-poster'); ?></option>
                        <option value="ten_minutes" <?php selected($interval, 'ten_minutes'); ?>><?php esc_html_e('10 минут', 'crypto-news-auto-poster'); ?></option>
                        <option value="hourly" <?php selected($interval, 'hourly'); ?>><?php esc_html_e('1 час', 'crypto-news-auto-poster'); ?></option>
                    </select>
                    <label class="cnap-form__label"><strong><?php esc_html_e('Постов:', 'crypto-news-auto-poster'); ?></strong></label><br>
                    <input type="number" name="count" value="<?php echo esc_attr($count); ?>" min="1" max="20" class="cnap-form__field">
                    <label class="cnap-form__label"><strong><?php esc_html_e('Источники:', 'crypto-news-auto-poster'); ?></strong></label><br>
                    <div class="cnap-checkboxes">
                        <?php foreach ($available_sources as $key => $source): ?>
                            <label class="cnap-checkboxes__label">
                                <input type="checkbox" name="sources[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected_sources, true)); ?>>
                                <span><?php echo esc_html($source['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="cnap_save_settings" value="1">
                    <button type="submit" class="button button-primary cnap-button-full">💾 <?php esc_html_e('Сохранить', 'crypto-news-auto-poster'); ?></button>
                </form>
                <div class="cnap-actions">
                    <button type="button" class="button button-primary cnap-action" data-action="toggle">
                        <?php echo esc_html($enabled ? __('⏸️ Стоп', 'crypto-news-auto-poster') : __('▶️ Старт', 'crypto-news-auto-poster')); ?>
                    </button>
                    <button type="button" class="button button-primary cnap-action" data-action="fetch">
                        <?php esc_html_e('🔄 Вручную', 'crypto-news-auto-poster'); ?>
                    </button>
                </div>
            </div>

            <div class="cnap-card cnap-card--section">
                <h2 class="cnap-card__title"><?php esc_html_e('🚫 Стоп-слова', 'crypto-news-auto-poster'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('cnap_save_stopwords', 'cnap_stopwords_nonce'); ?>
                    <textarea name="stopwords" rows="10" class="cnap-form__textarea"><?php echo esc_textarea($stopwords); ?></textarea>
                    <input type="hidden" name="cnap_save_stopwords" value="1">
                    <button type="submit" class="button button-primary cnap-button-full cnap-button-spaced">💾 <?php esc_html_e('Сохранить', 'crypto-news-auto-poster'); ?></button>
                </form>
            </div>
        </div>

        <div class="cnap-card cnap-card--section">
            <h2 class="cnap-card__title"><?php esc_html_e('📰 Последние посты', 'crypto-news-auto-poster'); ?></h2>
            <?php
            $posts = get_posts(array('posts_per_page' => 15, 'meta_key' => 'cnap_post'));
            if ($posts) {
                echo '<div class="cnap-posts-grid">';
                foreach ($posts as $p) {
                    $photos_count = get_post_meta($p->ID, 'cnap_photos_count', true);

                    echo '<div class="cnap-post-item">';
                    if (has_post_thumbnail($p->ID)) {
                        echo '<div class="cnap-post-thumb">' . get_the_post_thumbnail($p->ID, array(80,80), array('class' => 'cnap-thumb')) . '</div>';
                    }
                    echo '<div class="cnap-post-content">';
                    echo '<strong>' . esc_html($p->post_title) . '</strong><br>';
                    echo '<small class="cnap-post-meta">';
                    if ($photos_count) {
                        echo '<span class="cnap-post-photos">📷 ' . esc_html($photos_count) . '</span>';
                    }
                    echo esc_html(get_the_date('d.m.Y H:i', $p->ID));
                    echo '</small>';
                    echo '</div>';
                    echo '<a href="' . esc_url(get_edit_post_link($p->ID)) . '" class="button button-small">' . esc_html__('Ред.', 'crypto-news-auto-poster') . '</a>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<p class="cnap-posts-empty">' . esc_html__('Нет постов', 'crypto-news-auto-poster') . '</p>';
            }
            ?>
        </div>

        <div id="cnap-result" class="cnap-result"></div>
        <div id="cnap-loading" class="cnap-loading">
            <div class="cnap-loading__content">
                <div class="cnap-loading__spinner"></div>
                <p class="cnap-loading__text"><?php esc_html_e('Загрузка...', 'crypto-news-auto-poster'); ?></p>
            </div>
        </div>
    </div>
    <?php

    if (isset($_POST['cnap_save_settings'])) {
        check_admin_referer('cnap_save_settings', 'cnap_settings_nonce');
        update_option('cnap_count', intval($_POST['count']));
        $new_interval = sanitize_text_field($_POST['interval']);
        $available_sources = cnap_get_available_sources();
        $sources_input = isset($_POST['sources']) ? (array) $_POST['sources'] : array();
        $sources_sanitized = array();
        foreach ($sources_input as $source_key) {
            $source_key = sanitize_text_field($source_key);
            if (isset($available_sources[$source_key])) {
                $sources_sanitized[] = $source_key;
            }
        }
        if (empty($sources_sanitized)) {
            $sources_sanitized = array('cryptonewsz');
        }
        update_option('cnap_sources', array_values(array_unique($sources_sanitized)));
        update_option('cnap_interval', $new_interval);
        if (get_option('cnap_enabled', 0)) {
            wp_clear_scheduled_hook('cnap_cron');
            wp_schedule_event(time() + 60, $new_interval, 'cnap_cron');
        }
        echo '<div class="cnap-notice"><strong>' . esc_html__('✅ Сохранено!', 'crypto-news-auto-poster') . '</strong></div>';
    }

    if (isset($_POST['cnap_save_stopwords'])) {
        check_admin_referer('cnap_save_stopwords', 'cnap_stopwords_nonce');
        update_option('cnap_stopwords', sanitize_textarea_field($_POST['stopwords']));
        echo '<div class="cnap-notice"><strong>' . esc_html__('✅ Сохранено!', 'crypto-news-auto-poster') . '</strong></div>';
    }
}

add_action('wp_ajax_cnap_toggle', 'cnap_ajax_toggle');
function cnap_ajax_toggle() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Недостаточно прав', 'crypto-news-auto-poster'));
    }
    check_ajax_referer('cnap_ajax', 'nonce');

    $enabled = get_option('cnap_enabled', 0);
    $stats = get_option('cnap_stats', array());
    $interval = get_option('cnap_interval', 'five_minutes');

    if ($enabled) {
        if (isset($stats['start_time']) && $stats['start_time'] > 0) {
            $stats['uptime'] = time() - $stats['start_time'];
        }
        update_option('cnap_enabled', 0);
        update_option('cnap_stats', $stats);
        wp_clear_scheduled_hook('cnap_cron');
        wp_send_json_success(__('⏸️ Остановлен', 'crypto-news-auto-poster'));
    } else {
        $stats['start_time'] = time();
        $stats['uptime'] = 0;
        update_option('cnap_enabled', 1);
        update_option('cnap_stats', $stats);
        wp_clear_scheduled_hook('cnap_cron');
        wp_schedule_event(time() + 60, $interval, 'cnap_cron');
        wp_send_json_success(__('✅ Запущен!', 'crypto-news-auto-poster'));
    }
}

add_action('wp_ajax_cnap_fetch', 'cnap_ajax_fetch');
function cnap_ajax_fetch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Недостаточно прав', 'crypto-news-auto-poster'));
    }
    check_ajax_referer('cnap_ajax', 'nonce');

    $result = cnap_get_news();

    $msg = '<strong>' . esc_html__('📊 РЕЗУЛЬТАТ:', 'crypto-news-auto-poster') . '</strong><br><br>';
    $msg .= esc_html(sprintf(__('Найдено: %d', 'crypto-news-auto-poster'), $result['fetched'])) . '<br>';
    $msg .= esc_html(sprintf(__('Опубликовано: %d', 'crypto-news-auto-poster'), $result['published'])) . '<br>';
    $msg .= esc_html(sprintf(__('Пропущено: %d', 'crypto-news-auto-poster'), $result['skipped'])) . '<br>';
    if ($result['total_photos'] > 0) {
        $msg .= esc_html(sprintf(__('Фото: %d', 'crypto-news-auto-poster'), $result['total_photos']));
    }

    wp_send_json_success($msg);
}

add_action('cnap_cron', 'cnap_cron_run');
function cnap_cron_run() {
    if (get_option('cnap_enabled', 0)) {
        cnap_get_news();
    }
}

function cnap_get_news() {
    $count = get_option('cnap_count', 3);
    $stopwords_text = get_option('cnap_stopwords', '');
    $stopwords = array_filter(array_map('trim', explode("\n", $stopwords_text)));
    $selected_sources = cnap_get_selected_sources();
    $available_sources = cnap_get_available_sources();

    $stats = array(
        'fetched' => 0,
        'published' => 0,
        'skipped' => 0,
        'total_photos' => 0
    );

    $all_items = array();
    $fresh_cutoff = time() - (cnap_get_fresh_window_hours() * HOUR_IN_SECONDS);

    foreach ($selected_sources as $source_key) {
        if (!isset($available_sources[$source_key])) {
            continue;
        }

        $source = $available_sources[$source_key];
        $items = cnap_get_cached_feed_items($source_key, $source['feed']);
        $stats['fetched'] += count($items);

        foreach ($items as $item) {
            $published_at = isset($item['published_at']) ? intval($item['published_at']) : time();
            if ($published_at < $fresh_cutoff) {
                continue;
            }

            $item['source_name'] = $source['name'];
            $all_items[] = $item;
        }
    }

    usort($all_items, function($a, $b) {
        $a_time = isset($a['published_at']) ? intval($a['published_at']) : 0;
        $b_time = isset($b['published_at']) ? intval($b['published_at']) : 0;
        return $b_time <=> $a_time;
    });

    foreach ($all_items as $item) {
        if ($stats['published'] >= $count) {
            break;
        }

        $title = isset($item['title']) ? $item['title'] : '';
        $link = isset($item['link']) ? $item['link'] : '';
        if (empty($link)) {
            $stats['skipped']++;
            continue;
        }

        $exists = get_posts(array(
            'post_type' => 'post',
            'meta_key' => 'cnap_link',
            'meta_value' => $link,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));

        if (!empty($exists)) {
            $stats['skipped']++;
            continue;
        }

        $full = cnap_deep_parse_v35($link, $stopwords);

        if (empty($full['content'])) {
            cnap_log_error('parsed content empty', array(
                'link' => $link,
                'title' => $title,
                'photos_count' => $full['photos_count']
            ));
            $stats['skipped']++;
            continue;
        }

        $post_content = '<div class="cnap-post-content">' . $full['content'] . '</div>';

        $post_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => $post_content,
            'post_status' => 'publish',
            'post_author' => 1
        ));

        if ($post_id) {
            update_post_meta($post_id, 'cnap_post', 1);
            update_post_meta($post_id, 'cnap_link', $link);
            update_post_meta($post_id, 'cnap_source', isset($item['source_name']) ? $item['source_name'] : '');
            update_post_meta($post_id, 'cnap_photos_count', $full['photos_count']);

            $thumb_set = cnap_set_thumb($post_id, $full['featured_image']);
            if (!$thumb_set && !empty($full['image_urls'])) {
                foreach ($full['image_urls'] as $image_url) {
                    if ($image_url === $full['featured_image']) {
                        continue;
                    }
                    $thumb_set = cnap_set_thumb($post_id, $image_url);
                    if ($thumb_set) {
                        $full['featured_image'] = $image_url;
                        break;
                    }
                }
            }
            if (!$thumb_set && !has_post_thumbnail($post_id)) {
                $fallback_set = cnap_set_fallback_thumb($post_id);
                if ($fallback_set && $full['photos_count'] == 0) {
                    update_post_meta($post_id, 'cnap_photos_count', 1);
                }
            }

            $stats['published']++;
            $stats['total_photos'] += $full['photos_count'];
        }
    }

    $global_stats = get_option('cnap_stats', array());
    $global_stats['total_checked'] = (isset($global_stats['total_checked']) ? $global_stats['total_checked'] : 0) + $stats['fetched'];
    $global_stats['total_published'] = (isset($global_stats['total_published']) ? $global_stats['total_published'] : 0) + $stats['published'];
    $global_stats['total_filtered'] = (isset($global_stats['total_filtered']) ? $global_stats['total_filtered'] : 0) + $stats['skipped'];
    $global_stats['last_run'] = date('d.m.Y H:i:s');
    update_option('cnap_stats', $global_stats);

    if ($stats['published'] === 0) {
        cnap_log_error('cnap_get_news returned zero publications', $stats);
    }

    return $stats;
}

function cnap_deep_parse_v35($url, $stopwords) {
    $cache_key = 'cnap_parse_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $result = array(
        'content' => '',
        'featured_image' => '',
        'photos_count' => 0,
        'image_urls' => array()
    );

    $response = wp_remote_get($url, array('timeout' => 30, 'user-agent' => 'Mozilla/5.0'));
    if (is_wp_error($response)) {
        cnap_log_error('wp_remote_get error', array(
            'url' => $url,
            'error' => $response->get_error_message()
        ));
        return $result;
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        cnap_log_error('empty response body', array('url' => $url));
        return $result;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $content_selectors = array(
        '//*[contains(@class, "entry-content")]',
        '//*[contains(@class, "post-content")]',
        '//*[contains(@class, "article-content")]'
    );

    $content_node = null;
    foreach ($content_selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content_node = $nodes->item(0);
            break;
        }
    }

    if (!$content_node) {
        cnap_log_error('content node not found', array('url' => $url));
        return $result;
    }

    $remove_selectors = array(
        './/script', './/style', './/*[contains(@class, "share")]',
        './/*[contains(@class, "social")]', './/*[contains(@class, "subscribe")]',
        './/*[contains(@class, "related")]', './/*[contains(@class, "sidebar")]',
        './/*[contains(@class, "author")]', './/*[contains(@class, "comments")]',
        './/ul', './/ol'
    );

    foreach ($remove_selectors as $selector) {
        $nodes = $xpath->query($selector, $content_node);
        foreach ($nodes as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $images = $xpath->query('.//img', $content_node);
    $all_images = array();
    $seen_urls = array();
    $image_nodes = array();

    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (empty($src)) $src = $img->getAttribute('data-src');

        $width = $img->getAttribute('width');
        if ($width && $width < 150) continue;

        if (stripos($src, 'icon') !== false || stripos($src, 'avatar') !== false || stripos($src, 'logo') !== false) {
            continue;
        }

        if (strpos($src, 'http') !== 0) {
            $parsed = parse_url($url);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            $src = $base . (strpos($src, '/') === 0 ? '' : '/') . $src;
        }

        if (in_array($src, $seen_urls)) {
            continue;
        }
        $seen_urls[] = $src;

        $caption = '';
        $alt = $img->getAttribute('alt');
        $title = $img->getAttribute('title');

        $parent = $img->parentNode;
        if ($parent) {
            $caption_node = $xpath->query('.//*[contains(@class, "caption")]', $parent);
            if ($caption_node->length > 0) {
                $caption = trim($caption_node->item(0)->textContent);
            }
        }

        if (empty($caption) && !empty($alt)) $caption = $alt;
        if (empty($caption) && !empty($title)) $caption = $title;

        $all_images[] = array('src' => $src, 'caption' => $caption);
        $image_nodes[] = $img;
    }

    if (empty($all_images)) {
        cnap_log_error('no images found', array('url' => $url));
        return $result;
    }

    foreach ($image_nodes as $img) {
        if ($img->parentNode) {
            $img->parentNode->removeChild($img);
        }
    }

    $result['featured_image'] = $all_images[0]['src'];
    $result['photos_count'] = count($all_images);
    $result['image_urls'] = array_values(array_map(function($img) {
        return $img['src'];
    }, $all_images));

    $text_content = $content_node->textContent;

    $cut_patterns = array(
        '/Также\s+читайте\s*:?.*/ius',
        '/Читайте\s+также\s*:?.*/ius',
        '/Read\s+also\s*:?.*/ius',
        '/See\s+also\s*:?.*/ius',
        '/Related\s+articles?\s*:?.*/ius',
        '/Also\s+read\s*:?.*/ius'
    );

    $was_cut = false;
    foreach ($cut_patterns as $pattern) {
        if (preg_match($pattern, $text_content)) {
            $text_content = preg_replace($pattern, '', $text_content);
            $was_cut = true;
            break;
        }
    }

    $content_html = $dom->saveHTML($content_node);

    if ($was_cut) {
        $markers = array('Также читайте', 'Читайте также', 'Read also', 'See also', 'Related article', 'Also read');
        foreach ($markers as $marker) {
            $pos = mb_stripos($content_html, $marker);
            if ($pos !== false) {
                $content_html = mb_substr($content_html, 0, $pos);
                break;
            }
        }
    }

    $default_stopwords = array('Subscribe', 'Follow us', 'Join us', 'Sign up', 'Newsletter', 'Disclaimer');
    $all_stopwords = array_merge($stopwords, $default_stopwords);

    foreach ($all_stopwords as $stopword) {
        if (empty($stopword)) continue;
        $content_html = str_ireplace($stopword, '', $content_html);
    }

    $content_html = preg_replace('/#[a-zA-Z0-9_]+/u', '', $content_html);
    $content_html = preg_replace('/<p>\s*<\/p>/', '', $content_html);
    $content_html = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $content_html);
    $content_html = preg_replace('/<br\s*\/?>{2,}/i', '<br>', $content_html);
    $content_html = preg_replace('/\n{3,}/', "\n\n", $content_html);
    $content_html = preg_replace('/\s*(<img[^>]*>)\s*/i', '$1', $content_html);
    $content_html = preg_replace('/<\/p>\s*<img/i', '</p><img', $content_html);
    $content_html = preg_replace('/<img([^>]*)>\s*<p>/i', '<img$1><p>', $content_html);
    $content_html = preg_replace('/[ \t]+/', ' ', $content_html);
    $content_html = preg_replace('/<p>\s+/', '<p>', $content_html);
    $content_html = preg_replace('/\s+<\/p>/', '</p>', $content_html);
    if (!preg_match('/<p\b[^>]*>/i', $content_html)) {
        $content_html = wpautop($content_html);
    }

    $content_html = cnap_emphasize_first_paragraph($content_html);

    $allowed_tags = wp_kses_allowed_html('post');
    $allowed_tags['figure'] = array(
        'class' => true,
    );
    $allowed_tags['figcaption'] = array(
        'class' => true,
    );
    $allowed_tags['img'] = array_merge($allowed_tags['img'] ?? array(), array(
        'src' => true,
        'alt' => true,
        'class' => true,
        'title' => true,
        'loading' => true,
        'srcset' => true,
        'sizes' => true,
    ));
    $content_html = wp_kses($content_html, $allowed_tags);

    if (count($all_images) > 0) {
        $content_images = $all_images;
        if (!empty($result['featured_image'])) {
            $content_images = array_values(array_filter($content_images, function($img) use ($result) {
                return $img['src'] !== $result['featured_image'];
            }));
        }

        $paragraphs = preg_split('/(<\/p>)/', $content_html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $para_count = count($paragraphs);
        $paragraph_count = preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $content_html);
        $min_paragraphs_for_spread = 3;

        $max_photos = min(count($content_images), 5);
        $inserted = 0;

        if ($paragraph_count >= $min_paragraphs_for_spread && $para_count > 2) {
            for ($i = 0; $i < $max_photos; $i++) {
                $img_data = $content_images[$i];

                if ($i == 0) {
                    $insert_pos = 2;
                } else {
                    $interval = intval($para_count / ($max_photos + 1));
                    $insert_pos = min($interval * ($i + 1), $para_count - 1);
                }

                $img_html = '<figure class="cnap-figure"><img src="' . esc_url($img_data['src']) . '" alt="' . esc_attr__('Crypto News', 'crypto-news-auto-poster') . '">';

                if (!empty($img_data['caption'])) {
                    $img_html .= '<figcaption><strong>' . esc_html($img_data['caption']) . '</strong></figcaption>';
                }
                $img_html .= '</figure>';

                if (isset($paragraphs[$insert_pos])) {
                    $paragraphs[$insert_pos] .= $img_html;
                    $inserted++;
                }
            }

            $content_html = implode('', $paragraphs);
        } else {
            for ($i = 0; $i < $max_photos; $i++) {
                $img_data = $content_images[$i];
                $img_html = '<figure class="cnap-figure"><img src="' . esc_url($img_data['src']) . '" alt="' . esc_attr__('Crypto News', 'crypto-news-auto-poster') . '">';

                if (!empty($img_data['caption'])) {
                    $img_html .= '<figcaption><strong>' . esc_html($img_data['caption']) . '</strong></figcaption>';
                }
                $img_html .= '</figure>';

                $content_html .= $img_html;
                $inserted++;
            }
        }
    }

    $result['content'] = trim($content_html);

    if (empty($result['featured_image']) && !empty($all_images)) {
        $result['featured_image'] = $all_images[0]['src'];
    }

    set_transient($cache_key, $result, cnap_get_cache_ttl());
    return $result;
}

function cnap_emphasize_first_paragraph($content_html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content_html . '</div>');
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $paragraph = $xpath->query('//div/p')->item(0);
    if (!$paragraph) {
        return $content_html;
    }

    $strong = $dom->createElement('strong');
    while ($paragraph->firstChild) {
        $strong->appendChild($paragraph->removeChild($paragraph->firstChild));
    }
    $paragraph->appendChild($strong);

    $container = $xpath->query('//div')->item(0);
    $updated_html = '';
    foreach ($container->childNodes as $child) {
        $updated_html .= $dom->saveHTML($child);
    }

    return $updated_html;
}

function cnap_set_thumb($post_id, $url) {
    if (empty($url)) {
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $tmp = download_url($url, 20);
    if (is_wp_error($tmp)) {
        return false;
    }

    $file = array('name' => basename($url), 'tmp_name' => $tmp);
    $id = media_handle_sideload($file, $post_id);

    if (is_wp_error($id)) {
        return false;
    }

    set_post_thumbnail($post_id, $id);
    return true;
}

function cnap_set_fallback_thumb($post_id) {
    $keywords = array('bitcoin', 'crypto', 'blockchain', 'defi', 'nft', 'trading', 'mining');
    $used_hashes = get_option('cnap_fallback_hashes', array());
    if (!is_array($used_hashes)) {
        $used_hashes = array();
    }

    $max_attempts = 5;
    $fallback_url = '';

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $keyword = $keywords[array_rand($keywords)];
        $sig = wp_generate_password(8, false, false);
        $query = rawurlencode($keyword . ',crypto,blockchain,defi,nft,trading,mining');
        $fallback_url = 'https://source.unsplash.com/1600x900/?' . $query . '&sig=' . $sig;

        $tmp = download_url($fallback_url, 20);
        if (is_wp_error($tmp)) {
            cnap_log_error('fallback image download error', array(
                'url' => $fallback_url,
                'error' => $tmp->get_error_message()
            ));
            continue;
        }

        $hash = hash_file('sha256', $tmp);
        if (in_array($hash, $used_hashes, true)) {
            @unlink($tmp);
            $fallback_url = '';
            continue;
        }

        $file = array(
            'name' => 'cnap-' . $keyword . '-' . $sig . '.jpg',
            'tmp_name' => $tmp
        );

        $id = media_handle_sideload($file, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
            cnap_log_error('fallback image sideload error', array(
                'url' => $fallback_url,
                'error' => $id->get_error_message()
            ));
            $fallback_url = '';
            continue;
        }

        cnap_resize_attachment_to_site($id);
        set_post_thumbnail($post_id, $id);

        $used_hashes[] = $hash;
        $used_hashes = array_slice($used_hashes, -100);
        update_option('cnap_fallback_hashes', $used_hashes);
        update_post_meta($post_id, 'cnap_fallback_image', $fallback_url);

        return true;
    }

    return false;
}

function cnap_resize_attachment_to_site($attachment_id) {
    $width = intval(get_option('thumbnail_size_w'));
    $height = intval(get_option('thumbnail_size_h'));
    if ($width <= 0 || $height <= 0) {
        return;
    }

    $file = get_attached_file($attachment_id);
    if (empty($file) || !file_exists($file)) {
        return;
    }

    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) {
        return;
    }

    $crop = (bool) get_option('thumbnail_crop', false);
    $editor->resize($width, $height, $crop);
    $saved = $editor->save($file);
    if (is_wp_error($saved)) {
        return;
    }

    $metadata = wp_generate_attachment_metadata($attachment_id, $file);
    if (!is_wp_error($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
}
