<?php
/**
 * OPPIO Thank You Template - s WordPress témou
 */
defined('ABSPATH') || exit;

// ZASTAVIŤ všetky WooCommerce thankyou akcie
remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
remove_action('woocommerce_thankyou', 'woocommerce_order_details_customer_details', 20);


// Získaj order údaje
$order_id = 0;
global $wp;
if (isset($wp->query_vars['order-received']) && !empty($wp->query_vars['order-received'])) {
    $order_id = absint($wp->query_vars['order-received']);
}

if (!$order_id && isset($_GET['order']) && is_numeric($_GET['order'])) {
    $order_id = absint($_GET['order']);
}

if (!$order_id) {
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('/order-received\/(\d+)/', $current_url, $matches)) {
        $order_id = absint($matches[1]);
    }
}

if (!$order_id) {
    get_header();
    echo '<div style="text-align: center; padding: 50px;">';
    echo '<h1>' . __('Objednávka nebola nájdená.', 'woocommerce') . '</h1>';
    echo '</div>';
    get_footer();
    exit;
}

$key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
$order = wc_get_order($order_id);

if (!$order) {
    get_header();
    echo '<div style="text-align: center; padding: 50px;">';
    echo '<h1>' . __('Objednávka nebola nájdená.', 'woocommerce') . '</h1>';
    echo '</div>';
    get_footer();
    exit;
}

if (!empty($key) && $order->get_order_key() !== $key) {
    get_header();
    echo '<div style="text-align: center; padding: 50px;">';
    echo '<h1>' . __('Neplatný kľúč objednávky.', 'woocommerce') . '</h1>';
    echo '</div>';
    get_footer();
    exit;
}

// Rozlíšenie typu objednávky
$is_subscription = ($order->get_meta('_oppio_is_subscription') === 'yes');

// Získaj OPPIO plugin inštanciu
$oppio_plugin = null;
if (class_exists('OPPIO_Subscription_Manager')) {
    global $oppio_subscription_manager;
    if ($oppio_subscription_manager instanceof OPPIO_Subscription_Manager) {
        $oppio_plugin = $oppio_subscription_manager;
    } else {
        $oppio_plugin = new OPPIO_Subscription_Manager();
    }
}

// ✅ ZOBRAZ WORDPRESS HEADER
get_header();
?>

<style>
/* Skry WooCommerce default elementy */
.woocommerce-order-overview,
.woocommerce-customer-details, 
.woocommerce-order-details,
.woocommerce-bacs-bank-details,
.woocommerce-notice--success,
.shop_table,
.woocommerce-thankyou-order-received,
.woocommerce-order-downloads,
.woocommerce-breadcrumb {
    display: none !important;
}

.elementor-location-footer {
    display: none !important;
}

/* Responsive handling */
@media (max-width: 900px) {
    .custom__thankyou .oppio-flex {
        flex-direction: column !important;
    }
    .custom__thankyou .oppio-side {
        width: 100% !important;
    }
}

.custom__thankyou {
    padding-top: 160px;
}
@media screen and (max-width: 768px) {
    .custom__thankyou {
        padding-top: 160px;
    }
}
</style>

<?php

// GENERUJ OBSAH pomocou existujúcich funkcií
if ($oppio_plugin) {
    $reflection = new ReflectionClass($oppio_plugin);
    
    if ($is_subscription) {
        // Pre subscription - použij existujúcu funkciu
        $content_method = $reflection->getMethod('get_subscription_content_html');
        $content_method->setAccessible(true);
        $content_html = $content_method->invoke($oppio_plugin, $order);
        echo $content_html;
    } else {
        // Pre onetime - použij upravenú verziu
        generate_onetime_content_html($order, $oppio_plugin, $reflection);
    }
    
} 
else {
    // Fallback ak plugin nie je dostupný
    ?>
    <div style="max-width: 800px; margin: 50px auto; padding: 20px; text-align: center;">
        <h1><?php esc_html_e('Ďakujeme za objednávku!', 'woocommerce'); ?></h1>
        <p><?php esc_html_e('Vaša objednávka bola úspešne vytvorená.', 'woocommerce'); ?></p>
        <p><strong><?php esc_html_e('Číslo objednávky:', 'woocommerce'); ?></strong> #<?php echo esc_html($order->get_order_number()); ?></p>
    </div>
    <?php
}


/**
 * Generuje obsah pre jednorazové objednávky pomocou existujúcich funkcií
 */
function generate_onetime_content_html($order, $oppio_plugin, $reflection) {
    // Začni kontainer (rovnaký ako pre subscription)
    echo '<div class="custom__thankyou" style="max-width: 1200px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">';

        // Header s confirmation (rovnaký ako pre subscription)
        echo '<div style="text-align: center; margin-bottom: 30px;">';
            echo '<div style="display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                echo '<div style="width: 40px; height: 40px; background: #194A37; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px;">';
                    echo '<span style="color: white; font-size: 20px;">✓</span>';
                echo '</div>';
                echo '<div>';
                    echo '<div style="font-size: 14px; color: #666; margin-bottom: 5px;">' . __('Potvrdenie', 'oppio-subscriptions') . ' #' . strtoupper(substr(md5($order->get_id()), 0, 8)) . '</div>';
                    echo '<h2 style="margin: 0; font-size: 20px; color: #333;">' . __('Ďakujeme', 'oppio-subscriptions') .', ' . esc_html($order->get_billing_first_name()) . '!</h2>';
                echo '</div>';
            echo '</div>';
        echo '</div>';

        // Main content area - 2 column layout
        echo '<div class="oppio-flex" style="display: flex; gap: 30px; margin-bottom: 30px;">';

            // LEFT COLUMN - Onetime info
            echo '<div style="flex: 1; background: #f8f9fa; border-radius: 8px; overflow: hidden;">';
                render_onetime_info_wc_style($order);
            echo '</div>';

            // RIGHT COLUMN - Order summary
            echo '<div class="oppio-side" style="width: 280px;">';
                $summary_method = $reflection->getMethod('render_order_summary_wc_style');
                $summary_method->setAccessible(true);
                $summary_method->invoke($oppio_plugin, $order);
            echo '</div>';
            
        echo '</div>';

        // Bottom section - Order details
        echo '<div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
            $details_method = $reflection->getMethod('render_order_details_wc_style');
            $details_method->setAccessible(true);
            $details_method->invoke($oppio_plugin, $order);
        echo '</div>';

    echo '</div>';
}

/**
 * Info panel pre jednorazové objednávky
 */
function render_onetime_info_wc_style($order) {
    echo '<div style="padding: 20px;">';

        // Main message pre jednorazový nákup
        echo '<h3 style="margin: 0 0 15px 0; color: #194A37; font-size: 18px;">✅ ' . __('Vaša objednávka je potvrdená', 'oppio-subscriptions') . '</h3>';
        echo '<p style="margin: 0 0 20px 0; color: #666;">' . __('Ďakujeme za nákup! Vaša objednávka bude čoskoro spracovaná a odoslaná.', 'oppio-subscriptions') . '</p>';

        // Order summary info
        echo '<div style="background: white; padding: 15px; border-radius: 6px; margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">📦 ' . __('Jednorazový nákup', 'oppio-subscriptions') . '</h4>';
            echo '<p style="margin: 0 0 10px 0; color: #666;">💰 ' . __('Celková suma: ', 'oppio-subscriptions') . '<strong>' . $order->get_formatted_order_total() . '</strong></p>';
            
            // Doprava info
            if ($order->get_shipping_total() > 0) {
                echo '<p style="margin: 0; color: #666;">🚚 ' . __('Doprava: ', 'oppio-subscriptions') . wc_price($order->get_shipping_total()) . '</p>';
            } else {
                echo '<p style="margin: 0; color: #194A37; font-weight: 600;">🚚 ' . __('Doprava zadarmo', 'oppio-subscriptions') . '</p>';
            }
        echo '</div>';

        // Upsell sekcia - ponuka predplatného
        echo '<div style="background: #e8f5e8; padding: 15px; border-radius: 6px; border-left: 4px solid #194A37; margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #194A37; font-size: 16px;">💡 ' . __('Vedeli ste?', 'oppio-subscriptions') . '</h4>';
            echo '<p style="margin: 0 0 10px 0; color: #333; font-size: 14px;">' . __('S predplatným by ste ušetrili až 20% na každej objednávke + doprava zadarmo!', 'oppio-subscriptions') . '</p>';
            echo '<a href="' . esc_url(wc_get_page_permalink('shop')) . '" style="display: inline-block; background: #194A37; color: #FDFBEF !important; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 14px; font-weight: 600;">
                ' . __('Objednať predplatné', 'oppio-subscriptions') . '</a>';
        echo '</div>';

        // Account info
        $customer_id = $order->get_customer_id();
        $is_logged_in = is_user_logged_in() && $customer_id > 0 && get_current_user_id() == $customer_id;

        echo '<div style="background: white; padding: 15px; border-radius: 6px;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">👤 ' . __('Zákaznícky účet', 'oppio-subscriptions') . '</h4>';

            if ($is_logged_in) {
                $account_url = wc_get_page_permalink('myaccount');
                echo '<p style="margin: 0 0 10px 0; color: #666;">✅ ' . __('Ste prihlásený', 'oppio-subscriptions') . '</p>';
                echo '<a href="' . esc_url($account_url) . '" style="display: inline-block; background: #194A37; color: #FDFBEF !important; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 14px; font-weight: 600;">
                    ' . __('Môj účet', 'oppio-subscriptions') . '</a>';
            } 
            else {
                $login_url = wc_get_page_permalink('myaccount');
                echo '<p style="margin: 0 0 10px 0; color: #666;">📧 ' . __('Ak ste si vytvorili účet, prihlasovacie údaje nájdete v emaili.', 'oppio-subscriptions') . '</p>';
                echo '<a href="' . esc_url($login_url) . '" style="display: inline-block; background: #194A37; color: #FDFBEF !important; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 14px;">
                    ' . __('Prihlásiť sa', 'oppio-subscriptions') . '</a>';
            }
        echo '</div>';

    echo '</div>';
}

get_footer();
?>
