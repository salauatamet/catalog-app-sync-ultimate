<?php
/**
 * Plugin Name: Catalog.app Sync Ultimate v7.2
 * Description: ПМощный плагин для автоматической синхронизации товаров, цен и остатков из Catalog.app в WooCommerce. Полностью автоматизирует процесс обновления каталога с поддержкой до 40,000+ товаров.
 * Version: 7.2
 * Author: SalauatDiiN Ahmetov & Meteorit
 * Author URI: https://github.com/salauatamet
 */

if (!defined('ABSPATH')) exit;

class Catalog_App_Sync_V7_1 {

    private $api_base           = 'https://catalog.app/api';
    private $login              = 'salauat.amet@gmail.com'; 
    private $password           = 'Evagus91';
    private $catalog_id         = '806'; 
    private $vendor_id          = '26'; 
    private $pricing_profile_id = '1'; // ID профиля ценообразования (например, 1 для РРЦ)
    private $limit              = 10;
    
    private $exclude_attributes = [
        'Ссылка SEO', 
        'SEO URL', 
        'External ID', 
        'ID товара в API',
        'Ссылка',
        'Внутренний артикул',
        'внутренний-артикул'
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('catalog_sync_v7_event', [$this, 'execute_sync'], 10, 1);
        add_action('wp_ajax_get_sync_log_v7', [$this, 'ajax_get_log']);
        add_action('wp_ajax_clean_duplicate_attrs', [$this, 'ajax_clean_duplicates']);
        add_action('wp_ajax_clean_empty_attrs', [$this, 'ajax_clean_empty']);
        
        if (!wp_next_scheduled('catalog_sync_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'catalog_sync_daily_cron');
        }
        add_action('catalog_sync_daily_cron', [$this, 'start_sync_from_cron']);
        
        if (!wp_next_scheduled('catalog_token_cleanup')) {
            wp_schedule_event(time(), 'twicedaily', 'catalog_token_cleanup');
        }
        add_action('catalog_token_cleanup', [$this, 'cleanup_token']);
    }

    public function cleanup_token() {
        delete_transient('catalog_token_v7');
        $this->log("🔄 Автоматическая очистка токена выполнена");
    }

    public function start_sync_from_cron() {
        $this->log("⏰ АВТОМАТИЧЕСКИЙ ЗАПУСК ПО РАСПИСАНИЮ...");
        wp_schedule_single_event(time(), 'catalog_sync_v7_event', [0]);
    }

    public function create_menu() {
        add_menu_page('Catalog Sync v7.1', 'Catalog Sync v7.1', 'manage_options', 'catalog-sync-v7', [$this, 'render_page'], 'dashicons-update');
    }

    private function log($msg) {
        $log = get_option('catalog_sync_log_v7', '');
        $timestamp = date('Y-m-d H:i:s');
        $new_entry = "[{$timestamp}] {$msg}";
        update_option('catalog_sync_log_v7', $new_entry . "\n" . mb_substr($log, 0, 10000));
    }

    public function ajax_get_log() {
        check_ajax_referer('catalog_sync_nonce', 'nonce');
        echo get_option('catalog_sync_log_v7', 'Ожидание логов...');
        wp_die();
    }

    private function get_token() {
        $token = get_transient('catalog_token_v7');
        if ($token) return $token;
        
        $res = wp_remote_post("{$this->api_base}/authorization", [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['login' => $this->login, 'password' => $this->password]),
            'timeout' => 30, 
            'sslverify' => false
        ]);
        
        if (is_wp_error($res)) {
            $this->log("❌ Ошибка получения токена: " . $res->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (isset($body['token'])) {
            set_transient('catalog_token_v7', $body['token'], 12 * HOUR_IN_SECONDS);
            $this->log("✅ Токен получен и сохранен на 12 часов");
            return $body['token'];
        }
        
        return false;
    }

    /**
     * Исправлено: Добавлена поддержка пагинации для кеша цен и динамический ID профиля
     */
    private function update_price_cache($token) {
        $cache = [];
        $offset = 0;
        $batch_limit = 5000;
        $continue = true;

        $this->log("⏳ Начинаю загрузку цен (Профиль ID: {$this->pricing_profile_id})...");

        while ($continue) {
            $url = "{$this->api_base}/catalogs/{$this->catalog_id}/pricing-profiles/{$this->pricing_profile_id}/prices?limit={$batch_limit}&offset={$offset}";
            
            $res = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $token], 
                'timeout' => 60, 
                'sslverify' => false
            ]);
            
            if (is_wp_error($res)) {
                $this->log("❌ Ошибка кеша цен на смещении {$offset}: " . $res->get_error_message());
                break;
            }
            
            $data = json_decode(wp_remote_retrieve_body($res), true);
            
            if (is_array($data) && !empty($data)) {
                foreach ($data as $item) {
                    $sku = strtoupper(trim($item['model']['article'] ?? ''));
                    $v_id = $item['model']['vendor']['id'] ?? 0;
                    if ($sku && $v_id) {
                        $cache[$v_id . '_' . $sku] = [
                            'price' => floatval($item['price'] ?? 0), 
                            'stock' => intval($item['inStockAmount'] ?? 0)
                        ];
                    }
                }
                
                $count_received = count($data);
                $offset += $count_received;

                // Если получили меньше, чем запрашивали — значит данные закончились
                if ($count_received < $batch_limit) {
                    $continue = false;
                }
            } else {
                $continue = false;
            }
        }

        if (!empty($cache)) {
            update_option('catalog_price_cache_v7', $cache);
            $this->log("📊 Кеш цен обновлен: " . count($cache) . " позиций");
            return count($cache);
        }
        
        return 0;
    }

    public function execute_sync($offset) {
        $token = $this->get_token();
        if (!$token) {
            $this->log("❌ Синхронизация прервана: нет токена");
            return;
        }

        if ($offset == 0) {
            $this->log("🚀 НАЧАЛО СИНХРОНИЗАЦИИ");
            $this->update_price_cache($token);
        }

        $price_map = get_option('catalog_price_cache_v7', []);
        $url = "{$this->api_base}/catalogs/{$this->catalog_id}/vendors/{$this->vendor_id}/models?offset={$offset}&limit={$this->limit}";
        
        $res = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token], 
            'timeout' => 60, 
            'sslverify' => false
        ]);
        
        if (is_wp_error($res)) {
            $this->log("❌ Ошибка запроса товаров: " . $res->get_error_message());
            return;
        }
        
        $items = json_decode(wp_remote_retrieve_body($res), true);

        if (empty($items)) { 
            $this->log("✅ СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА. Обработано товаров: {$offset}"); 
            return; 
        }

        $this->log("📦 Обработка товаров {$offset}-" . ($offset + count($items)));
        
        foreach ($items as $list_item) {
            $this->process_item_v7($list_item, $token, $price_map);
        }

        wp_schedule_single_event(time() + 3, 'catalog_sync_v7_event', [$offset + $this->limit]);
    }

    private function process_item_v7($list_item, $token, $price_map) {
        $card_url = "{$this->api_base}/catalogs/{$this->catalog_id}/models/{$list_item['id']}/card";
        $res = wp_remote_get($card_url, [
            'headers' => ['Authorization' => 'Bearer ' . $token], 
            'timeout' => 30, 
            'sslverify' => false
        ]);
        
        if (is_wp_error($res)) {
            $this->log("⚠️ Ошибка загрузки карточки товара ID {$list_item['id']}");
            return;
        }
        
        $card = json_decode(wp_remote_retrieve_body($res), true);
        if (!$card) return;

        $model_data = $card['model'] ?? $list_item;
        $sku = $model_data['article'];
        $v_id = $model_data['vendor']['id'] ?? 0;
        
        $product_id = wc_get_product_id_by_sku($sku);
        $is_new = !$product_id;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();

        $price_data = $price_map[$v_id . '_' . strtoupper(trim($sku))] ?? ['price' => 0, 'stock' => 0];

        if (!empty($model_data['alias'])) {
            $current_slug = $product->get_slug();
            if ($is_new || $current_slug !== $model_data['alias']) {
                $product->set_slug($model_data['alias']);
            }
        }

        $product->set_name($model_data['name']);
        $product->set_sku($sku);
        $product->set_regular_price($price_data['price']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($price_data['stock']);
        $product->set_stock_status($price_data['stock'] > 0 ? 'instock' : 'outofstock');
        $product->set_description($card['description']['value'] ?? '');
        $product->save();

        $pid = $product->get_id();

        if (!empty($model_data['category']['name'])) {
            $this->safe_set_term_v7($pid, $model_data['category']['name'], 'product_cat', $model_data['category']['alias'] ?? '');
        }
        
        if (!empty($model_data['vendor']['name'])) {
            $brand_tax = taxonomy_exists('product_brand') ? 'product_brand' : 'pa_brand';
            $this->safe_set_term_v7($pid, $model_data['vendor']['name'], $brand_tax, $model_data['vendor']['alias'] ?? '');
        }
        
        if (!empty($card['propertyValues'])) {
            $this->update_attrs_v7($pid, $card['propertyValues']);
        }
        
        if (!empty($card['images'])) {
            $this->upload_img_v7($pid, $card['images']);
        }
        
        $status_icon = $is_new ? '🆕' : '🔄';
        $this->log("{$status_icon} {$sku} | {$model_data['name']} | Цена: {$price_data['price']} | Остаток: {$price_data['stock']}");
    }

    private function update_attrs_v7($pid, $props) {
        $wc_attrs = [];
        $processed_slugs = [];
        
        foreach ($props as $p) {
            $name = trim($p['definition']['name'] ?? '');
            
            if (in_array($name, $this->exclude_attributes)) continue;
            
            $val = $this->extract_value($p);
            
            if ($name === '' || $val === '' || $val === null) continue;
            
            $attr_slug = $p['definition']['alias'] ?? wc_sanitize_taxonomy_name($name);
            
            if (in_array($attr_slug, $processed_slugs)) {
                $this->log("⚠️ Пропуск дубля атрибута: {$name} (slug: {$attr_slug})");
                continue;
            }
            
            $processed_slugs[] = $attr_slug;
            $tax = 'pa_' . $attr_slug;
            
            if (!taxonomy_exists($tax)) {
                $this->reg_attr_v7($name, $attr_slug);
            }
            
            wp_set_object_terms($pid, $val, $tax, false);
            
            $wc_attrs[$tax] = [
                'name' => $tax, 
                'value' => '', 
                'is_visible' => 1, 
                'is_variation' => 0, 
                'is_taxonomy' => 1
            ];
        }
        
        update_post_meta($pid, '_product_attributes', $wc_attrs);
    }

    private function extract_value($prop) {
        $value = null;
        
        if (isset($prop['enumValue']['value'])) {
            $value = $prop['enumValue']['value'];
        } elseif (isset($prop['stringValue'])) {
            $value = $prop['stringValue'];
        } elseif (isset($prop['decimalValue'])) {
            $value = $prop['decimalValue'];
        } elseif (isset($prop['integerValue'])) {
            $value = $prop['integerValue'];
        }
        
        $value = trim((string)$value);
        
        return ($value === '' || $value === '0' && !isset($prop['integerValue'])) ? null : $value;
    }

    private function safe_set_term_v7($pid, $name, $tax, $slug = '') {
        $term = term_exists($name, $tax);
        
        if (!$term) {
            $args = !empty($slug) ? ['slug' => $slug] : [];
            $term = wp_insert_term($name, $tax, $args);
            
            if (is_wp_error($term)) {
                $this->log("⚠️ Ошибка создания термина {$name}: " . $term->get_error_message());
                return;
            }
        } elseif (!empty($slug)) {
            $tid = is_array($term) ? $term['term_id'] : $term;
            wp_update_term((int)$tid, $tax, ['slug' => $slug]);
        }
        
        $tid = is_array($term) ? $term['term_id'] : (is_object($term) ? $term->term_id : $term);
        wp_set_object_terms($pid, (int)$tid, $tax, false);
    }

    private function reg_attr_v7($label, $slug) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s", 
            $slug
        ));
        
        if (!$exists) {
            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                [
                    'attribute_name' => $slug,
                    'attribute_label' => $label,
                    'attribute_type' => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public' => 0
                ]
            );
            delete_transient('wc_attribute_taxonomies');
        }
        
        register_taxonomy('pa_' . $slug, ['product']);
    }

    private function upload_img_v7($pid, $urls) {
        if (empty($urls[0])) return;
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $id = media_sideload_image($urls[0], $pid, null, 'id');
        if (!is_wp_error($id)) {
            set_post_thumbnail($pid, $id);
            $this->log("🖼️ Изображение загружено для товара #{$pid}");
        }
    }

    public function ajax_clean_duplicates() {
        check_ajax_referer('catalog_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
            return;
        }
        
        global $wpdb;
        
        try {
            $cleaned = 0;
            $duplicates = $wpdb->get_results("
                SELECT attribute_name, COUNT(*) as cnt 
                FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                GROUP BY attribute_name 
                HAVING cnt > 1
            ", ARRAY_A);
            
            if (empty($duplicates)) {
                wp_send_json_success('Дублей не найдено');
                return;
            }
            
            foreach ($duplicates as $dup) {
                $attrs = $wpdb->get_results($wpdb->prepare("
                    SELECT attribute_id 
                    FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                    WHERE attribute_name = %s 
                    ORDER BY attribute_id ASC
                ", $dup['attribute_name']), ARRAY_A);
                
                if (count($attrs) > 1) {
                    array_shift($attrs);
                    foreach ($attrs as $attr) {
                        $attr_id = intval($attr['attribute_id']);
                        if ($attr_id > 0) {
                            $wpdb->delete($wpdb->prefix . 'woocommerce_attribute_taxonomies', ['attribute_id' => $attr_id]);
                            $cleaned++;
                        }
                    }
                }
            }
            
            delete_transient('wc_attribute_taxonomies');
            $this->log("🧹 Очищено дублей атрибутов: {$cleaned}");
            wp_send_json_success("Успешно! Очищено дублей: {$cleaned}");
        } catch (Exception $e) {
            $this->log("❌ Ошибка очистки дублей: " . $e->getMessage());
            wp_send_json_error('Ошибка: ' . $e->getMessage());
        }
    }

    public function ajax_clean_empty() {
        check_ajax_referer('catalog_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
            return;
        }
        
        global $wpdb;
        
        try {
            $cleaned = 0;
            $empty_terms = $wpdb->get_results("
                SELECT t.term_id, tt.taxonomy 
                FROM {$wpdb->prefix}terms t
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy LIKE 'pa_%' 
                AND (t.name = '' OR t.name IS NULL OR TRIM(t.name) = '')
            ", ARRAY_A);
            
            if (empty($empty_terms)) {
                wp_send_json_success('Пустых значений не найдено');
                return;
            }
            
            foreach ($empty_terms as $term) {
                $term_id = intval($term['term_id']);
                if ($term_id > 0) {
                    $deleted = wp_delete_term($term_id, $term['taxonomy']);
                    if ($deleted && !is_wp_error($deleted)) {
                        $cleaned++;
                    }
                }
            }
            
            $this->log("🗑️ Удалено пустых значений атрибутов: {$cleaned}");
            wp_send_json_success("Успешно! Удалено пустых значений: {$cleaned}");
        } catch (Exception $e) {
            $this->log("❌ Ошибка удаления пустых значений: " . $e->getMessage());
            wp_send_json_error('Ошибка: ' . $e->getMessage());
        }
    }

    public function render_page() {
        $next_cron = wp_next_scheduled('catalog_sync_daily_cron');
        $cron_status = $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Не активен';
        $nonce = wp_create_nonce('catalog_sync_nonce');
        ?>
        <div class="wrap">
            <h1>🚀 Catalog Sync v7.1 (Ultimate Edition)</h1>
            <p><strong>Автор:</strong> SalauatDiiN Ahmetov & Meteorit</p>
            
            <div style="background:#fff; padding:15px; border-left:4px solid #0073aa; margin:20px 0;">
                <h3>📊 Статус системы</h3>
                <p>Профиль цен: <strong><?php echo esc_html($this->pricing_profile_id); ?></strong></p>
                <p>Следующий запуск: <strong><?php echo esc_html($cron_status); ?></strong></p>
                <p>Токен: <strong><?php echo get_transient('catalog_token_v7') ? '✅ Активен' : '❌ Не получен'; ?></strong></p>
                <p>Кеш цен: <strong><?php echo count(get_option('catalog_price_cache_v7', [])); ?> позиций</strong></p>
            </div>
            
            <div style="background:#fff; padding:15px; margin:20px 0;">
                <h3>⚙️ Управление</h3>
                <form method="post" style="display:inline-block; margin-right:10px;">
                    <?php wp_nonce_field('catalog_sync_action', 'catalog_sync_nonce_field'); ?>
                    <input type="submit" name="start_sync" class="button button-primary" value="🔄 Запустить синхронизацию">
                </form>
                <button id="clean-duplicates" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">🧹 Очистить дубли атрибутов</button>
                <button id="clean-empty" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">🗑️ Удалить пустые значения</button>
                <form method="post" style="display:inline-block; margin-left:10px;">
                    <?php wp_nonce_field('catalog_sync_action', 'catalog_sync_nonce_field'); ?>
                    <input type="submit" name="clear_token" class="button button-secondary" value="🔄 Сбросить токен">
                </form>
            </div>
            
            <div style="background:#1a1a1a; color:#32ff32; padding:15px; height:500px; overflow-y:auto; font-family:monospace; border-radius:5px;">
                <div id="log">Загрузка логов...</div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';
            
            function fetchLog() {
                $.post(ajaxurl, {
                    action: 'get_sync_log_v7',
                    nonce: nonce
                }, function(data) {
                    $('#log').html(data.replace(/\n/g, '<br>'));
                });
            }
            setInterval(fetchLog, 3000);
            fetchLog();
            
            $('#clean-duplicates').click(function() {
                if (!confirm('Вы уверены?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('⏳ Очистка...');
                $.post(ajaxurl, { action: 'clean_duplicate_attrs', nonce: btn.data('nonce') }, function(response) {
                    alert(response.success ? '✅ ' + response.data : '❌ ' + response.data);
                    btn.prop('disabled', false).text('🧹 Очистить дубли атрибутов');
                });
            });

            $('#clean-empty').click(function() {
                if (!confirm('Вы уверены?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('⏳ Очистка...');
                $.post(ajaxurl, { action: 'clean_empty_attrs', nonce: btn.data('nonce') }, function(response) {
                    alert(response.success ? '✅ ' + response.data : '❌ ' + response.data);
                    btn.prop('disabled', false).text('🗑️ Удалить пустые значения');
                });
            });
        });
        </script>
        <?php
        
        if (isset($_POST['start_sync']) && check_admin_referer('catalog_sync_action', 'catalog_sync_nonce_field')) {
            wp_schedule_single_event(time(), 'catalog_sync_v7_event', [0]);
            echo '<div class="notice notice-success"><p>✅ Синхронизация запущена!</p></div>';
        }
        
        if (isset($_POST['clear_token']) && check_admin_referer('catalog_sync_action', 'catalog_sync_nonce_field')) {
            delete_transient('catalog_token_v7');
            echo '<div class="notice notice-success"><p>✅ Токен сброшен!</p></div>';
        }
    }
}

new Catalog_App_Sync_V7_1();