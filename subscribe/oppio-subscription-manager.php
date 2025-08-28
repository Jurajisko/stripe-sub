<?php
/**
 * Plugin Name: OPPIO Subscription Manager V5.2 ROZDELENE
 * Plugin URI: https://oppio.sk
 * Description: Custom subscription management for OPPIO store - 14-day and monthly subscriptions via WooCommerce Stripe Gateway
 * Version: 5.0
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 * Author: SIN s.r.o
 * Author URI: https://oppio.sk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oppio-subscriptions
 * Domain Path: /languages
 * Network: false
 */

// Zabráni priamemu prístupu
if (!defined('ABSPATH')) {
    exit;
}

// Kontrola či je WooCommerce aktívny
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Kontrola či je WooCommerce Stripe Gateway aktívny
add_action('admin_notices', function() {
    if (!class_exists('WC_Gateway_Stripe')) {
        echo '<div class="notice notice-error"><p><strong>OPPIO Subscription Manager:</strong> Vyžaduje WooCommerce Stripe Gateway plugin! <a href="' . admin_url('plugin-install.php?s=WooCommerce+Stripe+Gateway&tab=search&type=term') . '">Nainštalovať</a></p></div>';
    }
});

require_once 'class-oppio-account-view.php';

new OPPIO_ACCOUNT_VIEW();
new OPPIO_Stripe_Status();
new OPPIO_ACCOUNT_ACTIONS();
new OPPIO_SCA_FLOW();


class OPPIO_Subscription_Manager {

    /**
     * Custom logger pre OPPIO plugin - zapisuje do plugin priečinka
     */
    private function oppio_log($message) {
        $log_file = plugin_dir_path(__FILE__) . 'oppio-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    private function oppio_renew_log($message) {
        $log_file = plugin_dir_path(__FILE__) . 'oppio-renew-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    private function renew_log($message) {
        $log_file = plugin_dir_path(__FILE__) . 'renew-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Pridaj AJAX handlery do __construct()
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // ✅ SKRIPTY - NAJVYŠŠIA PRIORITA
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 5);
        // add_action('wp_enqueue_scripts', array($this, 'enqueue_account_scripts'), 10);
        
        // ✅ VŠETKY AJAX HANDLERY
        add_action('wp_ajax_check_cart_status', array($this, 'ajax_check_cart_status'));
        add_action('wp_ajax_nopriv_check_cart_status', array($this, 'ajax_check_cart_status'));
        add_action('wp_ajax_convert_cart_subscription', array($this, 'ajax_convert_cart_subscription'));
        add_action('wp_ajax_nopriv_convert_cart_subscription', array($this, 'ajax_convert_cart_subscription'));
        add_action('wp_ajax_switch_cart_subscription', array($this, 'handle_cart_subscription_popup_switch'));
        add_action('wp_ajax_nopriv_switch_cart_subscription', array($this, 'handle_cart_subscription_popup_switch'));
        
        // Pridaj subscription stĺpec do orders admin tabuľky
        add_action('init', array($this, 'add_subscription_column_to_orders'));
        
        // Hook pre admin nastavenia webhook secrets
        add_action('admin_menu', array($this, 'add_webhook_admin_menu'));
        
        // Zakáž Stripe express checkout pre non-subscription
        add_action('wp', array($this, 'disable_stripe_express_for_non_subscription'));
        
        // Hook pre zobrazenie Stripe mode notifikácie
        add_action('admin_notices', array($this, 'show_stripe_mode_notice'));
        
        // ✅ ZABLOKUJ VŠETKY NEW ACCOUNT EMAILY
        add_filter('woocommerce_email_enabled_customer_new_account', '__return_false');
        
        // ✅ POŠLI VLASTNÝ EMAIL PO VYTVORENÍ OBJEDNÁVKY
        add_action('woocommerce_checkout_order_processed', [$this, 'send_account_email_after_order'], 10, 3);

        // Pre template access k plugin inštancii
        global $oppio_subscription_manager;
        $oppio_subscription_manager = $this;
        // Template override
        // ✅ NOVÉ hooky THANK YOU PAGE
        add_filter('template_include', array($this, 'override_thankyou_template'), 1);
        add_filter('body_class', array($this, 'add_custom_thankyou_body_class'));

        add_action('wp_ajax_oppio_create_subscription', array($this, 'ajax_create_subscription'));
        add_action('wp_ajax_nopriv_oppio_create_subscription', array($this, 'ajax_create_subscription'));

        // Načítaj subscription.js + pošli mu potrebné premenné (checkout + thankyou)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_subscription_assets'));

        // Vymeníme thankyou šablónu len pri subscription objednávke
        add_filter('template_include', array($this, 'maybe_use_subscription_thankyou_template'), 99);

        // Log payment errors
        add_action('wp_ajax_oppio_log_payment_error', array($this, 'ajax_log_payment_error'));
        add_action('wp_ajax_nopriv_oppio_log_payment_error', array($this, 'ajax_log_payment_error'));

        // Dokončiť platbu
        // add_action('wp_enqueue_scripts', [$this, 'enqueue_retry_assets']);

        // add_action('wp_ajax_oppio_retry_subscription',        [$this, 'ajax_retry_subscription']);
        // add_action('wp_ajax_nopriv_oppio_retry_subscription', [$this, 'ajax_retry_subscription']);

        add_action('wp_ajax_oppio_pay_invoice_after_si',        [$this, 'ajax_pay_invoice_after_si']);
        add_action('wp_ajax_nopriv_oppio_pay_invoice_after_si', [$this, 'ajax_pay_invoice_after_si']);

        add_action('wp_ajax_oppio_after_pi_confirm', [$this, 'ajax_after_pi_confirm']);
        // END Dokončiť platbu


        // Oprava order ak nemam ID
        add_action('wp_ajax_oppio_fix_order',        [$this, 'ajax_oppio_fix_order']);
        add_action('wp_ajax_nopriv_oppio_fix_order', [$this, 'ajax_oppio_fix_order']);

        // vrat dokoncenie 3ds
        add_action('wp_ajax_oppio_confirm_pi',        [$this, 'ajax_oppio_confirm_pi']);
        add_action('wp_ajax_nopriv_oppio_confirm_pi', [$this, 'ajax_oppio_confirm_pi']);

    }

    public function ajax_oppio_fix_order() {
        // 0) Vstupy
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        if ($order_id <= 0) { wp_send_json_error(['message' => 'Chýba order_id']); }

        $order = wc_get_order($order_id);
        if (!$order) { wp_send_json_error(['message' => 'Objednávka neexistuje']); }

        // 1) Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if ($api_key === '') { wp_send_json_error(['message' => 'Chýba Stripe API key']); }

        $headers = [
            'Authorization'  => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20',
        ];

        // 2) Ak už máme sub_id v objednávke, vezmeme ho. Inak ho ideme nájsť.
        $sub_id = (string) $order->get_meta('_oppio_stripe_subscription_id');

        // 3) Skús nájsť subscription cez Stripe SEARCH podľa metadata["woocommerce_order_id"]
        if ($sub_id === '') {
            $query = 'metadata["woocommerce_order_id"]:"' . $order_id . '"';
            $url   = 'https://api.stripe.com/v1/subscriptions/search?query=' . rawurlencode($query) . '&limit=1';

            $resp = wp_remote_get($url, ['headers' => $headers, 'timeout' => 30]);
            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);

            if ($code === 200) {
                $data = json_decode($body, true);
                $sub  = $data['data'][0] ?? [];
                if (!empty($sub['id'])) {
                    $sub_id = $sub['id'];
                    $order->update_meta_data('_oppio_stripe_subscription_id', $sub_id);
                    if (!empty($sub['status'])) {
                        $order->update_meta_data('_oppio_subscription_status', strtolower(trim((string)$sub['status'])));
                    }
                    $order->save();
                }
            }
        }

        // 4) Ak stále nič, fallback: nájdi zákazníka podľa emailu -> pozri jeho faktúry -> z nich zober subscription
        if ($sub_id === '') {
            $billing_email = $order->get_billing_email();
            if ($billing_email !== '') {
                $cust_url = 'https://api.stripe.com/v1/customers/search?query=' . rawurlencode('email:"' . $billing_email . '"') . '&limit=1';
                $c = wp_remote_get($cust_url, ['headers' => $headers, 'timeout' => 30]);
                if ((int) wp_remote_retrieve_response_code($c) === 200) {
                    $cust = json_decode(wp_remote_retrieve_body($c), true);
                    $cus_id = (string)($cust['data'][0]['id'] ?? '');
                    if ($cus_id !== '') {
                        $inv_url = 'https://api.stripe.com/v1/invoices?customer=' . rawurlencode($cus_id) . '&limit=10';
                        $i = wp_remote_get($inv_url, ['headers' => $headers, 'timeout' => 30]);
                        if ((int) wp_remote_retrieve_response_code($i) === 200) {
                            $invoices = json_decode(wp_remote_retrieve_body($i), true)['data'] ?? [];
                            foreach ($invoices as $inv) {
                                if (!empty($inv['subscription'])) {
                                    $sub_id = (string)$inv['subscription'];
                                    $order->update_meta_data('_oppio_stripe_subscription_id', $sub_id);
                                    $order->save();
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($sub_id === '') {
            wp_send_json_error(['message' => 'Nepodarilo sa nájsť subscription pre túto objednávku.']);
        }

        // 5) Načítaj subscription s expand → latest_invoice.payment_intent (aby sme mali aj SCA link)
        $sub_url = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($sub_id) . '?expand[]=latest_invoice.payment_intent';
        $sresp   = wp_remote_get($sub_url, ['headers' => $headers, 'timeout' => 30]);
        $scode   = (int) wp_remote_retrieve_response_code($sresp);
        $sbody   = wp_remote_retrieve_body($sresp);
        if ($scode !== 200) {
            wp_send_json_error(['message' => 'Stripe vrátil chybu pri čítaní subscription (' . $scode . ')']);
        }
        $sub = json_decode($sbody, true);

        // 6) Ulož všetko podstatné do objednávky
        $status     = isset($sub['status']) ? strtolower(trim((string)$sub['status'])) : '';
        $invoice_id = $sub['latest_invoice']['id'] ?? '';
        $pi         = $sub['latest_invoice']['payment_intent'] ?? [];
        $pi_id      = $pi['id'] ?? '';
        $pi_secret  = $pi['client_secret'] ?? '';
        $sca_url    = $pi['next_action']['redirect_to_url']['url'] ?? '';

        if ($status !== '')    { $order->update_meta_data('_oppio_subscription_status', $status); }
        if ($invoice_id !== ''){ $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id); }
        if ($pi_id !== '')     { $order->update_meta_data('_oppio_stripe_pi_id', $pi_id); }
        if ($pi_secret !== '') { $order->update_meta_data('_oppio_stripe_payment_intent_secret', $pi_secret); }
        if ($sca_url !== '')   { $order->update_meta_data('_oppio_sca_redirect_url', $sca_url); }
        $order->save();
        $order->add_order_note('🔧 OPPIO: doplnené subscription meta z API (sub_id: ' . $sub_id . ').');

        // 7) Hotovo – odpoveď v JSON
        wp_send_json_success([
            'order_id'        => $order_id,
            'subscription_id' => $sub_id,
            'status'          => $status,
            'invoice_id'      => $invoice_id,
            'pi_id'           => $pi_id,
            'sca_url'         => $sca_url,
            'message'         => 'OK – meta uložené. Obnov "Moje predplatné" a uvidíš tlačidlo.'
        ]);
    }

    /**
     * AJAX: Skontroluj status košíka
     */
    public function ajax_check_cart_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'subscription_nonce')) {
            wp_send_json_error(array('message' => 'Neplatný nonce'));
        }

        $cart = WC()->cart;
        $cart_has_items = $cart && !$cart->is_empty();

        wp_send_json_success(array(
            'cart_has_items' => $cart_has_items,
            'cart_count' => $cart ? $cart->get_cart_contents_count() : 0
        ));
    }

    /**
     * AJAX: Konvertuj košík na subscription typ - VYLEPŠENÁ VERZIA
     */
    public function ajax_convert_cart_subscription() {
        if (!wp_verify_nonce($_POST['nonce'], 'subscription_nonce')) {
            wp_send_json_error(array('message' => 'Neplatný nonce'));
        }
        
        $subscription_type = sanitize_text_field($_POST['subscription_type']);
        $cart = WC()->cart;
        
        if (!$cart || $cart->is_empty()) {
            WC()->session->set('current_subscription_type', $subscription_type === 'none' ? 'none' : $subscription_type);
            wp_send_json_success(array('message' => __('Košík je prázdny - nič na konverziu', 'oppio-subscriptions')));
            return;
        }
        
        /*
        $items_converted = 0;

        // === 1) Nájsť položky so SKU '12' (vrátane variácií aj parenta) ===
        $keys_to_remove = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id   = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];
            $product      = wc_get_product($product_id);

            $sku_match = false;
            if ( $product && (string) $product->get_sku() === '12' ) {
                $sku_match = true;
            } else {
                // fallback: skontroluj parent produkt (ak ide o variáciu bez SKU)
                if ( !empty($cart_item['variation_id']) ) {
                    $parent_product = wc_get_product( (int) $cart_item['product_id'] );
                    if ( $parent_product && (string) $parent_product->get_sku() === '12' ) {
                        $sku_match = true;
                    }
                }
            }

            if ( $sku_match && $subscription_type !== 'none' ) {
                $keys_to_remove[] = $cart_item_key;
            }
        }
            */

        // === 2) Konverzia položiek podľa $subscription_type ===
        $items_converted = 0;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

            // Ak ide o subscription a táto položka je SKU 12 → preskočíme konverziu (odstránime ju nižšie)
            // if ( in_array($cart_item_key, $keys_to_remove, true) ) {
            //     continue;
            // }

            if ( $subscription_type === 'none' ) {
                // odstráň subscription flag z položky
                if ( isset($cart->cart_contents[$cart_item_key]['subscription_type']) ) {
                    unset($cart->cart_contents[$cart_item_key]['subscription_type']);
                    $items_converted++;
                }
            } else {
                // nastav subscription typ na ostatné produkty
                $cart->cart_contents[$cart_item_key]['subscription_type'] = $subscription_type;
                $items_converted++;
            }
        }

        // === 3) Odstráň položky so SKU 12 (len ak ide o subscription režim) ===
        if ( !empty($keys_to_remove) && $subscription_type !== 'none' ) {
            foreach ( $keys_to_remove as $key ) {
                $cart->remove_cart_item($key);
            }
        }

        // ✅ KRITICKÉ: Force save košíka do session
        // WC()->session->set('cart', $cart->get_cart_for_session());
        // WC()->session->save_data();

        // WC()->session->set('current_subscription_type', $subscription_type === 'none' ? 'none' : $subscription_type);
        // $cart->calculate_totals();

        // === 4) Uložiť a prepočítať košík ===
        WC()->session->set('current_subscription_type', $subscription_type === 'none' ? 'none' : $subscription_type);
        WC()->cart->set_session();
        $cart->calculate_totals();

        $labels = array(
            'none'      => __('Jednorázový nákup', 'oppio-subscriptions'),
            'monthly'   => __('Mesačné predplatné', 'oppio-subscriptions'),
            'biweekly'  => __('14-dňové predplatné', 'oppio-subscriptions'),
            'daily'     => __('Denné predplatné', 'oppio-subscriptions'),
        );
        
        $type_label = $labels[$subscription_type] ?? $subscription_type;
        
        if ($items_converted > 0) {
            $message = sprintf(__('Košík konvertovaný na: %1$s (%2$d produktov)', 'oppio-subscriptions'), $type_label, $items_converted);
        } else {
            $message = sprintf(__('Typ nákupu nastavený na: %s', 'oppio-subscriptions'), $type_label);
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'subscription_type' => $subscription_type,
            'items_converted' => $items_converted
        ));
    }

    /**
     * Deklaruje HPOS kompatibilitu
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables', 
                __FILE__, 
                true
            );
        }
    }

    public function init() {
        // Shortcode pre zobrazenie subscription options
        add_shortcode('oppio_subscription_options', array($this, 'display_subscription_options_shortcode'));

        // Hook na add to cart handling
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_subscription_data_to_cart'), 10, 3);

        // Hook na zobrazenie subscription info v košíku
        add_filter('woocommerce_get_item_data', array($this, 'display_subscription_data_in_cart'), 10, 2);

        // Hook na skupinové zobrazenie v košíku
        add_filter('woocommerce_cart_item_name', array($this, 'modify_cart_display'), 10, 3);

        // Hook pre custom AJAX handler
        $this->custom_ajax_add_to_cart_handler();

        // Hook na WooCommerce cart add item - košík synchronizácia
        add_filter('woocommerce_add_cart_item', array($this, 'add_subscription_to_cart_item'), 10, 2);

        // Hook na zobrazenie subscription summary v košíku
        add_action('woocommerce_before_cart_table', array($this, 'display_cart_subscription_summary'));
        
        // Hook na zobrazenie subscription switch v košíku
        add_action('woocommerce_before_cart_table', array($this, 'display_cart_subscription_switch'), 5);

        // Hook na aplikáciu subscription zľavy
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_subscription_discount'));

        // Hook na filtrovanie payment gateways pre subscription
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways'));

        // ====== STRIPE SUBSCRIPTION HOOKS ======

        // Hook na modifikáciu Stripe payment intent pre subscription
        add_filter('wc_stripe_payment_intent_args', array($this, 'modify_stripe_payment_intent'), 10, 2);

        // Hook na vytvorenie Stripe subscription po platbe
        add_action('woocommerce_payment_complete', array($this, 'create_stripe_subscription'), 10, 1);

        // Hook na pridanie subscription metadata do order - SKORŠÍ HOOK
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_subscription_line_item_meta'), 10, 4);

        // Hook na zachovanie subscription data v order items
        add_action('woocommerce_checkout_create_order', array($this, 'add_subscription_order_meta'), 10, 2);

        // Hook na modifikáciu order item display
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'customize_order_item_meta_display'), 10, 3);
        add_filter('woocommerce_order_item_display_meta_value', array($this, 'customize_order_item_meta_value'), 10, 3);

        // Hook na handling recurring payments
        // add_action('scheduled_subscription_payment_stripe', array($this, 'process_scheduled_payment'), 10, 2);

        // Hook pre save payment method pre subscriptions
        add_filter('wc_stripe_force_save_payment_method', array($this, 'force_save_payment_method'), 10, 2);

        // Hook na vyčistenie session keď sa košík vyprázdni
        add_action('woocommerce_cart_emptied', array($this, 'clear_subscription_session'));
        add_action('woocommerce_before_cart', array($this, 'maybe_clear_subscription_session'));

        // FREE SHIPPING pre SUBSCRIBE
        add_filter('woocommerce_shipping_free_shipping_is_available', array($this, 'enable_free_shipping_for_subscription'), 20, 3);

        // SHORTCODE VZDY FRESH
        add_action('wp_ajax_nopriv_oppio_load_subscription_block', 'oppio_render_subscription_ajax');
        add_action('wp_ajax_oppio_load_subscription_block', 'oppio_render_subscription_ajax');
    }

    /**
     * Vyčistí subscription session keď je košík prázdny
     */
    public function clear_subscription_session() {
        WC()->session->set('current_subscription_type', null);
        WC()->session->set('pending_subscription_type', null);
        // $this->oppio_log('OPPIO: Cleared subscription session - cart emptied');
    }

    /**
     * Možno vyčistí subscription session ak košík neobsahuje subscription items
     */
    public function maybe_clear_subscription_session() {
        $cart = WC()->cart;

        if (!$cart || $cart->is_empty()) {
            $this->clear_subscription_session();
            return;
        }

        // Skontroluj či košík má subscription items
        $has_subscription = false;
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['subscription_type'])) {
                $has_subscription = true;
                break;
            }
        }

        // Ak košík nemá subscription items, vyčisti session
        if (!$has_subscription) {
            $this->clear_subscription_session();
        }
    }

    /**
     * Shortcode pre zobrazenie subscription options - fakedropdown
     */
    public function display_subscription_options_shortcode($atts) {
        if ( null === WC()->session || ! WC()->cart ) {
            if ( function_exists('wc_load_cart') ) {
                wc_load_cart();
            }
        }


        $this->oppio_log('--- DEBUG OTVORENIE SHORTCODE ---');
        $this->oppio_log('SESSION subscription: ' . WC()->session->get('current_subscription_type'));
        $this->oppio_log('CART subscription: ' . $this->get_existing_subscription_type_from_cart());

        $atts = shortcode_atts(array(
            'product_id' => get_the_ID()
        ), $atts);
        
        $product_id = intval($atts['product_id']);
        $product    = $product_id ? wc_get_product($product_id) : null;
        
        if (!$product || !$product->is_type('simple')) {
            return '<p>'. __('Subscription options sú dostupné iba pre simple produkty', 'oppio-subscriptions') .'.</p>';
        }
        
        $regular_price = $product->get_regular_price();
        if (empty($regular_price)) {
            return '<p>'. __('Produkt musí mať nastavenú cenu', 'oppio-subscriptions') .'</p>';
        }

        // ✅ FORCE NAČÍTANIE CART A SESSION PRED POUŽITÍM
        if (WC()->cart && WC()->session) {
            // ✅ PRIDAJ AGRESÍVNE CACHE ČISTENIE
            $customer_id = WC()->session->get_customer_id();
            wp_cache_delete("cart_$customer_id", 'woocommerce');
            wp_cache_delete("cart_contents_$customer_id", 'woocommerce');
            
            // Force refresh cart z session
            WC()->cart->get_cart_from_session();
            
            // ✅ FORCE RELOAD CART CONTENTS
            WC()->cart->set_cart_contents(WC()->session->get('cart', array()));
            
            // Počkaj na načítanie
            if (!WC()->cart->is_empty()) {
                // Cart je načítaný, pokračuj
                $this->oppio_log('Cart loaded with ' . WC()->cart->get_cart_contents_count() . ' items');
            }
        }

        // Aktuálne subscription typ
        $session_subscription = WC()->session->get('current_subscription_type');
        $cart_subscription = $this->get_existing_subscription_type_from_cart();
        $cart_has_one_time_only = $this->cart_has_only_one_time_products();

        if (!empty($session_subscription)) {
            $current_subscription = $session_subscription;
            $this->oppio_log('Using SESSION subscription: ' . $current_subscription);
        } 
        elseif (!empty($cart_subscription)) {
            $current_subscription = $cart_subscription;
            $this->oppio_log('Using fallback: cart subscription = ' . $cart_subscription);
        } 
        elseif (WC()->cart && WC()->cart->is_empty()) {
            $current_subscription = 'biweekly'; // ← TU JE NOVÉ DEFAULT NASTAVENIE PRE EMPTY CART
            $this->oppio_log('Using fallback: cart is empty – defaulting to biweekly');

            if ( WC()->session ) {
                WC()->session->set('current_subscription_type', 'biweekly');
                if ( method_exists(WC()->session, 'save_data') ) { WC()->session->save_data(); }
            }
        }
        else {
            $current_subscription = 'none';
            $this->oppio_log('Using fallback: DEFAULT none');
        }

        // Košík info pre zobrazenie
        $cart = WC()->cart;
        $cart_has_items = $cart && !$cart->is_empty();

        // Checked states na základe aktuálneho subscription typu
        $checked_none       = ($current_subscription === 'none');
        $checked_monthly    = ($current_subscription === 'monthly');
        $checked_biweekly   = ($current_subscription === 'biweekly');
        $checked_daily      = ($current_subscription === 'daily');

        // ✅ NOVÉ: Sekcie active states
        $section1_active = ($checked_none) ? 'active' : '';
        $section2_active = ($checked_monthly || $checked_biweekly || $checked_daily) ? 'active' : '';

        // Výpočet cien s zľavami
        $monthly_price = $regular_price * 0.85; // 15% zľava
        $biweekly_price = $regular_price * 0.80; // 20% zľava

        // Aktuálna cena na základe typu
        $current_savings = 20;  // ← DEFAULT 20% - WEEKLY
        $current_price = $biweekly_price;  // ← DEFAULT biweekly cena
        
        if ($current_subscription === 'monthly') {
            $current_price = $monthly_price;
            $current_savings = 15;
        } 
        elseif ($current_subscription === 'biweekly') {
            $current_price = $biweekly_price;
            $current_savings = 20;
        }

        // $current_subscription_type = $this->get_existing_subscription_type_from_cart();
        
        ob_start();
        ?>
            <div class="oppio-purchase-options" style="margin: 20px 0;" data-default-subscription="<?php echo esc_attr($current_subscription); ?>">
                <h3 class="purchase-options-title"><?php echo __('Možnosti objednávky', 'oppio-subscriptions'); ?></h3>

                <!-- One Time Purchase -->
                <div class="purchase-option one-time <?php echo $section1_active; ?>">
                    
                    <label>
                        
                        <input type="radio" name="subscription_type" value="none" <?php checked($checked_none); ?> style="margin-right: 10px;">
                        <span class="option-content">
                            <span class="option-title">
                                <?php echo __('Jednorázový nákup', 'oppio-subscriptions'); ?>
                                <?php /* if ( $product && ( (string) $product->get_sku() !== '12' ) ) : ?>
                                    <span class="min-pieces">
                                        <?php echo __('min. počet', 'oppio-subscriptions');?> 
                                        <span>12 ks</span>
                                    </span>
                                <?php endif; */ ?>
                            </span>

                            <span class="option-price"><?php echo wc_price($regular_price); ?></span>
                            
                        </span>
                    </label>
                </div>

                <?php
                    // Skryť shortcode pre produkt s ID 7291 alebo SKU SKU 12
                    // podmienka na zobrazenie sekcie
                    // if ( $product && ( (string) $product->get_sku() !== '12' ) ) :
                ?>

                    <!-- Subscribe and Save -->
                    <div class="purchase-option subscription <?php echo $section2_active; ?>">
                        <label>
                            <?php /* <input type="radio" name="subscription_type" value="subscription" <?php checked($checked_subscription); ?>> */ ?>
                            <span class="option-content">
                                <div class="subscription-header">
                                    
                                    <div class="option-title-wrapper">
                                        <div class="fake__radio-button"></div>
                                        <span class="option-title"><?php echo __('Predplatné', 'oppio-subscriptions');?></span>
                                        <span class="save-badge"><?php echo __('Ušetri', 'oppio-subscriptions');?> <?php echo $current_savings; ?>%</span>

                                        <?php /* if ( $product && ( (string) $product->get_sku() !== '12' ) ) : ?>
                                            <span class="min-pieces"><?php echo __('min. počet', 'oppio-subscriptions');?> <span>12 ks</span></span>
                                        <?php endif; */ ?>
                                    </div>

                                    <div class="price-display">
                                        <span class="current-price"><?php echo wc_price($current_price); ?></span>
                                        <?php if ($current_savings > 0): ?>
                                            <span class="original-price"><?php echo wc_price($regular_price); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="delivery-frequency">
                                    <label class="frequency-label"><?php echo __('Frekvencia doručenia', 'oppio-subscriptions'); ?></label>

                                    <div class="simulate__selected-subscribe"></div>

                                    <section class="simulate__select">
                                        <!-- 14-dňové predplatné -->
                                        <label>
                                            <input type="radio" name="subscription_type" value="biweekly" <?php checked($checked_biweekly); ?> style="margin-right: 10px;">
                                            <strong><?php echo __('14-dňové predplatné', 'oppio-subscriptions'); ?></strong> 
                                            <!-- - <?php echo wc_price($biweekly_price); ?> -->
                                            <!-- <small style="color: green; display: block; margin-left: 25px;">✓ Ušetríte 20% + automatické doručovanie</small> -->
                                        </label>

                                        <!-- Mesačné predplatné -->
                                        <label>
                                            <input type="radio" name="subscription_type" value="monthly" <?php checked($checked_monthly); ?> style="margin-right: 10px;">
                                            <strong><?php echo __('Mesačné predplatné', 'oppio-subscriptions'); ?></strong> 
                                            <!-- - <?php echo wc_price($monthly_price); ?> -->
                                            <!-- <small style="color: green; display: block; margin-left: 25px;">✓ Ušetríte 15% + automatické doručovanie</small> -->
                                        </label>

                                        <?php 
                                        // if ( is_user_logged_in() && current_user_can( 'administrator' ) ) :
                                        if ( is_user_logged_in() && (current_user_can( 'administrator' ) || wp_get_current_user()->user_email === 'juraj@sin.sk') ) : ?>
                                            <!-- Denné predplatné -->
                                            <label>
                                                <input type="radio" name="subscription_type" value="daily" <?php checked($checked_daily); ?> style="margin-right: 10px;">
                                                <strong>Denné predplatné TEST</strong>
                                                <!-- - <?php echo wc_price($regular_price); ?> -->
                                                <!-- <small style="color: #666; display: block; margin-left: 25px;">Testovací režim - bez zľavy</small> -->
                                            </label>
                                        <?php endif; ?>

                                    </section>
                                </div>
                            </span>
                        </label>
                    </div>

                <?php 
                // endif; 
                ?>

                <!-- Hidden inputs pre kompatibilitu -->
                <input type="hidden" id="product_regular_price" value="<?php echo esc_attr($regular_price); ?>">
                <input type="hidden" id="current_product_id" value="<?php echo esc_attr($product_id); ?>">  
                <input type="hidden" id="current_cart_subscription" value="<?php echo esc_attr($current_subscription ?: 'biweekly'); ?>">
                <input type="hidden" id="actual_subscription_type" value="<?php echo esc_attr($current_subscription ?: 'biweekly'); ?>">
            </div>

            <!-- Tooltip s výhodami predplatného -->
            <div class="subscription-benefits-container" tabindex="0" role="button" aria-describedby="benefits-tooltip">
                <div class="benefits-trigger">
                    <div class="benefits-icon">
                        <img
                            src="https://oppio.sk/wp-content/uploads/2025/08/subscribe_1753980125-trimmy-ChatGPT_Image_Jul_31__2025__06_38_47_PM-removebg-preview1.png"
                            alt="Icona Oppio Subscribe" 
                            title="Icona Oppio Subscribe"
                        >
                    </div>
                    <span class="benefits-text"><?php echo __('Výhody predplatného:', 'oppio-subscriptions'); ?></span>
                </div>

                <div class="benefits-tooltip" id="benefits-tooltip" role="tooltip">
                    <div class="tooltip-content">
                        <ul>
                            <li><?php _e('Až 20% zľava na všetky produkty', 'oppio'); ?></li>
                            <li><?php _e('Doprava zadarmo pri každej dodávke', 'oppio'); ?></li>
                            <li><?php _e('Flexibilná frekvencia dodania', 'oppio'); ?></li>
                            <li><?php _e('Možnosť zmeny alebo zrušenia kedykoľvek', 'oppio'); ?></li>
                            <!-- <li><?php _e('Kvalitná kombucha OPPIO', 'oppio'); ?></li> -->
                        </ul>
                    </div>
                </div>
            </div>

            <?php if (WP_DEBUG): ?>
                <script>
                    // ✅ DETAILNÝ DEBUG
                    console.log('=== DETAILED SUBSCRIPTION DEBUG ===');
                    console.log('Cart subscription:', '<?php echo $cart_subscription ?: 'empty'; ?>');
                    console.log('Session subscription:', '<?php echo $session_subscription ?: 'empty'; ?>');
                    console.log('Cart has one-time only:', <?php echo $cart_has_one_time_only ? 'true' : 'false'; ?>);
                    console.log('Final subscription:', '<?php echo $current_subscription ?: 'biweekly'; ?>');
                    console.log('Cart has items:', <?php echo $cart_has_items ? 'true' : 'false'; ?>);
                    console.log('Cart items count:', <?php echo $cart ? $cart->get_cart_contents_count() : 0; ?>);
                    
                    // ✅ ZOBRAZ OBSAH KOŠÍKA
                    <?php if ($cart && !$cart->is_empty()): ?>
                        console.log('--- CART CONTENTS ---');
                        <?php foreach ($cart->get_cart() as $key => $item): ?>
                            console.log('Item <?php echo substr($key, 0, 8); ?>: subscription_type = <?php echo isset($item["subscription_type"]) ? $item["subscription_type"] : "NONE"; ?>');
                        <?php endforeach; ?>
                    <?php else: ?>
                        console.log('Cart is empty or not loaded');
                    <?php endif; ?>
                    
                    // ✅ ZOBRAZ SESSION INFO
                    <?php 
                        $all_session = WC()->session->get_session_data();
                        if (!empty($all_session)): ?>
                            console.log('--- SESSION DATA ---');
                            console.log('Session current_subscription_type:', '<?php echo WC()->session->get('current_subscription_type') ?: 'NULL'; ?>');
                            console.log('Session pending_subscription_type:', '<?php echo WC()->session->get('pending_subscription_type') ?: 'NULL'; ?>');
                        <?php endif; ?>
                        
                        console.log('=== END DEBUG ===');
                    </script>

                    <script>
                        document.addEventListener("DOMContentLoaded", function () {
                            setTimeout(function () {
                                if (typeof window.updateShippingProgress === "function") {
                                    console.log("✅ DEBUG: Calling updateShippingProgress() from WP_DEBUG");
                                    window.updateShippingProgress();
                                } else {
                                    console.warn("❌ updateShippingProgress not defined");
                                }

                                if (typeof window.shippingProgressData !== "undefined") {
                                    console.log("✅ shippingProgressData is available");
                                } else {
                                    console.warn("❌ shippingProgressData is undefined");
                                }
                            }, 1000);
                        });
                    </script>

            <?php endif; ?>

        <?php
        return ob_get_clean();
    }

    /**
     * Skontroluje či košík obsahuje iba jednorázové produkty (bez subscription_type)
     */
    private function cart_has_only_one_time_products() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return false;
        }
        
        $has_items = false;
        foreach ($cart->get_cart() as $cart_item) {
            $has_items = true;
            
            // Ak nájdeme akýkoľvek item s subscription_type, nie je to "only one time"
            if (isset($cart_item['subscription_type'])) {
                return false;
            }
        }
        
        // Vracia true iba ak košík má items ALE žiadny nemá subscription_type
        return $has_items;
    }

    /**
     * Pridá JavaScript pre handling radio buttonovň
     * subscription.js enqueued successfully
     */
    public function enqueue_scripts() {
        // ✅ 1. CONDITIONAL LOADING - len kde treba
        if (!is_product() && !is_cart() && !is_checkout() && !$this->has_subscription_shortcode()) {
            return; // Nevčítaj script ak nie je potrebný
        }
        
        // ✅ 2. FILE PATH CHECK
        $script_path = plugin_dir_path(__FILE__) . 'subscription.js';
        $script_url = plugin_dir_url(__FILE__) . 'subscription.js';
        
        // ✅ 3. SKONTROLUJ ČI SÚBOR EXISTUJE
        if (!file_exists($script_path)) {
            $this->oppio_log('ERROR: subscription.js not found at: ' . $script_path);
            return;
        }
        
        // ✅ 4. SPRÁVNY VERSIONING - filemtime namiesto time()
        $version = filemtime($script_path);
        
        // ✅ 5. DEBUG INFO
        // $this->oppio_log('Loading subscription.js on: ' . $_SERVER['REQUEST_URI']);
        // $this->oppio_log('Script path: ' . $script_path);
        // $this->oppio_log('Script version: ' . $version);
        
        // ✅ 6. ENQUEUE S PROPER VERSION
        wp_enqueue_script(
            'oppio-subscription-js', 
            $script_url, 
            array('jquery'), 
            $version, 
            true
        );
        
        // ✅ 7. LOCALIZE SCRIPT
        wp_localize_script('oppio-subscription-js', 'oppio_subscription_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subscription_nonce'),
            'debug' => WP_DEBUG ? 'true' : 'false'
        ));
        
        // $this->oppio_log('subscription.js enqueued successfully');
    }

    /**
     * Helper function pre shortcode check
     */
    private function has_subscription_shortcode() {
        global $post;
        
        if (!$post) {
            return false;
        }
        
        return has_shortcode($post->post_content, 'oppio_subscription_options');
    }

    /**
     * Hook do custom AJAX add to cart handler
     */
    public function custom_ajax_add_to_cart_handler() {
        add_action('wp_ajax_woocommerce_ajax_add_to_cart', array($this, 'modify_custom_add_to_cart'), 5);
        add_action('wp_ajax_nopriv_woocommerce_ajax_add_to_cart', array($this, 'modify_custom_add_to_cart'), 5);
    }

    public function modify_custom_add_to_cart() {
        if (isset($_POST['subscription_type'])) {
            $subscription_type = sanitize_text_field($_POST['subscription_type']);

            // ✅ ulož do session iba ak NIE JE 'none'
            if ($subscription_type !== 'none') {
                WC()->session->set('pending_subscription_type', $subscription_type);
            } else {
                WC()->session->set('pending_subscription_type', null);
            }

            // Debug log
            $this->oppio_log('OPPIO AJAX: Received subscription_type: ' . $subscription_type);
            $this->oppio_log('OPPIO AJAX: Pending subscription set to: ' . WC()->session->get('pending_subscription_type'));
            return;
        }

        // ⛳️ Keď téma nepošle subscription_type, nastav bezpečný default
        $cart = WC()->cart;
        $type = (function_exists('WC') && WC()->session) ? WC()->session->get('current_subscription_type') : null;

        if (empty($type)) {
            if (!$cart || $cart->is_empty()) {
                $type = 'biweekly';
            } else {
                $existing = $this->get_existing_subscription_type_from_cart();
                $type = $existing ? $existing : 'none';
            }
        }

        WC()->session->set('pending_subscription_type', ($type && $type !== 'none') ? $type : null);
        $this->oppio_log('OPPIO AJAX: subscription_type not posted; derived type: ' . $type);
    }

    /**
     * Pridá subscription data do cart item
     */
    public function add_subscription_data_to_cart($cart_item_data, $product_id, $variation_id) {
        $cart = WC()->cart;
        $posted_type = isset($_POST['subscription_type']) ? sanitize_text_field($_POST['subscription_type']) : null;

        if ($posted_type !== null) {
            // ✅ Kontrola duplikátov
            if ($cart && !$cart->is_empty()) {
                foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                    if ($cart_item['product_id'] == $product_id) {
                        $item_sub   = isset($cart_item['subscription_type']) ? $cart_item['subscription_type'] : 'none';
                        $target_sub = ($posted_type === 'none') ? 'none' : $posted_type;

                        if ($item_sub === $target_sub) {
                            $add_qty = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
                            $cart->set_quantity($cart_item_key, $cart_item['quantity'] + $add_qty);

                            if (wp_doing_ajax()) {
                                wp_send_json_success(['message' => 'Pripočítané do košíka']);
                            } else {
                                wp_redirect(wc_get_cart_url());
                                exit;
                            }
                        }
                    }
                }
            }

            if ($posted_type !== 'none') {
                $cart_item_data['subscription_type'] = $posted_type;
                WC()->session->set('current_subscription_type', $posted_type);
                WC()->session->set('pending_subscription_type', $posted_type);
            } else {
                WC()->session->set('current_subscription_type', 'none');
                WC()->session->set('pending_subscription_type', null);
            }
            return $cart_item_data;
        }

        // ⛳️ Žiadne pole `subscription_type` v požiadavke (typický problém AJAX Add-to-Cart z témy)
        // 1) Zober typ zo SESSION, ak je
        $type = (function_exists('WC') && WC()->session) ? WC()->session->get('current_subscription_type') : null;

        // 2) Ak nič a košík je prázdny → default 'biweekly'
        if (empty($type)) {
            if (!$cart || $cart->is_empty()) {
                $type = 'biweekly';
            } else {
                // 3) Ak košík nie je prázdny, drž sa aktuálneho typu v košíku
                $existing = $this->get_existing_subscription_type_from_cart();
                $type = $existing ? $existing : 'none';
            }
        }

        // 4) Aplikuj a zosúlaď session
        if ($type !== 'none') {
            $cart_item_data['subscription_type'] = $type;
        }
        WC()->session->set('current_subscription_type', $type);
        WC()->session->set('pending_subscription_type', ($type && $type !== 'none') ? $type : null);

        return $cart_item_data;
    }

    /**
    * Zobrazí subscription info v košíku
    */
    public function display_subscription_data_in_cart($item_data, $cart_item) {
        if (isset($cart_item['subscription_type'])) {
            $subscription_type = $cart_item['subscription_type'];

            $labels = array(
                'monthly'   => __('Mesačné predplatné', 'oppio-subscriptions'),
                'biweekly'  => __('14-dňové predplatné', 'oppio-subscriptions'),
                'daily'     => __('Denné predplatné', 'oppio-subscriptions'),
            );

            $item_data[] = array(
                'name'  => __('Typ nákupu', 'oppio-subscriptions'),
                'value' => $labels[$subscription_type] ?? 'Neznámy'
            );
        }
        return $item_data;
    }

    /**
     * Modifikuje zobrazenie v košíku
     */
    public function modify_cart_display($name, $cart_item, $cart_item_key) {
        if (isset($cart_item['subscription_type'])) {
            $subscription_type = $cart_item['subscription_type'];
            $period_labels = array(
                'monthly'   => __('mesačné', 'oppio-subscriptions'),
                'biweekly'  => __('14-dňové', 'oppio-subscriptions'),
                'daily'     => __('denne', 'oppio-subscriptions'),
            );

            $period = $period_labels[$subscription_type] ?? '';
            // $name = '<span class="oppio-subscription-icon">↻</span> ' . $name . ' <small>(' . $period . ' predplatné)</small>';
            $name = sprintf(
                __('<span class="oppio-subscription-icon">↻</span> %1$s <small>(%2$s predplatné)</small>', 'oppio-subscriptions'),
                $name,
                $period
            );
        }
        return $name;
    }

    /**
     * Košík synchronizácia - automaticky pridáva subscription type
     */
    public function add_subscription_to_cart_item($cart_item_data, $cart_item_key) {
        $pending_subscription = WC()->session->get('pending_subscription_type');
        
        // ✅ OPRAVENÉ - pridaj subscription_type iba ak existuje a nie je 'none'
        if ($pending_subscription && $pending_subscription !== 'none') {
            $cart_item_data['subscription_type'] = $pending_subscription;
            WC()->session->set('pending_subscription_type', null);
            // $this->oppio_log('OPPIO: Added pending subscription to cart: ' . $pending_subscription);
        } else {
            $existing_subscription_type = $this->get_existing_subscription_type_from_cart();
            if ($existing_subscription_type && $existing_subscription_type !== 'none') {
                $cart_item_data['subscription_type'] = $existing_subscription_type;
                $this->oppio_log('OPPIO: Added existing subscription to cart: ' . $existing_subscription_type);
            } else {
                $this->oppio_log('OPPIO: No subscription added to cart - keeping as one-time');
            }
        }
        
        return $cart_item_data;
    }

    /**
    * Zistí subscription typ z košíka
    */
    private function get_existing_subscription_type_from_cart() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            // $this->oppio_log('get_existing_subscription_type_from_cart: CART IS EMPTY');
            return false;
        }
        
        $found_subscription = false;
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            // $this->oppio_log('Cart item key: ' . $cart_item_key . ' - has subscription_type: ' . (isset($cart_item['subscription_type']) ? $cart_item['subscription_type'] : 'NO'));
            
            if (isset($cart_item['subscription_type'])) {
                $found_subscription = $cart_item['subscription_type'];
                break;
            }
        }
        
        // $this->oppio_log('get_existing_subscription_type_from_cart RESULT: ' . ($found_subscription ?: 'FALSE'));
        return $found_subscription;
    }

    /**
     * Zobrazí subscription popup pre one-time produkty v košíku - VŽDY!
     */
    public function display_cart_subscription_switch() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        // Skontroluj či košík obsahuje iba one-time produkty
        $current_subscription_type = $this->get_existing_subscription_type_from_cart();
        if ($current_subscription_type) {
            return; // Ak už má subscription, nič neukazuj
        }
        
        // ODSTRÁNENÉ: Kontrola transient - popup sa zobrazuje vždy pre one-time nákup!
        // Výpočty úspor
        $subtotal = $cart->get_subtotal();
        $shipping_cost = 4.99; // Štandardná doprava
        $discount_percent = 15;
        
        // [X] - Nová cena s predplatným
        $subscription_price = ($subtotal * (100 - $discount_percent) / 100);
        
        // [Z] - Ročná úspora  
        $monthly_savings = ($subtotal * $discount_percent / 100) + $shipping_cost;
        $yearly_savings = $monthly_savings * 12;
        
        ?>
        <!-- Popup overlay -->
        <div id="oppio-subscription-popup-overlay" style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        ">

            <?php
            $current_subscription = $this->get_existing_subscription_type_from_cart() ?: 'none';
            ?>
            <input type="hidden" id="actual_subscription_type" value="<?php echo esc_attr($current_subscription); ?>">

            <!-- Popup content -->
            <div id="oppio-subscription-popup" style="
                background: var(--project-light);
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                width: 90%;
                position: relative;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                transform: translateY(-30px);
                transition: transform 0.3s ease;
                text-align: center;
            ">
                <!-- Close button -->
                <button id="oppio-popup-close" style="
                    position: absolute;
                    top: 15px;
                    right: 20px;
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #999;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 50%;
                    transition: all 0.2s ease;
                " onmouseover="this.style.background='#f5f5f5'; this.style.color='#333';" onmouseout="this.style.background='none'; this.style.color='#999';">
                    ×
                </button>
                
                <!-- Header -->
                <div style="margin-bottom: 25px;">
                    <div style="
                        width: 60px;
                        height: 60px;
                        background: var(--project-green-light, #4CAF50);
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        margin: 0 auto 20px;
                        font-size: 24px;
                    ">
                        💰
                    </div>
                    <h2 style="
                        margin: 0;
                        color: #333;
                        font-size: 24px;
                        font-weight: 600;
                        line-height: 1.3;
                    ">
                        <?php _e('Ušetri s predplatným!', 'oppio-subscriptions'); ?>
                    </h2>
                </div>
                
                <!-- Main content -->
                <div style="margin-bottom: 30px; color: #555; font-size: 16px; line-height: 1.6;">
                    <p style="margin: 0 0 20px 0;">
                        <strong><?php _e('S naším výhodným predplatným by vás táto objednávka stála len ', 'oppio-subscriptions'); ?>
                        <span style="color: var(--project-green-light, #4CAF50); font-weight: 700; font-size: 18px;">
                            <?php echo wc_price($subscription_price); ?>
                        </span>.</strong>
                    </p>
                    
                    <p style="margin: 0 0 20px 0;">
                        <?php _e('To je úspora až ', 'oppio-subscriptions'); ?>
                        <span style="color: var(--project-green-light, #4CAF50); font-weight: 700;">
                            <?php echo wc_price($yearly_savings); ?><?php _e(' ročne!', 'oppio-subscriptions'); ?>
                        </span>
                    </p>
                    
                    <div style="
                        background: #f8f9fa;
                        padding: 20px;
                        border-radius: 8px;
                        margin: 20px 0;
                        border-left: 4px solid var(--project-green-light, #4CAF50);
                    ">
                        <p style="margin: 0; font-weight: 600; color: var(--project-green-light, #4CAF50);">
                            <?php _e('✓ Navyše s predplatným získate aj dopravu zadarmo!', 'oppio-subscriptions'); ?>
                        </p>
                    </div>
                    
                    <p style="margin: 0; font-size: 14px; color: #777;">
                        <?php _e('Predplatné možete kedykoľvek zrušiť alebo upraviť. 
                        Skúste to bez záväzkov – a ušetrite už dnes!', 'oppio-subscriptions'); ?>
                    </p>
                </div>
                
                <!-- Buttons -->
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button id="oppio-apply-subscription" style="
                        background: linear-gradient(135deg, var(--project-green-light, #4CAF50), var(--project-green-dark, #45a049));
                        color: white;
                        border: none;
                        padding: 15px 30px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: all 0.3s ease;
                        box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
                    " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(76, 175, 80, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(76, 175, 80, 0.3)';">
                        <?php _e('Aplikovať predplatné', 'oppio-subscriptions'); ?>
                    </button>
                    
                    <button id="oppio-popup-dismiss" style="
                        background: #f8f9fa;
                        color: #666;
                        border: 2px solid #e9ecef;
                        padding: 15px 25px;
                        border-radius: 8px;
                        font-size: 16px;
                        cursor: pointer;
                        transition: all 0.3s ease;
                    " onmouseover="this.style.borderColor='#ccc'; this.style.color='#333';" onmouseout="this.style.borderColor='#e9ecef'; this.style.color='#666';">
                        <?php _e('Nie, ďakujem', 'oppio-subscriptions'); ?>
                    </button>
                </div>
                
                <!-- Loading message -->
                <div id="oppio-popup-message" style="margin-top: 20px; display: none;"></div>
            </div>
        </div>
        
        <style>
        /* Popup animations */
        #oppio-subscription-popup-overlay.show {
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        #oppio-subscription-popup-overlay.show #oppio-subscription-popup {
            transform: translateY(0) !important;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            #oppio-subscription-popup {
                padding: 30px 20px !important;
                margin: 20px !important;
                width: calc(100% - 40px) !important;
            }
            
            #oppio-subscription-popup div[style*="display: flex"] {
                flex-direction: column !important;
            }
            
            #oppio-subscription-popup button {
                width: 100% !important;
                margin-bottom: 10px !important;
            }
        }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // OPRAVENÉ: Ukladaj do localStorage pre trvalé uloženie
                var popupKey = 'oppio_popup_dismissed_' + Date.now(); // Unique key pre každú cart session
                
                // Ak už existuje starší dismissed key pre aktuálny cart, použije sa
                var existingKey = localStorage.getItem('oppio_current_popup_key');
                if (existingKey && localStorage.getItem(existingKey)) {
                    console.log('Popup already dismissed for current cart session');
                    return;
                }
                
                // Nastaviť nový key pre túto cart session
                if (!existingKey) {
                    localStorage.setItem('oppio_current_popup_key', popupKey);
                } else {
                    popupKey = existingKey;
                }
                
                // Zobraziť popup po krátkom čakaní
                setTimeout(function() {
                    $('#oppio-subscription-popup-overlay').addClass('show');
                }, 1000);
                
                // Zatvorenie popup - X button
                $('#oppio-popup-close').on('click', function() {
                    closePopup();
                });
                
                // Zatvorenie popup - "Nie, ďakujem"
                $('#oppio-popup-dismiss').on('click', function() {
                    closePopup();
                });
                
                // Zatvorenie popup - klik mimo
                $('#oppio-subscription-popup-overlay').on('click', function(e) {
                    if (e.target === this) {
                        closePopup();
                    }
                });
                
                // Zatvorenie popup - ESC key
                $(document).on('keydown', function(e) {
                    if (e.keyCode === 27 && $('#oppio-subscription-popup-overlay').hasClass('show')) {
                        closePopup();
                    }
                });
                
                // Aplikovanie predplatného
                $('#oppio-apply-subscription').on('click', function() {
                    var button = $(this);
                    var messageDiv = $('#oppio-popup-message');
                    
                    // Disable tlačidlá
                    $('#oppio-apply-subscription, #oppio-popup-dismiss').prop('disabled', true);
                    button.html('🔄 Aplikujem predplatné...');
                    messageDiv.show();
                    
                    // AJAX konverzia na monthly subscription
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'switch_cart_subscription',
                        subscription_type: 'monthly',
                        nonce: '<?php echo wp_create_nonce('cart_subscription_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            messageDiv.html('<div style="color: #28a745; font-weight: 600; background: #d4edda; padding: 12px; border-radius: 6px; border: 1px solid #c3e6cb;">✓ ' + response.data.message + '</div>');
                            
                            // Označiť popup ako dismissed pred redirect
                            localStorage.setItem(popupKey, 'true');
                            
                            // Redirect na cart stránku
                            setTimeout(function() {
                                window.location.href = window.location.protocol + '//' + window.location.host + '/kosik/';
                            }, 1500);
                        } else {
                            messageDiv.html('<div style="color: #dc3545; background: #f8d7da; padding: 12px; border-radius: 6px; border: 1px solid #f5c6cb;">❌ ' + response.data.message + '</div>');
                            
                            // Re-enable tlačidlá
                            $('#oppio-apply-subscription, #oppio-popup-dismiss').prop('disabled', false);
                            button.html('Aplikovať predplatné');
                        }
                    }).fail(function() {
                        messageDiv.html('<div style="color: #dc3545; background: #f8d7da; padding: 12px; border-radius: 6px; border: 1px solid #f5c6cb;">❌ Chyba pri komunikácii so serverom</div>');
                        
                        // Re-enable tlačidlá
                        $('#oppio-apply-subscription, #oppio-popup-dismiss').prop('disabled', false);
                        button.html('Aplikovať predplatné');
                    });
                });
                
                function closePopup() {
                    $('#oppio-subscription-popup-overlay').removeClass('show');
                    
                    // OPRAVENÉ: Označiť že popup bol dismissed pre túto cart session
                    localStorage.setItem(popupKey, 'true');
                    
                    // Odstrániť z DOM po animácii
                    setTimeout(function() {
                        $('#oppio-subscription-popup-overlay').remove();
                    }, 300);
                }
                
                console.log('OPPIO subscription popup loaded with persistent dismissal');
            });

            // PRIDAJ IBA TOTO NA KONIEC:
            $(document).ajaxSuccess(function(event, xhr, settings) {
                if (settings.data && settings.data.indexOf('switch_cart_subscription') !== -1) {
                    console.log('OPPIO: Cart subscription switched - resetting popup');
                    
                    // Vymaž popup dismissed state
                    var currentKey = localStorage.getItem('oppio_current_popup_key');
                    if (currentKey) {
                        localStorage.removeItem(currentKey);
                        localStorage.removeItem('oppio_current_popup_key');
                        console.log('OPPIO: Popup state cleared - can show again');
                    }
                }
            });

        </script>
        
        <?php
    }

    /**
     * AJAX handler pre označenie popup ako videný
     */
    public function mark_popup_as_seen() {
        if (!wp_verify_nonce($_POST['nonce'], 'popup_seen_nonce')) {
            wp_send_json_error();
        }
        
        $user_id = get_current_user_id();
        $guest_session = WC()->session->get_customer_id();
        $popup_key = $user_id ? 'oppio_popup_seen_' . $user_id : 'oppio_popup_seen_' . $guest_session;
        
        // Nastaviť transient na 30 dní
        set_transient($popup_key, true, 30 * DAY_IN_SECONDS);
        
        wp_send_json_success();
    }
        
    /**
     * Handle AJAX cart subscription switch
     */
    public function handle_cart_subscription_popup_switch() {
        if (!wp_verify_nonce($_POST['nonce'], 'cart_subscription_nonce')) {
            wp_send_json_error(array('message' => 'Neplatný nonce'));
        }

        $subscription_type = sanitize_text_field($_POST['subscription_type']);
        $cart = WC()->cart;

        if (!$cart || $cart->is_empty()) {
            wp_send_json_error(array('message' => __('Košík je prázdny', 'oppio-subscriptions')));
        }

        /*
        🔹 Ak sa ide na predplatné, vymaž produkt so SKU 12
        if (in_array($subscription_type, array('monthly', 'biweekly', 'daily'), true)) {
            $target_sku = '12';
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (!empty($cart_item['data']) && $cart_item['data']->get_sku() === $target_sku) {
                    $cart->remove_cart_item($cart_item_key);
                }
            }
        }
            */

        // Aktualizuj všetky cart items
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if ($subscription_type === 'none') {
                unset($cart_item['subscription_type']);
                $cart->cart_contents[$cart_item_key] = $cart_item;
            } else {
                $cart->cart_contents[$cart_item_key]['subscription_type'] = $subscription_type;
            }
        }

        // ✅ Uložíme do session
        WC()->session->set('current_subscription_type', $subscription_type === 'none' ? 'none' : $subscription_type);

        $this->oppio_log('SESSION subscription: ' . WC()->session->get('current_subscription_type'));

        // ✅ Zabezpeč, aby sa cart uložil do session (inak fallback shortkody nevidia nič)
        WC()->cart->set_session();

        // ✅ Prepočítaj ceny
        $cart->calculate_totals();

        $labels = array(
            'none'      => __('Jednorázový nákup', 'oppio-subscriptions'),
            'monthly'   => __('Mesačné predplatné', 'oppio-subscriptions'), 
            'biweekly'  => __('14-dňové predplatné', 'oppio-subscriptions'),
            'daily'     => __('Denné predplatné', 'oppio-subscriptions'),
        );

        $message = __('Košík zmenený na: ', 'oppio-subscriptions') . '' . ($labels[$subscription_type] ?? $subscription_type);

        wp_send_json_success(array('message' => $message));
    }

    /**
     * Zakáže Stripe express checkout elementy pre non-subscription košíky
     */
    public function disable_stripe_express_for_non_subscription() {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        // ✅ DEBUG DO TVOJHO LOGU
        $has_subscription = $this->cart_has_subscription();
        // $this->oppio_log('Cart has subscription: ' . ($has_subscription ? 'YES' : 'NO'));
        // $this->oppio_log('Current page: ' . (is_cart() ? 'CART' : 'CHECKOUT'));
        
        if ($has_subscription) {
            // $this->oppio_log('Attempting to disable Stripe express checkout...');

            // Skús tento agresívnejší prístup:
            add_filter('wc_stripe_show_express_checkout_on_cart', '__return_false', 999);
            add_filter('wc_stripe_show_express_checkout_on_checkout', '__return_false', 999);
            add_filter('wc_stripe_express_checkout_enabled', '__return_false', 999);

            // CSS riešenie
            add_action('wp_footer', function() {
                ?>
                <script>
                    jQuery(document).ready(function($) {
                        console.log('OPPIO: Hiding Stripe express elements');
                        $('.wc-stripe-express-checkout-element').hide();
                        $('#wc-stripe-express-checkout-element').hide();
                        $('[id*="stripe"]').hide();
                    });
                </script>

                <style>
                    .wc-stripe-express-checkout-element,
                    #wc-stripe-express-checkout-element,
                    .stripe-express-checkout-element,
                    #wc-stripe-express-checkout-button-separator {
                        display: none !important;
                    }
                </style>
                <?php
            });

            // $this->oppio_log('Stripe express checkout disabled');
        }
        else {
             // CSS riešenie
            add_action('wp_footer', function() {
                ?>
                <script>
                    jQuery(document).ready(function($) {
                        console.log('OPPIO: Hiding Stripe');
                    });
                </script>

                <style>
                    #wc-stripe-express-checkout-button-separator,
                    #wc-stripe-express-checkout-element-googlePay,
                    .payment_method_stripe {
                        display: none !important;
                    }
                </style>
                <?php
            });
        }
    }

    /**
     * Zobrazí súhrn subscription v košíku
     */
    public function display_cart_subscription_summary() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) return;

        $subscription_type = $this->get_existing_subscription_type_from_cart();
        if (!$subscription_type) return;

        $labels = array('monthly' => 'Mesačné predplatné', 'biweekly' => '14-dňové predplatné', 'daily' => 'Denné predplatné');
        $savings = array('monthly' => '15%', 'biweekly' => '20%', 'daily' => '10%');
        $period_labels = array('monthly' => 'každý mesiac', 'biweekly' => 'každých 14 dní', 'daily' => 'denne');

        $subscription_label = $labels[$subscription_type] ?? 'Neznámy';
        $period_label = $period_labels[$subscription_type] ?? '';
        $saving = $savings[$subscription_type] ?? '0%';

        // SPRÁVNY VÝPOČET FINÁLNEJ SUMY
        // Získaj subtotal iba pre subscription produkty
        $subscription_subtotal = 0;
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['subscription_type']) && $cart_item['subscription_type'] === $subscription_type) {
                $subscription_subtotal += $cart_item['line_subtotal'];
            }
        }

        // Ak nie sú žiadne subscription produkty, použij celý subtotal
        if ($subscription_subtotal == 0) {
            $subscription_subtotal = $cart->get_subtotal();
        }

        // Vypočítaj zľavu
        $discount_percent = 0;
        switch ($subscription_type) {
            case 'monthly':
                $discount_percent = 15;
                $discount_label = 'Zľava mesačné predplatné';
                break;
            case 'biweekly':
                $discount_percent = 20;
                $discount_label = 'Zľava 14-dňové predplatné';
                break;
            case 'daily':                    // ← PRIDANÉ
                $discount_percent = 10;      // ← PRIDANÉ
                $discount_label = 'Zľava denné predplatné';  // ← PRIDANÉ
                break;                       // ← PRIDANÉ
        }

        $discount_amount = ($subscription_subtotal * $discount_percent) / 100;
        $final_total = $subscription_subtotal - $discount_amount;

        // Pridaj shipping ak existuje (shipping sa nezľavňuje)
        $shipping_total = $cart->get_shipping_total();
        if ($shipping_total > 0) {
            $final_total += $shipping_total;
        }

        /*
        ?>
            <div class="oppio-cart-subscription-summary" style="margin-bottom: 20px; padding: 15px; background: #e8f5e8; border: 2px solid var(--project-green-light); border-radius: 8px;">
                <h3 style="margin: 0 0 10px 0; color: #2e7d32;"><span class="oppio-subscription-icon">↻</span> <?php echo esc_html($subscription_label); ?></h3>
                <!-- <p style="margin: 5px 0; font-size: 16px;"><strong>Celková suma:</strong> <?php echo wc_price($final_total); ?> <strong><?php echo esc_html($period_label); ?></strong></p> -->
                <p style="margin: 5px 0; color: #2e7d32;">✓ Ušetríte <?php echo esc_html($saving); ?> oproti jednorázovému nákupu</p>
                <p style="margin: 5px 0; font-size: 14px; color: #666;">Všetky produkty v košíku sú súčasťou tohto predplatného.</p>
            </div>
        <?php 
        */
    }

    /**
     * EŠTE LEPŠIE RIEŠENIE - Nastav free shipping pre subscription
     * Pridaj do __construct() alebo init():
     * add_filter('woocommerce_shipping_free_shipping_is_available', array($this, 'enable_free_shipping_for_subscription'), 20, 3);
     */
    public function enable_free_shipping_for_subscription($is_available, $package, $shipping_method) {
        // Skontroluj či košík má subscription
        if (WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['subscription_type'])) {
                    // $this->oppio_log("Enabling free shipping for subscription");
                    return true; // Force free shipping
                }
            }
        }
        
        return $is_available;
    }

    /**
     * Aplikuje subscription zľavu
     *
     * OPRAVENÁ FUNKCIA - aplikuje subscription zľavu VŽDY ak je subscription v košíku
     */
    public function apply_subscription_discount() {
        $cart = WC()->cart;
        // if (!$cart || $cart->is_empty() || is_admin()) return;
        if ( !$cart || $cart->is_empty() || ( is_admin() && ! wp_doing_ajax() ) ) return;

        // 1) Musí byť zvolený typ predplatného v session
        $session_type = WC()->session ? WC()->session->get('current_subscription_type') : null;
        if (empty($session_type) || $session_type === 'none') { 
            $this->oppio_log('Skip discount: no subscription type in session');
            return; 
        }

        // 2) Košík musí mať položky so subscription_type
        if (!$this->cart_has_subscription()) { 
            $this->oppio_log('Skip discount: cart has no subscription items');
            return; 
        }

        // 3) Ak je kupón, neaplikovať subscribe zľavu (žiadne stackovanie)
        $applied_coupons = WC()->cart ? WC()->cart->get_applied_coupons() : array();
        if (!empty($applied_coupons)) { 
            $this->oppio_log('Skip discount: coupon detected [' . implode(',', $applied_coupons) . ']');
            return; 
        }

        // 4) Ak je v košíku aspoň jeden SALE produkt, neaplikovať subscribe zľavu (žiadne stackovanie)
        foreach ($cart->get_cart() as $ci) {
            $p = $ci['data'];
            if ($p && ( $p->is_on_sale() || $p->get_sale_price() !== '' )) {
                $this->oppio_log('Skip discount: whole order already has SALE prices');
                return;
            }
        }

        // 5) Získaj aktuálny typ predplatného (daily/biweekly/monthly)
        $subscription_type = $this->get_existing_subscription_type_from_cart();
        if (!$subscription_type) {
            $this->oppio_log('Skip discount: no subscription_type found in cart');
            return;
        }

        // 6) Nastav percentá a preložiteľný label podľa typu
        $discount_percent = 0;
        $discount_label   = '';

        switch ($subscription_type) {
            case 'monthly':
                $discount_percent = 15;
                $discount_label   = esc_html__('Zľava mesačné predplatné', 'oppio');
                break;
            case 'biweekly':
                $discount_percent = 20;
                $discount_label   = esc_html__('Zľava 14-dňové predplatné', 'oppio');
                break;
            case 'daily':
                $discount_percent = 10;
                $discount_label   = esc_html__('Zľava denné predplatné', 'oppio');
                break;
        }

        if ($discount_percent > 0) {
            // 7) Jedinečný názov fee (podľa typu a percent), aby sa nepridal 2x
            $unique_fee_name = sprintf('%s (-%d%%)', $discount_label, $discount_percent);

            // 8) Ak už fee existuje, skonči
            $existing_fees = $cart->get_fees();
            foreach ($existing_fees as $fee) {
                if ($fee->get_name() === $unique_fee_name) {
                    $this->oppio_log('Skip discount: fee already exists → ' . $unique_fee_name);
                    return; 
                }
            }

            // 9) Medzisúčet len pre položky so subscription_type
            $subscription_subtotal = 0.0;
            foreach ($cart->get_cart() as $cart_item) {
                if (isset($cart_item['subscription_type'])) {
                    $subscription_subtotal += (float) $cart_item['line_subtotal'];
                }
            }

            if ($subscription_subtotal > 0) {
                $discount_amount = round(($subscription_subtotal * $discount_percent) / 100, 2);
                $this->oppio_log('Applying discount: ' . $unique_fee_name . ' subtotal=' . $subscription_subtotal . ' discount=' . $discount_amount);

                // 10) Pridaj fee (záporná hodnota = zľava)
                $cart->add_fee($unique_fee_name, -1 * abs($discount_amount), false);
            } else {
                $this->oppio_log('Skip discount: subscription subtotal is 0');
            }
        }
    }

    /**
     * Filtruje payment gateways - STRIPE IBA PRE SUBSCRIPTION
     */
    public function filter_payment_gateways($gateways) {
        if (!is_checkout()) return $gateways;

        $has_subscription = $this->cart_has_subscription();

        if ($has_subscription) {
            // ✅ KOŠÍK MÁ SUBSCRIPTION → IBA STRIPE
            $filtered_gateways = array();

            foreach ($gateways as $gateway_id => $gateway) {
                if ($gateway_id === 'stripe' || strpos($gateway_id, 'stripe') !== false) {
                    $filtered_gateways[$gateway_id] = $gateway;
                    break;
                }
            }

            if (empty($filtered_gateways)) {
                // Fallback ak Stripe nie je dostupný
                foreach ($gateways as $gateway_id => $gateway) {
                    if (method_exists($gateway, 'get_title')) {
                        $gateway->title = $gateway->get_title() . ' ⚠️ NEPODPORUJE SUBSCRIPTION';
                    }
                }
                return $gateways;
            }
            return $filtered_gateways;
    
        } 
        else {
            // ✅ KOŠÍK NEMÁ SUBSCRIPTION → ODSTRÁŇ STRIPE
            $filtered_gateways = array();

            foreach ($gateways as $gateway_id => $gateway) {
                // Odstráň všetky Stripe gateways
                if ($gateway_id !== 'stripe-x' && strpos($gateway_id, 'stripe-x') === false) {
                    $filtered_gateways[$gateway_id] = $gateway;
                }
            }
            return $filtered_gateways;
        }
    }

    /**
     * Skontroluje či košík obsahuje subscription
     */
    private function cart_has_subscription() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) return false;

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['subscription_type'])) {
                return true;
            }
        }
        return false;
    }

    // =============================================
    // ====== STRIPE SUBSCRIPTION INTEGRATION ======
    // =============================================
    /**
     * Pridá subscription metadata do order line items
     */
    public function add_subscription_line_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['subscription_type'])) {
            $subscription_type = $values['subscription_type'];

            // Pridaj meta data do order item
            $item->add_meta_data('_oppio_subscription_type', $subscription_type);
            $item->add_meta_data('_oppio_is_subscription', 'yes');

            // Pridaj display meta pre subscription
            $labels = array(
                'monthly' => 'Mesačné predplatné',
                'biweekly' => '14-dňové predplatné',
                'daily' => 'Denné predplatné'
            );

            $item->add_meta_data('Typ nákupu', $labels[$subscription_type] ?? 'Neznámy');

            $this->oppio_log('OPPIO: Added subscription meta to order line item: ' . $subscription_type);
        }
    }

    /**
     * Customize order item meta display
     */
    public function customize_order_item_meta_display($display_key, $meta, $item) {
        if ($meta->key === '_oppio_subscription_type') {
            return 'Typ predplatného';
        }
        return $display_key;
    }

    /**
     * Customize order item meta value
     */
    public function customize_order_item_meta_value($display_value, $meta, $item) {
        if ($meta->key === '_oppio_subscription_type') {
            $labels = array(
                'monthly' => 'Mesačné predplatné',
                'biweekly' => '14-dňové predplatné',
                'daily' => 'Denné predplatné',
            );
            return $labels[$meta->value] ?? $meta->value;
        }
        return $display_value;
    }

    /**
     * Modifikuje Stripe payment intent pre subscription
     */
    public function modify_stripe_payment_intent($args, $order) {
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        if ($subscription_type) {
            $args['metadata']['oppio_subscription'] = 'true';
            $args['metadata']['oppio_subscription_type'] = $subscription_type;

            // 3DS necháme na Stripe (automaticky)
            $args['payment_method_options']['card']['request_three_d_secure'] = 'automatic';

            // nech si Stripe uloží kartu na off-session (pre obnovu predplatného)
            $args['setup_future_usage'] = 'off_session';

            $this->oppio_log('OPPIO: Modified Stripe Payment Intent for subscription: ' . $subscription_type);
        }
        return $args;
    }

    /**
     * Pridá subscription metadata do order
     */
    public function add_subscription_order_meta($order, $data) {
        // ✅ DEBUG
        $this->oppio_log('=== ADD_SUBSCRIPTION_ORDER_META CALLED ===');
        $this->oppio_log('Order ID: ' . $order->get_id());

        $subscription_type = $this->get_existing_subscription_type_from_cart();

        if ($subscription_type) {
            $order->update_meta_data('_oppio_subscription_type', $subscription_type);
            $order->update_meta_data('_oppio_is_subscription', 'yes');

            // Interval pre Stripe
            $intervals = array(
                'monthly' => array('interval' => 'month', 'interval_count' => 1),
                'biweekly' => array('interval' => 'week', 'interval_count' => 2),
                'daily' => array('interval' => 'day', 'interval_count' => 1)
            );
            
            if (isset($intervals[$subscription_type])) {
                $order->update_meta_data('_oppio_stripe_interval', $intervals[$subscription_type]['interval']);
                $order->update_meta_data('_oppio_stripe_interval_count', $intervals[$subscription_type]['interval_count']);
            };

            // Pridaj subscription info do order notes
            $labels = array('monthly' => 'Mesačné predplatné', 'biweekly' => '14-dňové predplatné', 'daily' => 'Denné predplatné');
            $subscription_label = $labels[$subscription_type] ?? $subscription_type;

            // $order->add_order_note('📦 Objednávka obsahuje: ' . $subscription_label);
            $order->add_order_note('<span class="oppio-subscription-icon">↻</span> Objednávka obsahuje: ' . $subscription_label);

            // $this->oppio_log('OPPIO: Added subscription metadata to order: ' . $subscription_type);
        }

    }

    /**
     * Force save payment method pre subscriptions
     */
    public function force_save_payment_method($force_save, $order) {
        // Ak príde len ID, načítaj objekt
        if (is_int($order)) {
            $order = wc_get_order($order);
        }

        // Ak sa objednávka nepodarí získať, ponechaj pôvodnú hodnotu
        if (!$order instanceof WC_Order) {
            return $force_save;
        }

        // Ak ide o subscription, vynúť uloženie payment method
        if ($order->get_meta('_oppio_is_subscription') === 'yes') {
            return true;
        }

        return $force_save;
    }

    /**
     * ✅
     * Vytvorí Stripe subscription po úspešnej platbe
     */
    public function create_stripe_subscription($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $is_subscription = $order->get_meta('_oppio_is_subscription');
        if ($is_subscription !== 'yes') {
            $this->oppio_log('OPPIO: Order ' . $order_id . ' is not a subscription order');
            return;
        }
        
        $stripe_subscription_id = $order->get_meta('_oppio_stripe_subscription_id');
        if (!empty($stripe_subscription_id)) {
            $this->oppio_log('OPPIO: Order ' . $order_id . ' already has subscription: ' . $stripe_subscription_id);
            return;
        }
        
        $stripe_customer_id = $order->get_meta('_stripe_customer_id');
        $stripe_payment_method = $order->get_meta('_stripe_payment_method_id') ?: $order->get_meta('_stripe_source_id');
        
        if (empty($stripe_customer_id)) {
            $order->add_order_note('❌ Chýba Stripe Customer ID');
            return;
        }
        
        $valid_payment_method = $this->get_valid_payment_method($stripe_customer_id, $stripe_payment_method);
        
        if (!$valid_payment_method) {
            $order->add_order_note('❌ Žiadny platný payment method pre subscription');
            $this->oppio_log('OPPIO: No valid payment method for subscription');
            return;
        }
        
        // ✅ ZMENÍTE LEN TENTO RIADOK:
        // STARÝ: $subscription_result = $this->create_stripe_subscription_api_working($order, $stripe_customer_id, $valid_payment_method);
        // NOVÝ:
        $subscription_result = $this->create_stripe_subscription_api_working_v5($order, $stripe_customer_id, $valid_payment_method);
        
        if ($subscription_result && isset($subscription_result['id'])) {
            $subscription_id = $subscription_result['id'];
            $status = $subscription_result['status'] ?? 'unknown';
            
            $order->update_meta_data('_oppio_stripe_subscription_id', $subscription_id);
            $order->update_meta_data('_oppio_subscription_status', $status);
            $order->save();
            
            if ($status === 'trialing') {
                $order->add_order_note('✅ Subscription vytvorené v trial période: ' . $subscription_id);
            } else {
                $order->add_order_note('✅ Subscription vytvorené (' . $status . '): ' . $subscription_id);
            }
            
            $this->oppio_log('SUCCESS: Subscription saved to order: ' . $subscription_id);
        } else {
            $order->add_order_note('❌ Nepodarilo sa vytvoriť Stripe subscription');
            $this->oppio_log('ERROR: No subscription ID returned from creation function');
        }
    }

    // ✅ NOVÁ FUNKCIA - VALIDÁCIA PAYMENT METHOD
    private function get_valid_payment_method($customer_id, $preferred_payment_method) {
        // Get API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key = $test_mode ? $stripe_settings['test_secret_key'] : $stripe_settings['secret_key'];
        
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20'
        );

        // 1. Skús preferovaný payment method
        if (!empty($preferred_payment_method)) {
            $pm_response = wp_remote_get('https://api.stripe.com/v1/payment_methods/' . $preferred_payment_method, array('headers' => $headers));
            
            if (!is_wp_error($pm_response) && wp_remote_retrieve_response_code($pm_response) === 200) {
                $pm_data = json_decode(wp_remote_retrieve_body($pm_response), true);
                
                // Skontroluj či je attached k customer
                if (isset($pm_data['customer']) && $pm_data['customer'] === $customer_id) {
                    $this->oppio_log('OPPIO: Using existing payment method: ' . $preferred_payment_method);
                    return $preferred_payment_method;
                }
            }
        }

        // 2. Získaj payment methods z customer
        $customer_pm_response = wp_remote_get('https://api.stripe.com/v1/customers/' . $customer_id . '/payment_methods?type=card', array('headers' => $headers));
        
        if (!is_wp_error($customer_pm_response) && wp_remote_retrieve_response_code($customer_pm_response) === 200) {
            $customer_pm_data = json_decode(wp_remote_retrieve_body($customer_pm_response), true);
            
            if (!empty($customer_pm_data['data'])) {
                $latest_pm = $customer_pm_data['data'][0]['id'];
                $this->oppio_log('OPPIO: Using customer latest payment method: ' . $latest_pm);
                return $latest_pm;
            }
        }

        $this->oppio_log('OPPIO: No valid payment method found for customer: ' . $customer_id);
        return false;
    }

    // ✅ Vypočíta subscription cenu BEZ kupónu
    // Presne zhodná suma so sumou rodičovskej objednávky
    private function calculate_subscription_price_without_coupon($order, $subscription_type) {
        return (float) $order->get_total();
    }

    /**
     * ✅ UNIVERZÁLNE RIEŠENIE PRE VŠETKY TYPY KARIET A SCA
     * Vytvorí Stripe subscription s automatickým SCA handling
     */
     /*
    public function create_stripe_subscription_api_working_v5($order, $customer_id, $payment_method_id) {
        $this->oppio_log('=== SIMPLE SUBSCRIPTION V2 START ===');

        // 1) Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if (empty($api_key)) {
            $this->oppio_log('ERROR: Missing API key');
            return array('success' => false, 'status' => 'error', 'message' => 'Missing Stripe API key');
        }

        $headers = array(
            'Authorization'  => 'Bearer ' . $api_key,
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2024-06-20',
        );

        // 2) (Voliteľný) pre-test off-session: NEVRACIAME SA ani pri requires_action, len si odložíme client_secret
        $this->oppio_log('Testing payment method for off-session usage');
        $pretest_si_secret = '';
        $setup_data = array(
            'customer'       => $customer_id,
            'payment_method' => $payment_method_id,
            'usage'          => 'off_session',
            'confirm'        => 'true' // ← STRING, nie boolean
        );

        $setup_response = wp_remote_post('https://api.stripe.com/v1/setup_intents', array(
            'headers' => $headers,
            'body'    => http_build_query($setup_data),
            'timeout' => 30,
        ));
        if (!is_wp_error($setup_response)) {
            $setup_result = json_decode(wp_remote_retrieve_body($setup_response), true);
            $this->oppio_log('Pretest SI HTTP ' . wp_remote_retrieve_response_code($setup_response) . ' BODY: ' . wp_remote_retrieve_body($setup_response));
            if (!empty($setup_result['status']) && $setup_result['status'] === 'requires_action') {
                $this->oppio_log('Payment method requires 3DS for off-session usage (pre-test)');
                $pretest_si_secret = $setup_result['client_secret'] ?? '';
                // NEVRACIAME — pokračujeme vytvoriť subscription
            }
        }

        $this->oppio_log('Payment method OK for off-session usage, creating subscription');
        $this->oppio_log('Using payment method: ' . $payment_method_id);

        // 3) Attach PM na customer (ak už nie je)
        wp_remote_post('https://api.stripe.com/v1/payment_methods/' . rawurlencode($payment_method_id) . '/attach', array(
            'headers' => $headers,
            'body'    => http_build_query(array('customer' => $customer_id)),
            'timeout' => 30,
        ));

        // 4) Príprava položiek
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        $items = $order->get_items();
        $first_item = reset($items);
        if (!$first_item) {
            $this->oppio_log('ERROR: No order items');
            return array('success' => false, 'status' => 'error', 'message' => 'No order items');
        }
        $product  = $first_item->get_product();
        $quantity = max(1, (int)$first_item->get_quantity());

        // mapa labelov
        $label = match ($subscription_type) {
            'daily'    => 'daily',
            'weekly'   => 'týždenné',
            'biweekly' => '14-denné',
            default    => 'mesačné',
        };

        $product_name = '#' . $order->get_id() .' - '. $label . ' predplatné';

        // 5) Vytvor Product
        $product_data = array(
            'name'                 => $product_name,
            'metadata[order_id]'   => $order->get_id(),
        );
        $product_response = wp_remote_post('https://api.stripe.com/v1/products', array(
            'headers' => $headers,
            'body'    => http_build_query($product_data),
            'timeout' => 30,
        ));
        $product_body   = wp_remote_retrieve_body($product_response);
        $product_result = json_decode($product_body, true);
        if (empty($product_result['id'])) {
            $this->oppio_log('ERROR: Product creation failed BODY: ' . $product_body);
            return array('success' => false, 'status' => 'error', 'message' => 'Stripe product creation failed');
        }

        // 6) Vytvor Price (kontrola sumy)
        $subscription_price = (float)$this->calculate_subscription_price_without_coupon($order, $subscription_type);
        $price_per_unit     = $subscription_price / $quantity;
        $amount_cents       = (int)round($price_per_unit * 100);
        if ($amount_cents <= 0) {
            $this->oppio_log('ERROR: Calculated unit_amount is <= 0 (currency ' . $order->get_currency() . ').');
            return array('success' => false, 'status' => 'error', 'message' => 'Invalid recurring amount (<= 0).');
        }

        // Interval
        $interval_data = array('month' => 1);
        switch ($subscription_type) {
            case 'daily':    $interval_data = array('day' => 1);  break;
            case 'weekly':   $interval_data = array('week' => 1); break;
            case 'biweekly': $interval_data = array('week' => 2); break;
            // monthly default
        }
        $interval       = key($interval_data);
        $interval_count = $interval_data[$interval];

        $price_data = array(
            'product'                   => $product_result['id'],
            'unit_amount'               => $amount_cents,
            'currency'                  => strtolower($order->get_currency()),
            'recurring[interval]'       => $interval,
            'recurring[interval_count]' => $interval_count,
            'metadata[order_id]'        => $order->get_id(),
        );
        $price_response = wp_remote_post('https://api.stripe.com/v1/prices', array(
            'headers' => $headers,
            'body'    => http_build_query($price_data),
            'timeout' => 30,
        ));
        $price_body   = wp_remote_retrieve_body($price_response);
        $price_result = json_decode($price_body, true);
        if (empty($price_result['id'])) {
            $this->oppio_log('ERROR: Price creation failed BODY: ' . $price_body);
            return array('success' => false, 'status' => 'error', 'message' => 'Stripe price creation failed');
        }

        // 7) Trial – použijeme počet dní (stabilnejšie než trial_end)
        $trial_days = 30; // default
        switch ($subscription_type) {
            case 'daily':    $trial_days = 1;  break;
            case 'weekly':   $trial_days = 7;  break;
            case 'biweekly': $trial_days = 14; break;
            case 'monthly':
            default:         $trial_days = 30; break;
        }

        // napr. vlastné ID kupónu alebo Stripe ID typu "coupon_..."
        $first_order_coupon_id = 'first_order_free'; 

        // 8) Vytvor Subscription
        $subscription_data = array(
            'customer'                               => $customer_id,
            'default_payment_method'                 => $payment_method_id,
            'items[0][price]'                        => $price_result['id'],
            'items[0][quantity]'                     => $quantity,

            // žiadny trial_period_days

            'payment_behavior'                       => 'default_incomplete',
            'payment_settings[save_default_payment_method]' => 'on_subscription',
            'collection_method'                      => 'charge_automatically',

            // 100 % zľava len na 1. faktúru (kupón)
            'discounts[0][coupon]'                   => $first_order_coupon_id,

            'metadata[order_id]'                     => $order->get_id(),
            'metadata[subscription_type]'            => $subscription_type,
        );

        // DEBUG: payload preview
        $__dbg_items = print_r(array(
            'customer'               => $subscription_data['customer'],
            'default_payment_method' => $subscription_data['default_payment_method'],
            'items[0][price]'        => $subscription_data['items[0][price]'],
            'items[0][quantity]'     => $subscription_data['items[0][quantity]'],
            'trial_period_days'      => $subscription_data['trial_period_days'],
            'payment_behavior'       => $subscription_data['payment_behavior'],
            'save_default_pm'        => $subscription_data['payment_settings[save_default_payment_method]'],
            'order_id'               => $subscription_data['metadata[order_id]'],
            'subscription_type'      => $subscription_data['metadata[subscription_type]'],
        ), true);
        $this->oppio_log('SUB payload preview: ' . $__dbg_items);

        // expand parametre explicitne pridáme do query stringu, aby boli 100%
        $body_qs  = http_build_query($subscription_data);
        $body_qs .= '&expand[]=latest_invoice.payment_intent&expand[]=pending_setup_intent';

        $subscription_response = wp_remote_post('https://api.stripe.com/v1/subscriptions', array(
            'headers' => $headers,
            'body'    => $body_qs,
            'timeout' => 30,
        ));

        $sub_http_code       = wp_remote_retrieve_response_code($subscription_response);
        $sub_body            = wp_remote_retrieve_body($subscription_response);
        $subscription_result = json_decode($sub_body, true);

        // DEBUG: logni presne, čo vrátil Stripe
        $this->oppio_log('SUB create HTTP ' . $sub_http_code . ' BODY: ' . $sub_body);

        if ($sub_http_code < 200 || $sub_http_code >= 300) {
            $msg = isset($subscription_result['error']['message']) ? $subscription_result['error']['message'] : 'Stripe error';
            $this->oppio_log('SUB create FAILED: ' . $msg);
            return array('success' => false, 'status' => 'error', 'message' => $msg);
        }

        if (empty($subscription_result['id'])) {
            $msg = isset($subscription_result['error']['message']) ? $subscription_result['error']['message'] : 'Subscription creation failed (no id)';
            $this->oppio_log('ERROR: ' . $msg);
            return array('success' => false, 'status' => 'error', 'message' => $msg);
        }

        $this->oppio_log('SUCCESS: Subscription created: ' . $subscription_result['id']);

        // 9) Vyber client_secret-y
        $pi_secret = '';
        $si_secret = '';
        if (!empty($subscription_result['latest_invoice']['payment_intent']['client_secret'])) {
            $pi_secret = $subscription_result['latest_invoice']['payment_intent']['client_secret'];
        }
        if (!empty($subscription_result['pending_setup_intent']['client_secret'])) {
            $si_secret = $subscription_result['pending_setup_intent']['client_secret'];
        }
        // ak by pending SI nebol a máme pre-test SI, použi ten
        if (empty($si_secret) && !empty($pretest_si_secret)) {
            $si_secret = $pretest_si_secret;
        }

        // 10) Ulož do order meta
        $order->update_meta_data('_oppio_invoice_pi_client_secret', $pi_secret);
        $order->update_meta_data('_oppio_setup_client_secret', $si_secret);
        $order->save();

        // 11) Vráť dáta pre frontend
       return array(
            'success'         => true,
            'id'              => $subscription_result['id'],            // ← PRIDANÉ, kvôli tvojmu volajúcemu
            'subscription_id' => $subscription_result['id'],
            'status'          => $subscription_result['status'],
            'type'            => ($pi_secret ? 'pi' : 'si'),
            'client_secret'   => $pi_secret,
            'setup_secret'    => $si_secret,
            'redirect'        => $order->get_checkout_order_received_url()
        );
    }
    */
    public function create_stripe_subscription_api_working_v5($order, $customer_id, $payment_method_id) {
        $this->oppio_log('=== SIMPLE SUBSCRIPTION V2 START ===');

        // 1) Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if (empty($api_key)) {
            $this->oppio_log('ERROR: Missing API key');
            return array('success' => false, 'status' => 'error', 'message' => 'Missing Stripe API key');
        }

        $headers = array(
            'Authorization'  => 'Bearer ' . $api_key,
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2024-06-20',
        );

        // 2) ZRUŠENÉ: "pre-test" SetupIntent s confirm=true (spôsobovalo varovanie o return_url)
        // -- úmyselne vynechané --

        $this->oppio_log('Creating subscription with PM: ' . $payment_method_id);

        // 3) Attach PM na customer + KONTROLA CHÝB
        $attach_resp = wp_remote_post(
            'https://api.stripe.com/v1/payment_methods/' . rawurlencode($payment_method_id) . '/attach',
            array(
                'headers' => $headers,
                'body'    => http_build_query(array('customer' => $customer_id)),
                'timeout' => 30,
            )
        );
        if (is_wp_error($attach_resp) || (int)wp_remote_retrieve_response_code($attach_resp) >= 400) {
            $this->oppio_log('ERROR: attach PM failed: ' . wp_remote_retrieve_body($attach_resp));
            return array('success' => false, 'status' => 'error', 'message' => 'Unable to attach payment method to customer');
        }

        // 4) Príprava položiek
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        $items = $order->get_items();
        $first_item = reset($items);
        if (!$first_item) {
            $this->oppio_log('ERROR: No order items');
            return array('success' => false, 'status' => 'error', 'message' => 'No order items');
        }
        $product  = $first_item->get_product();
        $quantity = max(1, (int)$first_item->get_quantity());

        // label názvu
        $label = match ($subscription_type) {
            'daily'    => 'daily',
            'weekly'   => 'týždenné',
            'biweekly' => '14-denné',
            default    => 'mesačné',
        };
        $product_name = '#' . $order->get_id() .' - '. $label . ' predplatné';

        // 5) Product
        $product_data = array(
            'name'               => $product_name,
            'metadata[order_id]' => $order->get_id(),
        );
        $product_response = wp_remote_post('https://api.stripe.com/v1/products', array(
            'headers' => $headers,
            'body'    => http_build_query($product_data),
            'timeout' => 30,
        ));
        $product_body   = wp_remote_retrieve_body($product_response);
        $product_result = json_decode($product_body, true);
        if (empty($product_result['id'])) {
            $this->oppio_log('ERROR: Product creation failed BODY: ' . $product_body);
            return array('success' => false, 'status' => 'error', 'message' => 'Stripe product creation failed');
        }

        // 6) Price
        $subscription_price = (float)$this->calculate_subscription_price_without_coupon($order, $subscription_type);
        $price_per_unit     = $subscription_price / $quantity;
        $amount_cents       = (int)round($price_per_unit * 100);
        if ($amount_cents <= 0) {
            $this->oppio_log('ERROR: Calculated unit_amount is <= 0 (currency ' . $order->get_currency() . ').');
            return array('success' => false, 'status' => 'error', 'message' => 'Invalid recurring amount (<= 0).');
        }

        // interval
        $interval_data = array('month' => 1);
        switch ($subscription_type) {
            case 'daily':    $interval_data = array('day' => 1);  break;
            case 'weekly':   $interval_data = array('week' => 1); break;
            case 'biweekly': $interval_data = array('week' => 2); break;
        }
        $interval       = key($interval_data);
        $interval_count = $interval_data[$interval];

        $price_data = array(
            'product'                   => $product_result['id'],
            'unit_amount'               => $amount_cents,
            'currency'                  => strtolower($order->get_currency()),
            'recurring[interval]'       => $interval,
            'recurring[interval_count]' => $interval_count,
            'metadata[order_id]'        => $order->get_id(),
        );
        $price_response = wp_remote_post('https://api.stripe.com/v1/prices', array(
            'headers' => $headers,
            'body'    => http_build_query($price_data),
            'timeout' => 30,
        ));
        $price_body   = wp_remote_retrieve_body($price_response);
        $price_result = json_decode($price_body, true);
        if (empty($price_result['id'])) {
            $this->oppio_log('ERROR: Price creation failed BODY: ' . $price_body);
            return array('success' => false, 'status' => 'error', 'message' => 'Stripe price creation failed');
        }

        // 7) Trial premennú nechávame, nepoužívame (žiadny trial_period_days)
        $trial_days = match ($subscription_type) {
            'daily'    => 1,
            'weekly'   => 7,
            'biweekly' => 14,
            default    => 30,
        };

        // 8) Subscription payload
        // POZOR na kupón: ak v Stripe NEEXISTUJE 'first_order_free', radšej riadok nepošli.
        $subscription_data = array(
            'customer'                               => $customer_id,
            'default_payment_method'                 => $payment_method_id,
            'items[0][price]'                        => $price_result['id'],
            'items[0][quantity]'                     => $quantity,
            'payment_behavior'                       => 'default_incomplete',
            'payment_settings[save_default_payment_method]' => 'on_subscription',
            'collection_method'                      => 'charge_automatically',
            'discounts[0][coupon]'                 => 'first_order_free', // ← zapni len ak kupón existuje
            'metadata[order_id]'                     => $order->get_id(),
            'metadata[subscription_type]'            => $subscription_type,
        );

        // DEBUG: bezpečný náhľad (bez neexistujúcich kľúčov)
        $__dbg_items = print_r(array(
            'customer'               => $subscription_data['customer'],
            'default_payment_method' => $subscription_data['default_payment_method'],
            'items[0][price]'        => $subscription_data['items[0][price]'],
            'items[0][quantity]'     => $subscription_data['items[0][quantity]'],
            'payment_behavior'       => $subscription_data['payment_behavior'],
            'save_default_pm'        => $subscription_data['payment_settings[save_default_payment_method]'],
            'order_id'               => $subscription_data['metadata[order_id]'],
            'subscription_type'      => $subscription_data['metadata[subscription_type]'],
        ), true);
        $this->oppio_log('SUB payload preview: ' . $__dbg_items);

        // expand
        $body_qs  = http_build_query($subscription_data);
        $body_qs .= '&expand[]=latest_invoice.payment_intent&expand[]=pending_setup_intent';

        $subscription_response = wp_remote_post('https://api.stripe.com/v1/subscriptions', array(
            'headers' => $headers,
            'body'    => $body_qs,
            'timeout' => 30,
        ));

        $sub_http_code       = wp_remote_retrieve_response_code($subscription_response);
        $sub_body            = wp_remote_retrieve_body($subscription_response);
        $subscription_result = json_decode($sub_body, true);

        $this->oppio_log('SUB create HTTP ' . $sub_http_code . ' BODY: ' . $sub_body);

        if ($sub_http_code < 200 || $sub_http_code >= 300) {
            $msg = isset($subscription_result['error']['message']) ? $subscription_result['error']['message'] : 'Stripe error';
            $this->oppio_log('SUB create FAILED: ' . $msg);
            return array('success' => false, 'status' => 'error', 'message' => $msg);
        }
        if (empty($subscription_result['id'])) {
            $msg = isset($subscription_result['error']['message']) ? $subscription_result['error']['message'] : 'Subscription creation failed (no id)';
            $this->oppio_log('ERROR: ' . $msg);
            return array('success' => false, 'status' => 'error', 'message' => $msg);
        }

        $this->oppio_log('SUCCESS: Subscription created: ' . $subscription_result['id']);

        // 9) client_secret-y
        $pi_secret = $subscription_result['latest_invoice']['payment_intent']['client_secret'] ?? '';
        $si_secret = $subscription_result['pending_setup_intent']['client_secret'] ?? '';

        // 10) uložiť do order meta
        $order->update_meta_data('_oppio_invoice_pi_client_secret', $pi_secret);
        $order->update_meta_data('_oppio_setup_client_secret', $si_secret);
        $order->save();

        // 11) dôležité: return_url pre frontend confirm na Thank You page
        $return_url = $order->get_checkout_order_received_url();

        return array(
            'success'         => true,
            'id'              => $subscription_result['id'],
            'subscription_id' => $subscription_result['id'],
            'status'          => $subscription_result['status'],
            'type'            => ($pi_secret ? 'pi' : 'si'),
            'client_secret'   => $pi_secret,
            'setup_secret'    => $si_secret,
            'redirect'        => $return_url,
            'return_url'      => $return_url, // ⬅️ toto musí frontend použiť v confirm*
        );
    }

    /**
     * AJAX endpoint pre vytvorenie Stripe Subscription
     */
    public function ajax_create_subscription() {
        $order_id = absint($_POST['order_id'] ?? 0);
        $customer_id = sanitize_text_field($_POST['customer_id'] ?? '');
        $payment_method = sanitize_text_field($_POST['payment_method_id'] ?? '');
        
        if (!$order_id || !$customer_id || !$payment_method) {
            wp_send_json_error(['message' => 'Missing input'], 400);
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found'], 404);
        }
        
        // ✅ ZMENÍTE LEN TENTO RIADOK:
        // STARÝ: $resp = $this->create_stripe_subscription_api_working($order, $customer_id, $payment_method);
        // NOVÝ:
        $resp = $this->create_stripe_subscription_api_working_v5($order, $customer_id, $payment_method);
        
        if (!$resp || isset($resp['error'])) {
            wp_send_json_error(['message' => 'Subscription creation failed'], 500);
        }
        
        wp_send_json_success($resp);
    }

    /**
     * ✅ NOVÁ FUNKCIA: Potvrdí PaymentIntent ktorý vyžaduje confirmation
     */
    private function confirm_payment_intent($payment_intent_id, $api_key) {
        $headers = array(
            'Authorization'     => 'Bearer ' . $api_key,
            'Content-Type'      => 'application/x-www-form-urlencoded',
            'Stripe-Version'    => '2024-06-20',
            'Idempotency-Key'   => 'oppio-'.$payment_intent_id.'-confirm',
        );

        $confirm_response = wp_remote_post('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id . '/confirm', array(
            'headers'   => $headers,
            'body'      => '',  // Prázdne telo pre confirm
            'timeout'   => 30
        ));

        if (is_wp_error($confirm_response)) {
            return array('success' => false, 'error' => $confirm_response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($confirm_response);
        $body = wp_remote_retrieve_body($confirm_response);
        $result = json_decode($body, true);

        if ($response_code !== 200) {
            $error_message = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
            return array('success' => false, 'error' => $error_message);
        }

        return array('success' => true, 'data' => $result);
    }

    /**
     * Process scheduled subscription payment (pre budúce recurring payments)
     */
    public function process_scheduled_payment($amount_to_charge, $order) {
        $this->oppio_log('OPPIO: Scheduled payment triggered for amount: ' . $amount_to_charge);
        // Toto bude implementované pre handling renewal payments v budúcnosti
    }

    /**
     * AJAX endpoint pre logovanie chyby pri platbe
     */
    public function ajax_log_payment_error() {
        $error_data = array(
            'order_id' => sanitize_text_field($_POST['order_id'] ?? ''),
            'customer_id' => sanitize_text_field($_POST['customer_id'] ?? ''),
            'error_message' => sanitize_text_field($_POST['error_message'] ?? ''),
            'error_code' => sanitize_text_field($_POST['error_code'] ?? ''),
            'error_type' => sanitize_text_field($_POST['error_type'] ?? ''),
            'page_url' => esc_url($_POST['page_url'] ?? ''),
            'user_agent' => sanitize_text_field($_POST['user_agent'] ?? ''),
            'timestamp' => sanitize_text_field($_POST['timestamp'] ?? '')
        );
        
        // Log do súboru
        $log_message = sprintf(
            '[%s] PAYMENT ERROR - Order: %s, Code: %s, Message: %s, URL: %s',
            $error_data['timestamp'],
            $error_data['order_id'],
            $error_data['error_code'],
            $error_data['error_message'],
            $error_data['page_url']
        );
        
        $this->oppio_log($log_message);
        
        // Pridaj order note ak máme order_id
        if ($error_data['order_id']) {
            $order = wc_get_order($error_data['order_id']);
            if ($order) {
                $order->add_order_note(sprintf(
                    '❌ Payment Error: %s (Code: %s)',
                    $error_data['error_message'],
                    $error_data['error_code']
                ));
            }
        }
        
        wp_send_json_success(['message' => 'Error logged successfully']);
    }

    // =====================================================================================
    // ================== Pridat stlpce do Orders tabulky vo WooCommerce ===================
    // =====================================================================================

    /**
     * Pridá stĺpec Predplatné - HPOS VERSION pre WooCommerce 9.9.5
     */
    public function add_subscription_column_to_orders() {
        // HPOS hooks pre WooCommerce 9.9.5
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_subscription_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'display_subscription_column_content'), 10, 2);
        add_action('admin_head', array($this, 'subscription_column_css'));
    }

    /**
     * Pridá stĺpec do orders tabuľky
     */
    public function add_subscription_column($columns) {
        $this->oppio_log('ORIGINAL columns: ' . print_r($columns, true));
        $new_columns = array();

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;

            // Pridaj hneď za "order_number" (Objednávka)
            if ($key === 'order_number') {
                $new_columns['oppio_subscription'] = 'Predplatné';
            }
        }

        $this->oppio_log('AFTER adding subscription column: ' . print_r($new_columns, true));
        return $new_columns;
    }

    /**
     * Lokalizuje Stripe script pre thankyou stránku
     */
    public function localize_stripe_script_for_thankyou() {
        if (!is_order_received_page()) return;
        
        $order_id = absint($_GET['order-received'] ?? 0);
        if (!$order_id) return;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Len ak je to OPPIO subscription a máme uložený PI client_secret
        if ($order->get_meta('_oppio_is_subscription') === 'yes') {
           $client_secret = $order->get_meta('_stripe_intent_secret') ?: 
                $order->get_meta('_stripe_source_id') ?: 
                $order->get_meta('_oppio_stripe_payment_intent_secret');
            if ($client_secret) {
                // Uisti sa, že už máš zaregistrovaný/enqueue-nutý tvoj JS 'oppio-subscription'
                wp_localize_script(
                    'oppio-subscription',
                    'OPPIO_SUB',
                    array(
                        'is_subscription' => true,
                        'client_secret'   => $client_secret,
                        'success_url'     => $order->get_checkout_order_received_url(),
                    )
                );
                
                $this->oppio_log('Localized Stripe script for order #' . $order_id . ' with client_secret');
            }
        }
    }

    public function enqueue_subscription_assets() {
        // Zistíme, kde sme
        $is_checkout = function_exists('is_checkout') ? is_checkout() : false;
        $is_thankyou = function_exists('is_order_received_page') ? is_order_received_page() : false;

        if ( ! $is_checkout && ! $is_thankyou ) {
            return;
        }

        // Stripe publishable key z woo nastavení
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $pk = '';
        if (!empty($stripe_settings)) {
            $pk = (!empty($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes')
                ? ($stripe_settings['test_publishable_key'] ?? '')
                : ($stripe_settings['publishable_key'] ?? '');
        }

        // Zistíme, či riešime subscription kontext
        $has_subscription = false;
        $order_id = 0;
        $customer_id = '';
        $client_secret = ''; // ✅ PRIDANÉ

        if ($is_checkout && WC()->cart) {
            // checkout – skús cart flag
            foreach (WC()->cart->get_cart() as $ci) {
                if (!empty($ci['subscription_type'])) { $has_subscription = true; break; }
            }
        }

        if ($is_thankyou) {
            // thankyou – zober order a pozri meta
            $order_id = absint( get_query_var('order-received') );
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $has_subscription = ($order->get_meta('_oppio_is_subscription') === 'yes');
                    $customer_id = $order->get_meta('_stripe_customer_id');
                    
                    // ✅ KĽÚČOVÉ: Získaj client_secret pre thankyou page
                    $client_secret = $order->get_meta('_oppio_stripe_payment_intent_secret');
                    
                    // $this->oppio_log('Thankyou page - Order ID: ' . $order_id . ', has subscription: ' . ($has_subscription ? 'yes' : 'no') . ', client_secret: ' . ($client_secret ? 'YES' : 'NO'));
                }
            }
        }

        // nič nenačítaj, ak nejde o subscription
        if (! $has_subscription) return;

        // enqueue súboru
        $handle = 'oppio-subscription';
        $src    = plugin_dir_url(__FILE__) . 'subscription.js';
        $ver    = @filemtime( plugin_dir_path(__FILE__) . 'subscription.js' ) ?: time();

        wp_enqueue_script($handle, $src, array('jquery'), $ver, true);

        // Pridaj k existujúcim premenným
        $setup_secret = '';
        if ($is_thankyou && $order) {
            $setup_secret = $order->get_meta('_oppio_setup_intent_secret');
        }

        // V sprintf pridaj novú premennú:
        $inline = sprintf(
            'window.OPPIO_IS_SUBSCRIPTION=%s;window.OPPIO_STRIPE_PK=%s;window.OPPIO_ORDER_ID=%s;window.OPPIO_CUSTOMER_ID=%s;window.OPPIO_AJAX_URL=%s;window.OPPIO_CLIENT_SECRET=%s;window.OPPIO_SETUP_SECRET=%s;',
            $has_subscription ? 'true' : 'false',
            json_encode($pk ?: ''),
            json_encode($order_id ?: ''),
            json_encode($customer_id ?: ''),
            json_encode(admin_url('admin-ajax.php')),
            json_encode($client_secret ?: ''),
            json_encode($setup_secret ?: '') // NOVÉ
        );

        wp_add_inline_script($handle, $inline, 'before');
    }

    function maybe_use_subscription_thankyou_template($template) {
        if ( ! function_exists('is_order_received_page') || ! is_order_received_page() ) {
            return $template;
        }

        $order_id = absint( get_query_var('order-received') );
        if (! $order_id) return $template;

        $order = wc_get_order($order_id);
        if (! $order) return $template;

        if ($order->get_meta('_oppio_is_subscription') !== 'yes') {
            return $template; // nie je subscription – nechaj default
        }

        // Použi tvoju šablónu len pre subscription
        $custom = plugin_dir_path(__FILE__) . 'templates/thankyou-oppio.php';
        return file_exists($custom) ? $custom : $template;
    }

    /**
     * HPOS verzia obsahu stĺpca
     */
    public function display_subscription_column_content($column, $order) {
        if ($column !== 'oppio_subscription') {
            return;
        }

        if (!$order instanceof WC_Order) {
            echo '<small style="color: red;">ERR1</small>';
            return;
        }

        $is_subscription = $order->get_meta('_oppio_is_subscription');
        $subscription_type = $order->get_meta('_oppio_subscription_type');

        // DEBUG - ukáž čo sa načítava
        // echo '<small style="font-size: 10px;">IS:' . $is_subscription . ' TYPE:' . $subscription_type . '</small>';
        
        if ($is_subscription === 'yes' && !empty($subscription_type)) {
            $labels = array(
                'monthly'   => 'Mesačné predplatné',
                'biweekly'  => '14-dňové predplatné', 
                'daily'     => 'Denné predplatné',
            );

            $tooltip = $labels[$subscription_type] ?? 'Predplatné';

            echo '<span class="oppio-subscription-icon" title="' . esc_attr($tooltip) . '" style="display: inline-block; width: 20px; height: 20px; background: #4CAF50; color: white; text-align: center; line-height: 20px; border-radius: 50%; font-size: 14px; font-weight: bold;">↻</span>';
        }
    }

    /**
     * CSS styling
     */
    public function subscription_column_css() {
        $screen = get_current_screen();
        $this->oppio_log('subscription_column_css called, screen: ' . ($screen ? $screen->id : 'null'));

        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            $this->oppio_log('Wrong screen for HPOS, not adding CSS');
            return;
        }

        $this->oppio_log('Adding CSS for HPOS subscription column');
        ?>

        <style>
        .oppio-subscription-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            text-align: center;
            line-height: 20px;
            border-radius: 50%;
            background: var(--project-green-light);
            color: white;
            font-size: 14px;
            font-weight: bold;
            cursor: help;
            animation: oppio-rotate 2s linear infinite;
        }

        @keyframes oppio-rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .oppio-subscription-icon:hover {
            background: #45a049;
            animation-duration: 0.5s;
        }

        .wp-list-table .column-oppio_subscription {
            width: 80px;
            text-align: center;
        }

        </style>
        <?php
    }

    // ????????????????????????????????????????????????????????????????????????????????????
    // ?? AJAX endpoint pre potvrdenie PaymentIntent (ak je potrebné)
    // ????????????????????????????????????????????????????????????????????????????????????
    public function ajax_oppio_confirm_pi() {
        check_ajax_referer('sca_nonce');

        $pi_id    = isset($_POST['pi_id']) ? sanitize_text_field($_POST['pi_id']) : '';
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($pi_id === '') wp_send_json_error(['message' => 'Chýba pi_id.']);

        $order = $order_id ? wc_get_order($order_id) : null;

        $s = get_option('woocommerce_stripe_settings', array());
        $test = isset($s['testmode']) && $s['testmode'] === 'yes';
        $api  = $test ? ($s['test_secret_key'] ?? '') : ($s['secret_key'] ?? '');
        if ($api === '') wp_send_json_error(['message' => 'Chýba Stripe API key.']);

        $return_url = $order ? $order->get_view_order_url() : home_url('/my-account/');

        $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id) . '/confirm', [
            'headers' => [
                'Authorization'  => 'Bearer ' . $api,
                'Stripe-Version' => '2025-07-30.basil',
                'Content-Type'   => 'application/x-www-form-urlencoded',
            ],
            'body'    => http_build_query(['return_url' => $return_url]),
            'timeout' => 30,
        ]);

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) wp_send_json_error(['message' => 'Potvrdenie PI zlyhalo', 'raw' => $body]);

        $status = strtolower((string)($body['status'] ?? ''));
        if ($order) {
            if (!empty($body['id']))           $order->update_meta_data('_oppio_stripe_pi_id', $body['id']);
            if (!empty($body['client_secret'])) $order->update_meta_data('_oppio_stripe_payment_intent_secret', $body['client_secret']);
            $order->save();
        }

        if ($status === 'succeeded' || $status === 'processing') {
            wp_send_json_success(['status' => $status, 'message' => 'Platba potvrdená.']);
        } elseif ($status === 'requires_action') {
            wp_send_json_success(['status' => $status, 'message' => 'Stále vyžaduje akciu.']);
        } else {
            wp_send_json_error(['message' => 'Platba sa nepodarila: ' . $status]);
        }
    }
    // ?????????????????????????????????????????????????????????????????????????????????????
    // ?? END AJAX endpoint pre potvrdenie PaymentIntent (ak je potrebné)
    // ?????????????????????????????????????????????????????????????????????????????????????





    // ?????????????????????????????????????????????????????????????????????
    // ?????????????????????????????????????????????????????????????????????
    // ?????????????????????????????????????????????????????????????????????
    /** 
     * Dokončiť platbu - Skúsiť znova
     * Načíta Stripe.js a malý JS handler 
     * */
    public function enqueue_retry_assets() {
        if ( ! is_user_logged_in() ) return;
        if ( ! function_exists('is_account_page') || ! is_account_page() ) return;

        // jQuery istota
        wp_enqueue_script('jquery');

        // Stripe.js (ak ho už iný plugin nenačítal, WP to ošetrí)
        wp_enqueue_script('oppio-stripe-js', 'https://js.stripe.com/v3/', [], null, true);

        // prázdny register na ktorý pripneme inline kód
        $handle = 'oppio-retry';
        wp_register_script($handle, false, ['jquery','oppio-stripe-js'], '1.0', true);
        wp_enqueue_script($handle);

        $ajax = admin_url('admin-ajax.php');

        // DEBUG + handler kliknutia
        wp_add_inline_script($handle, <<<JS
            window.OppioRetryAjax = "{$ajax}";
            (function($){
            function log(){ if(window.console){ console.log.apply(console, arguments); } }

            // signal do DOM, že skript beží
            document.documentElement.setAttribute('data-oppio-retry-ready','1');

            $(document).on('click', '.complete-payment', async function(e){
                e.preventDefault();
                var \$btn = $(this);
                var orderId = \$btn.data('order-id');
                var nonce   = \$btn.data('nonce');

                log('[OPPIO] click Dokončiť platbu', {orderId: orderId});

                if(!orderId || !nonce){ alert('Chýbajú údaje pre platbu.'); return; }

                \$btn.prop('disabled', true).addClass('is-busy').text('Prebieha…');

                try {
                const res = await $.ajax({
                    url: window.OppioRetryAjax,
                    method: 'POST',
                    data: { action:'oppio_retry_subscription', order_id: orderId, _nonce: nonce }
                });

                log('[OPPIO] init response', res);

                if(!res || res.success !== true){ 
                    alert('Inicializácia platby zlyhala: ' + (res?.data?.msg || 'neznáma chyba'));
                    \$btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu');
                    return;
                }

                if(res.data.mode === 'confirm_payment'){
                    var stripe = window.Stripe(res.data.publishable_key);
                    const out = await stripe.confirmCardPayment(res.data.client_secret);
                    if(out.error){
                        alert(out.error.message);
                        $btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu');
                        return;
                    }

                    // ➕ po úspechu povedz serveru, ktorú kartu má uložiť na subscription
                    await $.post(window.OppioRetryAjax, {
                        action: 'oppio_after_pi_confirm',
                        order_id: orderId,
                        pi_id: out.paymentIntent ? out.paymentIntent.id : '',
                        _nonce: nonce
                    });

                    location.reload();
                    return;
                }


                if(res.data.mode === 'collect_new_card'){
                    var stripe = window.Stripe(res.data.publishable_key);
                    var wrapId = 'oppio-card-el-' + orderId;
                    var \$wrap = $('#' + wrapId);
                    if(!\$wrap.length){
                    \$wrap = $('<div id="'+wrapId+'" style="margin-top:8px;padding:12px;border:1px solid #ddd;background:#fff;max-width:360px"></div>').insertAfter(\$btn);
                    }
                    var elements = stripe.elements();
                    var card = elements.create('card');
                    \$wrap.empty();
                    card.mount('#' + wrapId);

                    const out = await stripe.confirmCardSetup(res.data.setup_client_secret, { payment_method: { card }});
                    if(out.error){ alert(out.error.message); \$btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu'); return; }

                    const pay = await $.post(window.OppioRetryAjax, { action:'oppio_pay_invoice_after_si', order_id: res.data.order_id, invoice_id: res.data.invoice_id });
                    log('[OPPIO] pay after SI', pay);
                    if(!pay || pay.success !== true){ alert('Platbu sa nepodarilo dokončiť.'); \$btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu'); return; }
                    location.reload();
                    return;
                }

                if(res.data.mode === 'paid'){
                    location.reload();
                    return;
                }

                // fallback
                alert('Neznáma odpoveď servera.');
                \$btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu');

                } catch(err){
                console && console.error && console.error(err);
                alert('AJAX zlyhal. Pozri konzolu pre detaily.');
                \$btn.prop('disabled', false).removeClass('is-busy').text('Dokončiť platbu');
                }
            });
            })(jQuery);
            JS
        );
    }

    /** AJAX: inicializácia retry flow */
    public function ajax_retry_subscription() {
        if (!is_user_logged_in()) wp_send_json_error(['msg'=>'auth']);

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $nonce    = sanitize_text_field($_POST['_nonce'] ?? '');
        if (!$order_id || !wp_verify_nonce($nonce, 'oppio_retry_'.$order_id)) {
            wp_send_json_error(['msg'=>'bad_nonce']);
        }

        $order = wc_get_order($order_id);
        if (!$order || (int)$order->get_user_id() !== get_current_user_id()) {
            wp_send_json_error(['msg'=>'no_order']);
        }

        $env = $order->get_meta('_oppio_subscription_env') ?: 'live';
        $S   = get_option('woocommerce_stripe_settings', []);
        $sk  = $env==='test' ? ($S['test_secret_key'] ?? '') : ($S['secret_key'] ?? '');
        $pk  = $env==='test' ? ($S['test_publishable_key'] ?? '') : ($S['publishable_key'] ?? '');
        if (!$sk || !$pk) wp_send_json_error(['msg'=>'no_keys']);

        $subId = $order->get_meta('_oppio_stripe_subscription_id');
        if (!$subId) wp_send_json_error(['msg'=>'no_sub']);

        // posledná/open faktúra
        $inv = $this->stripe_get("/v1/invoices?subscription={$subId}&limit=1", $sk);
        if ($inv['http']!==200 || empty($inv['json']['data'][0])) wp_send_json_error(['msg'=>'no_invoice']);

        $invoice = $inv['json']['data'][0];
        $pi_id   = $invoice['payment_intent'] ?? '';
        $pi      = $pi_id ? $this->stripe_get("/v1/payment_intents/{$pi_id}", $sk) : null;
        $pi_st   = $pi && $pi['http']===200 ? ($pi['json']['status'] ?? '') : '';

        if ($pi_st === 'requires_action') {
            wp_send_json_success([
                'mode'            => 'confirm_payment',
                'publishable_key' => $pk,
                'client_secret'   => $pi['json']['client_secret'],
                'order_id'        => $order_id,
            ]);
        }

        if ($pi_st === 'requires_payment_method' || !$pi_id) {
            $cus = $invoice['customer'] ?: $order->get_meta('_stripe_customer_id');
            if (!$cus) wp_send_json_error(['msg'=>'no_customer']);

            $si = $this->stripe_post('/v1/setup_intents', $sk, [
                'customer' => $cus,
                'payment_method_types[]' => 'card',
                'usage' => 'off_session',
            ]);
            if ($si['http']!==200) wp_send_json_error(['msg'=>'si_fail']);

            wp_send_json_success([
                'mode'                => 'collect_new_card',
                'publishable_key'     => $pk,
                'setup_client_secret' => $si['json']['client_secret'],
                'invoice_id'          => $invoice['id'],
                'order_id'            => $order_id,
            ]);
        }

        // iná open invoice → pokus o pay
        $pay = $this->stripe_post("/v1/invoices/{$invoice['id']}/pay", $sk, []);
        if (($pay['http'] ?? 0)===200) {
            // okamžite prepnúť parent ORDER do ACTIVE
            $order->update_meta_data('_oppio_subscription_status', 'active');
            $order->delete_meta_data('_oppio_sca_redirect_url');
            $order->delete_meta_data('_oppio_stripe_payment_intent_secret');
            $order->update_meta_data('_oppio_status_last_check', current_time('mysql'));
            $order->save();
            $order->add_order_note('✅ Dokončené cez „Skúsiť znova“ – označené ako ACTIVE (webhook môže doraziť neskôr).');
            
            wp_send_json_success(['mode'=>'paid']);
        }
        wp_send_json_error(['msg'=>'pay_failed','http'=>$pay['http'] ?? 0]);
    }

    /** AJAX: zaplatenie po uložení novej karty (SetupIntent) */
    public function ajax_pay_invoice_after_si() {
        if (!is_user_logged_in()) wp_send_json_error();

        $order_id   = isset($_POST['order_id'])   ? (int) $_POST['order_id']   : 0;
        $invoice_id = isset($_POST['invoice_id']) ? sanitize_text_field($_POST['invoice_id']) : '';
        $order = wc_get_order($order_id); 
        if (!$order || (int)$order->get_user_id() !== get_current_user_id()) wp_send_json_error();

        $env = $order->get_meta('_oppio_subscription_env') ?: 'live';
        $S   = get_option('woocommerce_stripe_settings',[]);
        $sk  = $env==='test' ? ($S['test_secret_key'] ?? '') : ($S['secret_key'] ?? '');

        $pay = $this->stripe_post("/v1/invoices/{$invoice_id}/pay", $sk, []);
        if (($pay['http'] ?? 0)===200) {
            $order->update_meta_data('_oppio_subscription_status', 'active');
            $order->delete_meta_data('_oppio_sca_redirect_url');
            $order->delete_meta_data('_oppio_stripe_payment_intent_secret');
            $order->update_meta_data('_oppio_status_last_check', current_time('mysql'));
            $order->save();
            $order->add_order_note('✅ Dokončené po uložení novej karty – ACTIVE.');

            wp_send_json_success();
        }
        wp_send_json_error($pay);
    }

    public function ajax_after_pi_confirm() {
        if (!is_user_logged_in()) wp_send_json_error(['msg'=>'auth']);

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $pi_id    = sanitize_text_field($_POST['pi_id'] ?? '');
        $nonce    = sanitize_text_field($_POST['_nonce'] ?? '');

        if (!$order_id || !$pi_id || !wp_verify_nonce($nonce, 'oppio_retry_'.$order_id)) {
            wp_send_json_error(['msg'=>'bad_params']);
        }

        $order = wc_get_order($order_id);
        if (!$order || (int)$order->get_user_id() !== get_current_user_id()) {
            wp_send_json_error(['msg'=>'no_order']);
        }

        $env = $order->get_meta('_oppio_subscription_env') ?: 'live';
        $S   = get_option('woocommerce_stripe_settings', []);
        $sk  = $env==='test' ? ($S['test_secret_key'] ?? '') : ($S['secret_key'] ?? '');
        if (!$sk) wp_send_json_error(['msg'=>'no_keys']);

        $subId = $order->get_meta('_oppio_stripe_subscription_id');
        if (!$subId) wp_send_json_error(['msg'=>'no_sub']);

        // 1) zisti payment_method z PaymentIntentu
        $pi = $this->stripe_get("/v1/payment_intents/{$pi_id}", $sk);
        if (($pi['http'] ?? 0) !== 200) wp_send_json_error(['msg'=>'pi_fetch_failed']);
        $pm = $pi['json']['payment_method'] ?? '';
        if (!$pm) wp_send_json_error(['msg'=>'no_pm']);

        // 2) nastav default kartu na SUBSCRIPTION (kľúčové)
        $upd = $this->stripe_post("/v1/subscriptions/{$subId}", $sk, [
            'default_payment_method' => $pm,
            'payment_settings[save_default_payment_method]' => 'on_subscription',
        ]);

        // voliteľne: nastav to isté aj na zákazníka (pomáha pri ďalších zakupeniach)
        $cus = $order->get_meta('_oppio_stripe_customer_id');
        if ($cus) {
            $this->stripe_post("/v1/customers/{$cus}", $sk, [
                'invoice_settings[default_payment_method]' => $pm,
            ]);
        }

        // housekeeping na objednávke
        $order->update_meta_data('_oppio_subscription_status', 'active');
        $order->delete_meta_data('_oppio_sca_redirect_url');
        $order->delete_meta_data('_oppio_stripe_payment_intent_secret');
        $order->update_meta_data('_oppio_status_last_check', current_time('mysql'));
        $order->add_order_note('✅ 3DS hotové – default karta uložená na subscription.');
        $order->save();

        wp_send_json_success(['ok'=>true]);
    }
    // ?????????????????????????????????????????????????????????????????????
    // ?????????????????????????????????????????????????????????????????????
    // ?????????????????????????????????????????????????????????????????????

    /** PRIVATE: Stripe GET/POST */
    private function stripe_get($path, $sk) {
        $r = wp_remote_get('https://api.stripe.com'.$path, [
            'headers'=>['Authorization'=>'Bearer '.$sk,'Stripe-Version'=>'2024-06-20'],
            'timeout'=>20,
        ]);
        if (is_wp_error($r)) return ['http'=>0,'json'=>['error'=>$r->get_error_message()]];
        return ['http'=>(int)wp_remote_retrieve_response_code($r), 'json'=>json_decode(wp_remote_retrieve_body($r), true)];
    }

    private function stripe_post($path, $sk, $data) {
        $r = wp_remote_post('https://api.stripe.com'.$path, [
            'headers'=>['Authorization'=>'Bearer '.$sk,'Stripe-Version'=>'2024-06-20'],
            'timeout'=>20, 'body'=>$data,
        ]);
        if (is_wp_error($r)) return ['http'=>0,'json'=>['error'=>$r->get_error_message()]];
        return ['http'=>(int)wp_remote_retrieve_response_code($r), 'json'=>json_decode(wp_remote_retrieve_body($r), true)];
    }



    // ===============================================================
    // Funkcie vytvorenia novej objednavky pri obnoveni predpaltneho
    // ===============================================================
    /**
     * Pomocná funkcia: vráti deliteľ pre menové "centy" zo Stripe.
     * Väčšina mien má 2 desatinné miesta => delíme 100.
     * Nulové (JPY, KRW...) => delíme 1.
     */
    private function oppio_minor_unit_divisor($currency) {
        $currency = strtoupper((string) $currency);

        $zero_decimal = array(
            'BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA',
            'PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'
        );

        return in_array($currency, $zero_decimal, true) ? 1 : 100;
    }

    /**
     * Vytvorí novú objednávku podľa Stripe invoice
     * @param WC_Order $parent_order – pôvodná objednávka, z ktorej vychádzame
     * @param array $invoice – Stripe invoice objekt (pole)
     * @return WC_Order|false – nová objednávka alebo false pri chybe
     */
    private function create_renewal_order_from_invoice($parent_order, $invoice) {
        $this->oppio_renew_log('=== RENEWAL CREATE (from invoice) ===');

        if (!$parent_order instanceof \WC_Order) {
            $this->oppio_renew_log('Parent order missing, abort.');
            return false;
        }

        $invoice_id = $invoice['id'] ?? '';
        if (!$invoice_id) {
            $this->oppio_renew_log('Invoice ID missing, abort.');
            return false;
        }

        // 🛑 druhý zámok proti 0 €
        $invoice_total_minor      = (int)($invoice['total'] ?? 0);
        $invoice_amount_due_minor = (int)($invoice['amount_due'] ?? $invoice_total_minor);
        if ($invoice_total_minor <= 0 || $invoice_amount_due_minor <= 0) {
            $this->oppio_renew_log('Skip renewal: zero-value invoice (total=' . $invoice_total_minor . ', due=' . $invoice_amount_due_minor . ').');
            return false;
        }

        // idempotencia
        $exists = wc_get_orders([
            'meta_key'   => '_oppio_stripe_invoice_id',
            'meta_value' => $invoice_id,
            'limit'      => 1,
            'status'     => array_keys(wc_get_order_statuses()),
        ]);
        if ($exists) {
            $this->oppio_renew_log('Renewal already exists: #'.$exists[0]->get_id());
            return $exists[0];
        }

        $currency    = strtoupper($invoice['currency'] ?? '') ?: $parent_order->get_currency();
        $divisor     = method_exists($this, 'oppio_minor_unit_divisor') ? $this->oppio_minor_unit_divisor($currency) : 100;
        $lines       = $invoice['lines']['data'] ?? [];
        $customer_id = (int) $parent_order->get_customer_id();

        // 1) nová objednávka
        $order = wc_create_order(['customer_id' => $customer_id]);
        $order->set_currency($currency);

        // adresy
        $order->set_address($parent_order->get_address('billing'), 'billing');
        $order->set_address($parent_order->get_address('shipping'), 'shipping');

        // 2) položky
        foreach ($lines as $line) {
            // presnejšia suma – Stripe môže mať amount_total
            $amount_minor = (int)($line['amount'] ?? $line['amount_total'] ?? 0);
            $qty          = max(1, (int)($line['quantity'] ?? 1));
            $line_total   = $divisor ? ($amount_minor / $divisor) : 0.0;

            $oppio_pid = !empty($line['metadata']['oppio_product_id']) ? (int)$line['metadata']['oppio_product_id'] : 0;
            $desc      = $line['description'] ?? 'Obnova (stripe)';

            if ($oppio_pid && ($product = wc_get_product($oppio_pid))) {
                $item = new \WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_name($product->get_name());
                $item->set_quantity($qty);
                $item->set_total($line_total);
                $order->add_item($item);
            } else {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name($desc);
                $fee->set_amount($line_total);
                $fee->set_total($line_total);
                $order->add_item($fee);
            }
        }

        // 3) meta prepojenia
        $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id);
        $order->update_meta_data('_oppio_parent_order_id', $parent_order->get_id());
        $order->update_meta_data('_oppio_subscription_type', $parent_order->get_meta('_oppio_subscription_type'));
        $order->update_meta_data('_oppio_stripe_subscription_id', $parent_order->get_meta('_oppio_stripe_subscription_id'));
        $order->update_meta_data('_oppio_subscription_env', $parent_order->get_meta('_oppio_subscription_env'));
        if ($parent_order->get_meta('_oppio_stripe_customer_id')) {
            $order->update_meta_data('_oppio_stripe_customer_id', $parent_order->get_meta('_oppio_stripe_customer_id'));
        }

        // 4) platobná brána
        $order->set_payment_method('stripe');
        $order->set_payment_method_title('Stripe – obnova predplatného');

        // 5) totals + (voliteľne) označiť ako zaplatené
        $order->calculate_totals();

        // ak chceš mať stav rovno "paid":
        $pi_for_txn = $invoice['payment_intent']['id'] ?? ($invoice['payment_intent'] ?? '');
        if ($pi_for_txn) {
            $order->payment_complete($pi_for_txn); // Woo spraví „paid“ flow
        } else {
            $order->update_status('processing');   // fallback
        }

        $order->add_order_note('🧾 Renewal vytvorený zo Stripe invoice '.$invoice_id.'.');
        $order->save();

        $this->oppio_renew_log('Renewal created: #'.$order->get_id().' total='.$order->get_total().' '.$currency);
        return $order;
    }


    /**
     * Pridaj admin menu pre webhook nastavenia
     */
    public function add_webhook_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'OPPIO Webhook Settings',
            'OPPIO Webhooks',
            'manage_options',
            'oppio-webhooks',
            array($this, 'webhook_admin_page')
        );
    }

    /**
     * Admin stránka pre webhook nastavenia
     */
    public function webhook_admin_page() {
        // Spracuj formulár
        if (isset($_POST['save_webhook_settings'])) {
            $test_secret = sanitize_text_field($_POST['test_webhook_secret']);
            $live_secret = sanitize_text_field($_POST['live_webhook_secret']);

            update_option('oppio_stripe_test_webhook_secret', $test_secret);
            update_option('oppio_stripe_live_webhook_secret', $live_secret);

            echo '<div class="notice notice-success"><p>Webhook secrets uložené!</p></div>';
        }

        
        $test_secret = get_option('oppio_stripe_test_webhook_secret', '');
        $live_secret = get_option('oppio_stripe_live_webhook_secret', '');
        $webhook_url = home_url('/wp-json/oppio/v1/stripe-webhook');
        ?>

        <div class="wrap">
            <h1>OPPIO Webhook Settings</h1>

            <div class="card" style="max-width: 800px;">
                <h2>Stripe Webhook Endpoint</h2>
                <p><strong>URL pre Stripe Dashboard:</strong></p>
                <code style="background: #f1f1f1; padding: 10px; display: block; margin: 10px 0;">
                    <?php echo esc_url($webhook_url); ?>
                </code>
            </div>

            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Test Webhook Secret</th>
                        <td>
                            <input type="password" name="test_webhook_secret" value="<?php echo esc_attr($test_secret); ?>" class="regular-text" placeholder="whsec_..." />
                            <p class="description">Webhook secret pre test mode</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Live Webhook Secret</th>
                        <td>
                            <input type="password" name="live_webhook_secret" value="<?php echo esc_attr($live_secret); ?>" class="regular-text" placeholder="whsec_..." />
                            <p class="description">Webhook secret pre LIVE mode</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Uložiť nastavenia', 'primary', 'save_webhook_settings'); ?>
            </form>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h3>Testovanie</h3>
                <p><strong>Webhook URL:</strong> <a href="<?php echo esc_url($webhook_url); ?>" target="_blank"><?php echo esc_url($webhook_url); ?></a></p>
                <p><strong>Log súbor:</strong> <code>/wp-content/plugins/oppio-subscription-manager/oppio-debug.log</code></p>
            </div>
        </div>
        <?php
    }

    /**
     * Zobraz upozornenie na Stripe režim
     */
    public function show_stripe_mode_notice() {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'woocommerce') !== false) {
            $stripe_settings = get_option('woocommerce_stripe_settings', array());
            $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

            if ($test_mode) {
                echo '<div class="notice notice-warning"><p><strong>🧪 OPPIO Subscriptions:</strong> Stripe je v TEST MODE</p></div>';
            } 
            else {
                echo '<div class="notice notice-success"><p><strong>🚀 OPPIO Subscriptions:</strong> Stripe je v LIVE MODE</p></div>';
            }
        }
    }

    // ======================================================================================
    // Automaticke vytvorenie uctu zakaznikovy ak vytvory PREDPLATNE a nema este ucet
    // ======================================================================================

    /**
     * Pošle email o vytvorení účtu PO vytvorení objednávky
     */
    public function send_account_email_after_order($order_id, $posted_data, $order) {
        $this->oppio_log('=== ORDER PROCESSED - CHECKING FOR NEW ACCOUNT ===');
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            $this->oppio_log('No customer ID - guest order');
            return;
        }
        
        $user = get_user_by('ID', $customer_id);
        if (!$user) {
            $this->oppio_log('User not found: ' . $customer_id);
            return;
        }
        
        // Skontroluj či je to nový účet (vytvorený dnes)
        $user_registered = strtotime($user->user_registered);
        $today = strtotime('today');
        
        if ($user_registered < $today) {
            $this->oppio_log('User is not new - account created before today');
            return;
        }
        
        $this->oppio_log('NEW USER DETECTED - sending account email');
        
        // Skontroluj či je subscription
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        $is_subscription = !empty($subscription_type);
        
        $this->oppio_log('Is subscription: ' . ($is_subscription ? 'YES' : 'NO'));
        
        // Pošli správny email
        if ($is_subscription) {
            $this->send_subscription_account_email($user, $order);
        } else {
            $this->send_regular_account_email($user, $order);
        }
    }

    /**
     * Pošle subscription email s WooCommerce template a prekladateľnými textami
     */
    private function send_subscription_account_email($user, $order) {
        $this->oppio_log('SENDING SUBSCRIPTION ACCOUNT EMAIL WITH TEMPLATE');
    
        $first_name = $user->first_name ?: __('Zákazník', 'oppio');
        $subject = __('Tvoj zákaznícky účet na oppio.sk bol vytvorený', 'oppio');
    
        // ✅ OBSAH EMAILU s prekladateľnými textami
        $message = sprintf(__('Ahoj %s!', 'oppio'), $first_name) . "<br><br>";
        $message .= __('Ďakujeme, že si si vybral našu kombuchu formou predplatného - teší nás, že si s nami! 🙌', 'oppio') . "<br><br>";
        $message .= __('Svoje predplatné máš teraz <strong>úplne pod kontrolou</strong> - môžeš ho <strong>upraviť, zmeniť alebo zrušiť</strong>,', 'oppio') . "<br>";
        $message .= __('kedykoľvek budeš chcieť vo svojom účte:', 'oppio') . "<br>";
        $message .= sprintf(__('👉 <a href="%s">Môj účet</a>', 'oppio'), home_url() . "/moj-ucet") . "<br><br>";
        $message .= sprintf(__('Používateľské meno: %s', 'oppio'), $user->user_email) . "<br><br>";
        $message .= sprintf(
            __('Najskôr si ale prosím nastav nové heslo <a href="%s">tu</a>.', 'oppio'), 
            wp_lostpassword_url()
        ) . "<br>";
        $message .= sprintf(__('🔒 <a href="%s">Obnoviť heslo</a>', 'oppio'), wp_lostpassword_url()) . "<br><br>";
        $message .= __('Ďakujeme za podporu 💌', 'oppio') . "<br><br>";
        $message .= __('Tím OPPIO', 'oppio');
    
        // ✅ POUŽIJ WOOCOMMERCE MAILER S TEMPLATE
        $mailer = WC()->mailer();
    
        // Zabal do WooCommerce template s hlavičkou a pätičkou
        $wrapped_message = $mailer->wrap_message($subject, $message);
    
        // Pošli s WooCommerce štýlom
        $sent = $mailer->send(
            $user->user_email,
            $subject,
            $wrapped_message,
            "Content-Type: text/html; charset=UTF-8\r\n"
        );
    
        $this->oppio_log('Subscription email with template sent: ' . ($sent ? 'SUCCESS' : 'FAILED'));
    }

    /**
     * Pošle bežný account email
     */
    private function send_regular_account_email($user, $order) {
        $this->oppio_log('SENDING REGULAR ACCOUNT EMAIL');
        
        // Použij štandardný WooCommerce email template
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        
        if (isset($emails['WC_Email_Customer_New_Account'])) {
            $email = $emails['WC_Email_Customer_New_Account'];
            $email->trigger($user->ID, '', true);
            $this->oppio_log('Regular WooCommerce email sent');
        }
    }

    /**
     * OPPIO THANK YOU Page Subscription Sekcie
     * Pridať do OPPIO_Subscription_Manager class
     */
    /**
     * Prebije WooCommerce "order received" stránku našou šablónou.
     */
    public function override_thankyou_template($template) {
        // PRIDAJ DEBUG
        // $this->oppio_log('Template filter called. Current template: ' . $template);
        
        if ($this->is_thankyou_page()) {
            $custom_template = plugin_dir_path(__FILE__) . 'templates/thankyou-oppio.php';
            
            if (file_exists($custom_template)) {
                // $this->oppio_log('RETURNING custom template: ' . $custom_template);
                
                // ✅ KRITICKÉ: Odstráň všetky WooCommerce thankyou hooks
                remove_all_actions('woocommerce_thankyou');
                
                return $custom_template;
            } else {
                $this->oppio_log('ERROR: Custom template not found: ' . $custom_template);
            }
        }
        
        // $this->oppio_log('Using default template: ' . $template);
        return $template;
    }

    /**
     * Vylepšená detekcia thankyou page
     */
    private function is_thankyou_page() {
        // 1. WooCommerce endpoint
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return true;
        }
        
        // 2. Fallback cez query vars
        global $wp;
        if (isset($wp->query_vars['order-received']) && !empty($wp->query_vars['order-received'])) {
            return true;
        }
        
        // 3. URL pattern fallback
        if (isset($_SERVER['REQUEST_URI']) && 
            (strpos($_SERVER['REQUEST_URI'], '/order-received/') !== false || 
            strpos($_SERVER['REQUEST_URI'], 'order-received') !== false)) {
            return true;
        }
        
        return false;
    }

    /**
     * Pridá body class pre všetky thankyou stránky (užitočné pre styling/selektory).
     */
    public function add_custom_thankyou_body_class($classes) {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) {
            return $classes;
        }
        
        $classes[] = 'oppio-thankyou';
        
        // Voliteľne rozlíšime subscription/onetime podľa nášho meta flagu
        global $wp;
        $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $is_subscription = $order->get_meta('_oppio_is_subscription') === 'yes';
                $classes[] = $is_subscription ? 'oppio-thankyou-subscription' : 'oppio-thankyou-onetime';
                
                // $this->oppio_log('Added thankyou body classes for order #' . $order_id . ' (subscription: ' . ($is_subscription ? 'yes' : 'no') . ')');
            }
        }
        
        return $classes;
    }

    /**
     * Vráti HTML content pre subscription sekcie
     * THANKY YOU
     */
    private function get_subscription_content_html($order) {
        ob_start();

        // WooCommerce štýl container
        echo '<div class="custom__thankyou" style="max-width: 1200px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">';

            // Header s confirmation
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
            echo '<div class="thankyou-columns" style="display: flex; gap: 30px; margin-bottom: 30px;">';

                // LEFT COLUMN - Subscription info (namiesto mapy)
                echo '<div style="flex: 1; background: #f8f9fa; border-radius: 8px; overflow: hidden;">';
                    $this->render_subscription_info_wc_style($order);
                echo '</div>';

                // RIGHT COLUMN - Order summary
                echo '<div class="order-list">';
                    $this->render_order_summary_wc_style($order);
                echo '</div>';
                
            echo '</div>'; // End main content

            // Bottom section - Order details
            echo '<div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
                $this->render_order_details_wc_style($order);
            echo '</div>';

        echo '</div>'; // End container

        return ob_get_clean();
    }

    /**
     * HELPER METHODS - ZACHOVANÉ
     */
    private function get_subscription_display_info($subscription_type) {
        $labels = array(
            'monthly'   => array('label' => __('Mesačné predplatné', 'oppio-subscriptions'), 'period' => __('každý mesiac', 'oppio-subscriptions')),
            'biweekly'  => array('label' => __('14-dňové predplatné', 'oppio-subscriptions'), 'period' => __('každých 14 dní', 'oppio-subscriptions')),
            'daily'     => array('label' => __('Denné predplatné', 'oppio-subscriptions'), 'period' => __('denne', 'oppio-subscriptions')),
        );

        return $labels[$subscription_type] ?? array('label' => __('Predplatné', 'oppio-subscriptions'), 'period' => __('pravidelne', 'oppio-subscriptions'));
    }

    private function calculate_next_delivery_date($subscription_type) {
        $intervals = array(
            'monthly'   => '+1 month',
            'biweekly'  => '+14 days', 
            'daily'     => '+1 day',
        );
        
        $interval = $intervals[$subscription_type] ?? '+1 month';

        // Začiatok doručenia = dnes + interval + 2 dni
        $start_timestamp = strtotime($interval . ' +2 days');

        // Koniec doručenia = začiatok + 5 dní
        $end_timestamp = strtotime('+5 days', $start_timestamp);

        // Výstup ako: 10.08.2025 - 15.08.2025
        return date('d.m.Y', $start_timestamp) . ' - ' . date('d.m.Y', $end_timestamp);
    }

    private function calculate_subscription_savings($order, $subscription_type) {
        // 1) percentá podľa typu
        $savings_percent = 0;
        switch ($subscription_type) {
            case 'monthly':  $savings_percent = 15; break;
            case 'biweekly': $savings_percent = 20; break;
            case 'daily':    $savings_percent = 10; break;
        }

        // 2) rovnaká logika ako pri zobrazení "Zľava predplatné"
        $display_incl_tax = ( 'incl' === get_option('woocommerce_tax_display_cart') );

        $discount_amount = 0.0;
        foreach ($order->get_fees() as $fee) {
            $fee_total_excl = (float) $fee->get_total();          // hodnota bez DPH (negatívna pri zľave)
            $fee_total_incl = $fee_total_excl + (float) $fee->get_total_tax(); // hodnota s DPH

            // vyber hodnotu podľa nastavenia zobrazenia cien (rovnako ako na thankyou)
            $value = $display_incl_tax ? $fee_total_incl : $fee_total_excl;

            // rátame len zľavy (negatívne hodnoty) a ukladáme ich ako kladnú "ušetrenú sumu"
            if ($value < 0) {
                $discount_amount += abs($value);
            }
        }

        return array(
            'percent' => $savings_percent,
            'amount'  => $discount_amount
        );
    }


    /**
     * PRIDAJ METÓDU render_subscription_info_wc_style:
     */
    private function render_subscription_info_wc_style($order) {
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        $subscription_info = $this->get_subscription_display_info($subscription_type);
        $next_delivery = $this->calculate_next_delivery_date($subscription_type);
        $savings_info = $this->calculate_subscription_savings($order, $subscription_type);

        echo '<div style="padding: 20px;">';

            // Main message
            echo '<h3 style="margin: 0 0 15px 0; color: #194A37; font-size: 18px;">🎉 ' . __('Vaše predplatné je aktívne', 'oppio-subscriptions') . '</h3>';
            echo '<p style="margin: 0 0 20px 0; color: #666;">' . __('Tvoja objednávka je potvrdená a bude doručovaná pravidelne.', 'oppio-subscriptions') . '</p>';

            // Subscription details
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin-bottom: 15px;">';
                echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">📦 ' . esc_html($subscription_info['label']) . '</h4>';
                if ($savings_info['percent'] > 0) {
                    echo '<p style="margin: 0 0 10px 0; color: #194A37; font-weight: 600;">💰 ' . __('Ušetrili ste ', 'oppio-subscriptions') . '' . $savings_info['percent'] . '% (' . wc_price($savings_info['amount']) . ')</p>';
                }
                echo '<p style="margin: 0; color: #666;">🚚 ' . __('Ďalšie dodanie: ', 'oppio-subscriptions') . '<strong>' . esc_html($next_delivery) . '</strong></p>';
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
                        ' . __('Spravovať predplatné', 'oppio-subscriptions') . '</a>';
                } 
                else {
                    $login_url = wc_get_page_permalink('myaccount');
                    echo '<p style="margin: 0 0 10px 0; color: #666;">📧 ' . __('Prihlasovacie údaje odoslané na: ', 'oppio-subscriptions') . '<br><strong>' . esc_html($order->get_billing_email()) . '</strong></p>';
                    echo '<a href="' . esc_url($login_url) . '" style="display: inline-block; background: #194A37; color: #FDFBEF !important; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 14px;">
                        ' . __('Prihlásiť sa', 'oppio-subscriptions') . '</a>';
                }
            echo '</div>';

        echo '</div>';
    }

    /**
     * PRIDAJ METÓDU render_order_summary_wc_style:
     */
    private function render_order_summary_wc_style($order) {
        echo '<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';

        // Products
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                echo '<div style="display: flex; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee;">';
                    // Product image placeholder
                    echo '<div style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 4px; margin-right: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px;">📦</div>';

                    echo '<div style="flex: 1;">';
                        echo '<div style="font-weight: 600; color: #333; font-size: 14px;">' . esc_html($item->get_name()) . '</div>';
                        echo '<div style="color: #666; font-size: 12px;">Quantity: ' . $item->get_quantity() . '</div>';
                    echo '</div>';

                    // 🔹 cena bez zľavy, za 1 ks
                    // zobrazujeme ceny rovnako ako košík/pokladňa
                    $display_incl_tax = ( 'incl' === get_option('woocommerce_tax_display_cart') );

                    // množstvo
                    $qty = max(1, (int) $item->get_quantity());

                    // radšej si pripravíme súčty s/bez DPH podľa nastavenia
                    $line_subtotal_ex = (float) $item->get_subtotal();        // bez DPH
                    $line_subtotal_tax = (float) $item->get_subtotal_tax();   // DPH k subtotal

                    if ( $display_incl_tax ) {
                        // jednotková cena S DPH (presne ako na checkoute)
                        $unit_price = ($line_subtotal_ex + $line_subtotal_tax) / $qty;
                    } else {
                        // jednotková cena bez DPH
                        $unit_price = $line_subtotal_ex / $qty;
                    }

                    echo '<div style="font-weight: 600; color: #333;">' . wc_price($unit_price) . '</div>';


                echo '</div>';
            }
        }

        // Totals section
        echo '<div style="border-top: 2px solid #eee; padding-top: 15px;">';

            // Subtotal (bez zliav)
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                echo '<span style="color: #666;">' . __('Medzisúčet', 'oppio-subscriptions') . '</span>';

                $display_incl_tax = ( 'incl' === get_option('woocommerce_tax_display_cart') );

                if ( $display_incl_tax ) {
                    // spočítame položky vrátane DPH (bez fee a dopravy) – ako checkout
                    $items_subtotal_incl = 0.0;
                    foreach ( $order->get_items() as $it ) {
                        $items_subtotal_incl += (float) $it->get_subtotal() + (float) $it->get_subtotal_tax();
                    }
                    echo '<span style="color: #333;">' . wc_price($items_subtotal_incl) . '</span>';
                } else {
                    // bez DPH
                    echo '<span style="color: #333;">' . wc_price($order->get_subtotal()) . '</span>';
                }

            echo '</div>';

            // Fees (zľavy ako negatívne fee)
            $display_incl_tax = ( 'incl' === get_option('woocommerce_tax_display_cart') );
            foreach ( $order->get_fees() as $fee ) {
                $fee_total = (float) $fee->get_total();
                if ( $display_incl_tax ) {
                    $fee_total += (float) $fee->get_total_tax(); // zahrni DPH, ak je
                }
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                    echo '<span style="color: #194A37;">' . __('Zľava predplatné', 'oppio-subscriptions') . '</span>';
                    echo '<span style="color: #194A37;">' . wc_price($fee_total) . '</span>';
                echo '</div>';
            }


            // Shipping (ak je > 0)
            if ($order->get_shipping_total() > 0) {
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                    echo '<span style="color: #666;">' . __('Doprava', 'oppio-subscriptions') . '</span>';
                    echo '<span style="color: #333;">' . wc_price($order->get_shipping_total()) . '</span>';
                echo '</div>';
            }

            // Total (zaplatená suma)
            echo '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 16px; padding-top: 8px; border-top: 1px solid #eee;">';
                echo '<span>' . __('Spolu', 'oppio-subscriptions') . '</span>';
                echo '<span style="color: #333;">' . wc_price($order->get_total()) . '</span>';
            echo '</div>';

        echo '</div>'; // End totals
        echo '</div>'; // End summary
    }


    /**
     * PRIDAJ METÓDU render_order_details_wc_style:
     */
    private function render_order_details_wc_style($order) {
        echo '<div style="padding: 20px;">';
            echo '<h3 style="margin: 0 0 20px 0; color: #333; font-size: 18px;">' . __('Podrobnosti objednávky', 'oppio-subscriptions') . '</h3>';

            echo '<div style="display: flex; gap: 30px;">';

                // Contact information
                echo '<div style="flex: 1;">';
                    echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px; font-weight: 600;">' . __('Kontaktné informácie', 'oppio-subscriptions') . '</h4>';
                    echo '<p style="margin: 0; color: #666; font-size: 14px;">' . esc_html($order->get_billing_email()) . '</p>';
                echo '</div>';

                // Payment method
                echo '<div style="flex: 1;">';
                    echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px; font-weight: 600;">' . __('Spôsob platby', 'oppio-subscriptions') . '</h4>';
                    echo '<div style="display: flex; align-items: center;">';
                    echo '<div style="width: 20px; height: 14px; background: #FF6B35; border-radius: 2px; margin-right: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 8px; font-weight: bold;">S</div>';
                        echo '<span style="color: #666; font-size: 14px;">•••• • ' . $order->get_formatted_order_total() . '</span>';
                    echo '</div>';
                echo '</div>';

            echo '</div>'; // End first row

            echo '<div style="display: flex; gap: 30px; margin-top: 20px;">';

                // Shipping address
                echo '<div style="flex: 1;">';
                    echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px; font-weight: 600;">' . __('Dodacia adresa', 'oppio-subscriptions') . '</h4>';
                    echo '<div style="color: #666; font-size: 14px; line-height: 1.4;">';
                        echo esc_html($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()) . '<br>';
                        echo esc_html($order->get_shipping_address_1()) . '<br>';
                        echo esc_html($order->get_shipping_postcode() . ' ' . $order->get_shipping_city()) . '<br>';
                        echo esc_html($order->get_shipping_country());
                    echo '</div>';
                echo '</div>';

                // Billing address  
                echo '<div style="flex: 1;">';
                    echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px; font-weight: 600;">' . __('Fakturačná adresa', 'oppio-subscriptions') . '</h4>';
                    echo '<div style="color: #666; font-size: 14px; line-height: 1.4;">';
                        echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '<br>';
                        echo esc_html($order->get_billing_address_1()) . '<br>';
                        echo esc_html($order->get_billing_postcode() . ' ' . $order->get_billing_city()) . '<br>';
                        echo esc_html($order->get_billing_country());
                    echo '</div>';
                echo '</div>';

            echo '</div>'; // End second row

            // Shipping method
            echo '<div style="margin-top: 20px;">';
                echo '<h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px; font-weight: 600;">' . __('Spôsob dopravy', 'oppio-subscriptions') . '</h4>';
                foreach ($order->get_shipping_methods() as $shipping_method) {
                    echo '<p style="margin: 0; color: #666; font-size: 14px;">' . esc_html($shipping_method->get_name()) . '</p>';
                }
                if (empty($order->get_shipping_methods())) {
                    echo '<p style="margin: 0; color: #666; font-size: 14px;">' . __('Štandardná', 'oppio-subscriptions') . '</p>';
                }
            echo '</div>';

        echo '</div>'; // End details container
    }

}

// Inicializácia pluginu
new OPPIO_Subscription_Manager();

add_action(
    'wp_print_scripts',
    function () {
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            global $wp_scripts;
            foreach ( $wp_scripts->queue as $handle ) {
                error_log( 'SCRIPT: ' . $handle );
            }
        }
    },
    PHP_INT_MAX
);

add_action('wp_enqueue_scripts', function () {
  if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
  }
}, 1);

// 1) AJAX handler na získanie SetupIntent client_secret z meta objednávky
// --- admin-ajax (ak by sa odblokoval) ---
// /?wc-ajax=oppio_get_setup_secret  (funguje aj pre neprihlásených)
add_action('wc_ajax_oppio_get_setup_secret',        'oppio_get_setup_secret_handler');
add_action('wc_ajax_nopriv_oppio_get_setup_secret', 'oppio_get_setup_secret_handler');
add_action('wp_ajax_nopriv_oppio_get_setup_secret', 'oppio_get_setup_secret_handler');
add_action('wp_ajax_oppio_get_setup_secret',        'oppio_get_setup_secret_handler');
function oppio_get_setup_secret_handler(){
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) wp_send_json_error(['message'=>'missing order_id'], 400);
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error(['message'=>'order not found'], 404);

    $si = $order->get_meta('_oppio_setup_client_secret', true);
    if (empty($si)) $si = $order->get_meta('_oppio_setup_intent_secret', true);

    if ($si) wp_send_json_success(['setup_secret'=>$si], 200);
    wp_send_json_error(['message'=>'not ready'], 202);
}

// --- REST fallback: /wp-json/oppio/v1/setup-secret?order_id=123 ---
add_action('rest_api_init', function () {
    register_rest_route('oppio/v1', '/setup-secret', [
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $order_id = absint($req->get_param('order_id'));
            if (!$order_id) return new WP_REST_Response(['success'=>false,'message'=>'missing order_id'], 400);
            $order = wc_get_order($order_id);
            if (!$order)  return new WP_REST_Response(['success'=>false,'message'=>'order not found'], 404);

            $si = $order->get_meta('_oppio_setup_client_secret', true);
            if (empty($si)) $si = $order->get_meta('_oppio_setup_intent_secret', true);

            if ($si) return new WP_REST_Response(['success'=>true,'setup_secret'=>$si], 200);
            return new WP_REST_Response(['success'=>false], 202);
        }
    ]);
});
