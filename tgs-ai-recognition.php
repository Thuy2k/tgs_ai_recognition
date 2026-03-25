<?php

/**
 * Plugin Name: TGS AI Recognition
 * Plugin URI: https://bizgpt.vn/
 * Description: AI nhận diện sản phẩm từ ảnh/file để nhập nhanh vào phiếu mua hàng. Hook vào TGS Shop Management.
 * Version: 1.0.0
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * Text Domain: tgs-ai-recognition
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGS_AI_VERSION', '1.0.0');

/**
 * Main Plugin Class
 */
class TGS_AI_Recognition
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Check dependency
        add_action('admin_init', [$this, 'check_dependency']);

        // Load includes
        $this->load_includes();

        // Hook into tgs_shop_management sidebar
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);
        add_action('tgs_shop_sidebar_menu', [$this, 'render_sidebar_menu'], 10, 1);

        // Register AJAX handlers
        add_action('wp_ajax_tgs_ai_process_file', ['TGS_AI_Ajax_Handler', 'process_file']);
        add_action('wp_ajax_tgs_ai_save_settings', ['TGS_AI_Ajax_Handler', 'save_settings']);
        add_action('wp_ajax_tgs_ai_test_connection', ['TGS_AI_Ajax_Handler', 'test_connection']);

        // Hook into ticket create page — inject modal + JS
        add_action('tgs_ticket_create_after_modals', [$this, 'inject_ai_modal'], 10, 1);
        add_action('tgs_ticket_create_scripts', [$this, 'inject_ai_scripts'], 10, 1);
    }

    /**
     * Check if tgs_shop_management is active
     */
    public function check_dependency()
    {
        if (!defined('TGS_SHOP_PLUGIN_DIR')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>TGS AI Recognition</strong> yêu cầu plugin <strong>TGS Shop Management</strong> được kích hoạt.';
                echo '</p></div>';
            });
        }
    }

    /**
     * Load includes
     */
    private function load_includes()
    {
        require_once TGS_AI_PLUGIN_DIR . 'includes/class-tgs-ai-settings.php';
        require_once TGS_AI_PLUGIN_DIR . 'includes/class-tgs-ai-ajax-handler.php';
        require_once TGS_AI_PLUGIN_DIR . 'includes/class-tgs-ai-processor.php';
    }

    /**
     * Register dashboard routes into tgs_shop_management
     */
    public function register_routes($routes)
    {
        $routes['ai-settings'] = [
            'Cấu hình AI nhận diện',
            TGS_AI_PLUGIN_DIR . 'admin-views/ai-settings.php'
        ];
        return $routes;
    }

    /**
     * Add menu item to tgs_shop sidebar
     */
    public function render_sidebar_menu($current_view)
    {
        $active = ($current_view === 'ai-settings') ? 'active' : '';
        ?>
        <li class="menu-item <?php echo $active; ?>">
            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=ai-settings'); ?>"
               class="menu-link">
                <i class="menu-icon tf-icons bx bx-bot"></i>
                <div>AI nhận diện</div>
                <span class="badge bg-label-warning ms-auto">Mới</span>
            </a>
        </li>
        <?php
    }

    /**
     * Inject AI modal vào trang tạo phiếu (hook point)
     */
    public function inject_ai_modal($ticket_type)
    {
        if ($ticket_type === 'purchase') {
            include TGS_AI_PLUGIN_DIR . 'admin-views/components/ticket_ai_recognition_modal.php';
        }
    }

    /**
     * Inject AI JS vào trang tạo phiếu (hook point)
     */
    public function inject_ai_scripts($ticket_type)
    {
        if ($ticket_type === 'purchase') {
            $settings = TGS_AI_Settings::get_all();
            ?>
            <script src="<?php echo TGS_AI_PLUGIN_URL; ?>assets/js/ticket-ai-recognition.js?v=<?php echo time(); ?>"></script>
            <script type="text/javascript">
                window.TGS_AI_CONFIG = {
                    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                    nonce: '<?php echo wp_create_nonce('tgs_ai_nonce'); ?>',
                    provider: '<?php echo esc_js($settings['provider'] ?? 'groq'); ?>',
                    enabled: <?php echo ($settings['enabled'] ?? false) ? 'true' : 'false'; ?>,
                    maxFileSize: <?php echo intval($settings['max_file_size'] ?? 10); ?>,
                    acceptedFormats: '<?php echo esc_js($settings['accepted_formats'] ?? 'image/*,.xlsx,.xls,.csv,.pdf'); ?>'
                };

                // Initialize AI Recognition khi DOM ready
                jQuery(document).ready(function($) {
                    if (typeof TicketAIRecognition !== 'undefined' && window.ticketCreateInstance) {
                        window.ticketAIRecognition = new TicketAIRecognition({
                            ajaxUrl: window.TGS_AI_CONFIG.ajaxUrl,
                            nonce: window.TGS_AI_CONFIG.nonce,
                            ticketInstance: window.ticketCreateInstance,
                            excelImportInstance: window.ticketExcelImport || null
                        });

                        // Bind AI buttons (chỉ hoạt động khi AI đã bật)
                        if (window.TGS_AI_CONFIG.enabled) {
                            $('#btnTicketAIImportMain').on('click', function() {
                                if (window.ticketAIRecognition) {
                                    window.ticketAIRecognition.openForMain();
                                }
                            });
                            $('#btnTicketAIImportGift').on('click', function() {
                                if (window.ticketAIRecognition) {
                                    window.ticketAIRecognition.openForGift();
                                }
                            });
                        } else {
                            // AI chưa bật → disable nút + tooltip
                            $('#btnTicketAIImportMain, #btnTicketAIImportGift')
                                .prop('disabled', true)
                                .attr('title', 'AI chưa được bật. Vào Cài đặt > AI nhận diện để kích hoạt.');
                        }
                    }
                });
            </script>
            <?php
        }
    }

    /**
     * Get plugin settings
     */
    public static function get_settings()
    {
        return TGS_AI_Settings::get_all();
    }
}

// Initialize
add_action('plugins_loaded', function () {
    TGS_AI_Recognition::instance();
});
