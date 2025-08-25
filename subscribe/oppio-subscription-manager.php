<?php
/**
 * Plugin Name: OPPIO Subscription Manager NEROZDELENE
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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_account_scripts'), 10);
        
        // ✅ VŠETKY AJAX HANDLERY
        add_action('wp_ajax_check_cart_status', array($this, 'ajax_check_cart_status'));
        add_action('wp_ajax_nopriv_check_cart_status', array($this, 'ajax_check_cart_status'));
        add_action('wp_ajax_convert_cart_subscription', array($this, 'ajax_convert_cart_subscription'));
        add_action('wp_ajax_nopriv_convert_cart_subscription', array($this, 'ajax_convert_cart_subscription'));
        add_action('wp_ajax_switch_cart_subscription', array($this, 'handle_cart_subscription_popup_switch'));
        add_action('wp_ajax_nopriv_switch_cart_subscription', array($this, 'handle_cart_subscription_popup_switch'));
        add_action('wp_ajax_cancel_oppio_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_nopriv_cancel_oppio_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_renew_oppio_subscription', array($this, 'ajax_renew_subscription'));
        add_action('wp_ajax_nopriv_renew_oppio_subscription', array($this, 'ajax_renew_subscription'));
        
        // Pridaj subscription stĺpec do orders admin tabuľky
        add_action('init', array($this, 'add_subscription_column_to_orders'));
        
        // Hook pre zákaznícky účet
        add_action('init', array($this, 'add_subscription_account_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_subscription_menu_item'));
        add_action('woocommerce_account_moje-predplatne_endpoint', array($this, 'subscription_account_content'));
        // add_action('woocommerce_account_moje-predplatne_endpoint', function () {
        //     echo '<div class="woocommerce-MyAccount-content"><h2>TEST: Moje predplatné funguje</h2></div>';
        // });
        
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_retry_assets']);

        add_action('wp_ajax_oppio_retry_subscription',        [$this, 'ajax_retry_subscription']);
        add_action('wp_ajax_nopriv_oppio_retry_subscription', [$this, 'ajax_retry_subscription']);

        add_action('wp_ajax_oppio_pay_invoice_after_si',        [$this, 'ajax_pay_invoice_after_si']);
        add_action('wp_ajax_nopriv_oppio_pay_invoice_after_si', [$this, 'ajax_pay_invoice_after_si']);

        add_action('wp_ajax_oppio_after_pi_confirm', [$this, 'ajax_after_pi_confirm']);
        // END Dokončiť platbu

        // Dokoncti objednavku - prebrat link z FIALED subscribe po TRIAL statuse
        add_action('wp_ajax_oppio_get_sca_url',        [$this, 'ajax_get_sca_url']);
        add_action('wp_ajax_nopriv_oppio_get_sca_url', [$this, 'ajax_get_sca_url']);

        // Oprava order ak nemam ID
        add_action('wp_ajax_oppio_fix_order',        [$this, 'ajax_oppio_fix_order']);
        add_action('wp_ajax_nopriv_oppio_fix_order', [$this, 'ajax_oppio_fix_order']);

        // vrat dokoncenie 3ds
        add_action('wp_ajax_oppio_confirm_pi',        [$this, 'ajax_oppio_confirm_pi']);
        add_action('wp_ajax_nopriv_oppio_confirm_pi', [$this, 'ajax_oppio_confirm_pi']);

        // overenie karty pri prvom zalozeni subscribe
            // v konštruktore:
add_action('woocommerce_thankyou', [$this,'render_thankyou_card_setup'], 20, 1);
add_action('wp', [$this,'thankyou_fallback_hook']); // fallback

        add_action('wp_ajax_oppio_init_si_onboarding',       [$this,'ajax_init_si_onboarding']);
        add_action('wp_ajax_nopriv_oppio_init_si_onboarding',[$this,'ajax_init_si_onboarding']);

        add_action('wp_ajax_oppio_attach_pm_subscription',       [$this,'ajax_attach_pm_subscription']);
        add_action('wp_ajax_nopriv_oppio_attach_pm_subscription',[$this,'ajax_attach_pm_subscription']);

    }



public function thankyou_fallback_hook(){
    if ( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received') ) {
        $this->oppio_renew_log('thankyou_fallback: endpoint matched');
        add_action('wp_footer', [$this,'thankyou_fallback_render']);
    }
}

public function thankyou_fallback_render(){
    // získať order_id aj keď builder mení šablónu
    $order_id = absint( get_query_var('order-received') );
    if ( ! $order_id && isset($_GET['key']) ) {
        $key = wc_clean( wp_unslash($_GET['key']) );
        $order_id = wc_get_order_id_by_order_key($key);
    }
    $this->oppio_renew_log('thankyou_fallback_render: order_id='.$order_id);
    if ($order_id) {
        $this->render_thankyou_card_setup($order_id); // tvoja existujúca funkcia
    }
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
        add_action('scheduled_subscription_payment_stripe', array($this, 'process_scheduled_payment'), 10, 2);

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

    /*
    public function modify_custom_add_to_cart() {
        if (isset($_POST['subscription_type'])) {
            $subscription_type = sanitize_text_field($_POST['subscription_type']);
            
            // ✅ OPRAVENÉ - ulož do session iba ak NIE JE 'none'
            if ($subscription_type !== 'none') {
                WC()->session->set('pending_subscription_type', $subscription_type);
            } else {
                WC()->session->set('pending_subscription_type', null);
            }
            
            // Debug log
            $this->oppio_log('OPPIO AJAX: Received subscription_type: ' . $subscription_type);
            $this->oppio_log('OPPIO AJAX: Pending subscription set to: ' . WC()->session->get('pending_subscription_type'));
        }
    }
        */
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
        if (!$cart || $cart->is_empty() || is_admin()) return;

        $subscription_type = $this->get_existing_subscription_type_from_cart();
        if (!$subscription_type) return;

        // ODSTRÁNENÉ: static $discount_applied - aby sa mohla aplikovať opakovane
        
        $discount_percent = 0;
        $discount_label = '';

        switch ($subscription_type) {
            case 'monthly':
                $discount_percent = 15;
                $discount_label = 'Zľava mesačné predplatné';
                break;
            case 'biweekly':
                $discount_percent = 20;
                $discount_label = 'Zľava 14-dňové predplatné';
                break;
            case 'daily':
                $discount_percent = 10;
                $discount_label = 'Zľava denné predplatné';
                break;
        }

        if ($discount_percent > 0) {
            // KONTROLA: Skontroluj či už nie je subscription fee aplikovaná
            $existing_fees = $cart->get_fees();
            $subscription_fee_exists = false;
            
            foreach ($existing_fees as $fee) {
                if (strpos($fee->get_name(), 'predplatné') !== false || 
                    strpos($fee->get_name(), $discount_label) !== false) {
                    $subscription_fee_exists = true;
                    break;
                }
            }
            
            // Aplikuj iba ak ešte nie je aplikovaná
            if (!$subscription_fee_exists) {
                $subscription_subtotal = 0;

                foreach ($cart->get_cart() as $cart_item) {
                    if (isset($cart_item['subscription_type'])) {
                        $subscription_subtotal += $cart_item['line_subtotal'];
                    }
                }

                if ($subscription_subtotal > 0) {
                    $discount_amount = ($subscription_subtotal * $discount_percent) / 100;
                    $cart->add_fee($discount_label . ' (-' . $discount_percent . '%)', -$discount_amount);
                }
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

        // Remove sleep(2) - to je hack!
        
        // Skontroluj či je to subscription order
        $is_subscription = $order->get_meta('_oppio_is_subscription');
        if ($is_subscription !== 'yes') {
            $this->oppio_log('OPPIO: Order ' . $order_id . ' is not a subscription order');
            return;
        }

        // Skontroluj či už má subscription ID
        $stripe_subscription_id = $order->get_meta('_oppio_stripe_subscription_id');
        if (!empty($stripe_subscription_id)) {
            $this->oppio_log('OPPIO: Order ' . $order_id . ' already has subscription: ' . $stripe_subscription_id);
            return;
        }

        // Získaj Stripe údaje
        $stripe_customer_id = $order->get_meta('_stripe_customer_id');
        $stripe_payment_method = $order->get_meta('_stripe_payment_method_id') ?: $order->get_meta('_stripe_source_id');

        if (empty($stripe_customer_id)) {
            $order->add_order_note('❌ Chýba Stripe Customer ID');
            return;
        }

        // ✅ NOVÉ: VALIDUJ A OPRAV PAYMENT METHOD
        $valid_payment_method = $this->get_valid_payment_method($stripe_customer_id, $stripe_payment_method);
        
        if (!$valid_payment_method) {
            $order->add_order_note('❌ Žiadny platný payment method pre subscription');
            $this->oppio_log('OPPIO: No valid payment method for subscription');
            return;
        }

        // Vytvor subscription s platným payment method
        $subscription_result = $this->create_stripe_subscription_api_working($order, $stripe_customer_id, $valid_payment_method);

        if ($subscription_result && isset($subscription_result['id'])) {
            $order->update_meta_data('_oppio_stripe_subscription_id', $subscription_result['id']);
            $order->save();
            $order->add_order_note('✅ Stripe subscription vytvorené: ' . $subscription_result['id']);
            $this->oppio_log('OPPIO: Stripe subscription created successfully: ' . $subscription_result['id']);
            /*
            // ✅ ISTOTA: odošli e-maily pri prvej (parent) objednávke, ak je reálne zaplatená
            if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
                $mailer = WC()->mailer();
                $mailer->emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id() );
                $mailer->emails['WC_Email_New_Order']->trigger( $order->get_id() );
                $this->oppio_log('OPPIO: Transactional emails triggered for parent order ' . $order->get_id());
            }
                */
        } else {
            $order->add_order_note('❌ Nepodarilo sa vytvoriť Stripe subscription');
            $this->oppio_log('OPPIO: Failed to create Stripe subscription for order: ' . $order_id);
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
    /*
    private function calculate_subscription_price_without_coupon($order, $subscription_type) {
        $items = $order->get_items();
        $subscription_subtotal = 0.0;

        // Použi sumy z objednávky (už sú v mene objednávky – CZK alebo EUR)
        foreach ($items as $item) {
            // Subtotal bez kupónov a bez DPH
            $line_subtotal = (float) $item->get_subtotal();
            $subscription_subtotal += $line_subtotal;
        }

        // Aplikuj iba zľavu za typ predplatného
        $discount_percent = 0;
        switch ($subscription_type) {
            case 'monthly':
                $discount_percent = 15;
                break;
            case 'biweekly':
                $discount_percent = 20;
                break;
            case 'daily':
                $discount_percent = 10;
                break;
        }

        if ($discount_percent > 0) {
            $subscription_subtotal *= (1 - $discount_percent / 100);
        }

        return $subscription_subtotal;
    }
        */
    // Presne zhodná suma so sumou rodičovskej objednávky
    private function calculate_subscription_price_without_coupon($order, $subscription_type) {
        return (float) $order->get_total();
    }

    /**
     * ✅ UNIVERZÁLNE RIEŠENIE PRE VŠETKY TYPY KARIET A SCA
     * Vytvorí Stripe subscription s automatickým SCA handling
     */
    public function create_stripe_subscription_api_working($order, $customer_id, $payment_method_id) {
        // Získaj Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        
        if ($test_mode) {
            $api_key = isset($stripe_settings['test_secret_key']) ? $stripe_settings['test_secret_key'] : '';
        } else {
            $api_key = isset($stripe_settings['secret_key']) ? $stripe_settings['secret_key'] : '';
        }

        if (empty($api_key)) {
            $this->oppio_log('OPPIO: Missing Stripe API key');
            return false;
        }

        // ✅ HELPER FUNKCIA PRE STABILNÉ IDEMPOTENCY KEYS
        $oppio_idem = function($order_id, $step) {
            return 'oppio-' . $order_id . '-' . $step;
        };

        $headers = array(
            'Authorization'     => 'Bearer ' . $api_key,
            'Content-Type'      => 'application/x-www-form-urlencoded',
            'Stripe-Version'    => '2024-06-20',
            'Idempotency-Key'   => $oppio_idem($order->get_id(), 'product')
        );

        // Získaj subscription details
        $subscription_type = $order->get_meta('_oppio_subscription_type');
        $subscription_frequency = $order->get_meta('_oppio_subscription_frequency');
        
        $mode_label = $test_mode ? 'test' : 'live';
        
        // Výpočet intervalu
        if ($subscription_type === 'biweekly') {
            $interval = 'week';
            $interval_count = 2; // každé 2 týždne = 14 dní
        } 
        elseif ($subscription_type === 'monthly') {
            $interval = 'month';
            $interval_count = 1;
        } 
        else {
            $interval = 'day';
            $interval_count = 1;
        }

        // Získaj prvý produkt z objednávky
        $items = $order->get_items();
        $first_item = reset($items);
        $product = $first_item->get_product();
        $quantity = $first_item->get_quantity();

        // KROK 1: Vytvor Stripe Product
        $product_data = array(
            'name' => $product->get_name() . ' - ' . $subscription_type . ' predplatné',
            'metadata' => array(
                'woocommerce_product_id' => $product->get_id(),
                'oppio_subscription_type' => $subscription_type,
                'oppio_mode' => $mode_label
            )
        );

        $product_response = wp_remote_post('https://api.stripe.com/v1/products', array(
            'headers'   => $headers,
            'body'      => http_build_query($product_data),
            'timeout'   => 30
        ));

        if (is_wp_error($product_response)) {
            $this->oppio_log('OPPIO: Product creation failed: ' . $product_response->get_error_message());
            return false;
        }

        $product_body = wp_remote_retrieve_body($product_response);
        $product_result = json_decode($product_body, true);

        if (wp_remote_retrieve_response_code($product_response) !== 200 || isset($product_result['error'])) {
            $this->oppio_log('OPPIO: Product creation error: ' . $product_body);
            return false;
        }

        $stripe_product_id = $product_result['id'];
        $this->oppio_log('OPPIO: Stripe Product created: ' . $stripe_product_id);

        // KROK 2: Vytvor Stripe Price
        $subscription_price_per_unit = $this->calculate_subscription_price_without_coupon($order, $subscription_type) / $quantity;
        $price_data = array(
            'product' => $stripe_product_id,
            'unit_amount'   => (int) round($subscription_price_per_unit * 100), // ✅ Správne zaokrúhlenie
            'currency' => strtolower($order->get_currency()),
            'recurring' => array(
                'interval' => $interval,
                'interval_count' => $interval_count
            ),
            'metadata' => array(
                'woocommerce_order_id'       => $order->get_id(),
                'oppio_subscription_type'    => $subscription_type,
                'subscription_type'          => $subscription_type,
                'oppio_mode'                 => $mode_label
            )
        );

        $price_headers = $headers;
        $price_headers['Idempotency-Key'] = $oppio_idem($order->get_id(), 'price');

        $price_response = wp_remote_post('https://api.stripe.com/v1/prices', array(
            'headers'   => $price_headers,
            'body'      => http_build_query($price_data),
            'timeout'   => 30
        ));

        if (is_wp_error($price_response)) {
            $this->oppio_log('OPPIO: Price creation failed: ' . $price_response->get_error_message());
            return false;
        }

        $price_body = wp_remote_retrieve_body($price_response);
        $price_result = json_decode($price_body, true);

        if (wp_remote_retrieve_response_code($price_response) !== 200 || isset($price_result['error'])) {
            $this->oppio_log('OPPIO: Price creation error: ' . $price_body);
            return false;
        }

        $price_id = $price_result['id'];
        $this->oppio_log('OPPIO: Stripe Price created: ' . $price_id);

        // ✅ KROK 3: PRIPOJ PAYMENT METHOD K CUSTOMER
        $this->oppio_log('OPPIO: Attaching payment method ' . $payment_method_id . ' to customer ' . $customer_id);

        $attach_data = array(
            'customer' => $customer_id
        );

        $attach_headers = $headers;
        $attach_headers['Idempotency-Key'] = $oppio_idem($order->get_id(), 'attach');

        $attach_response = wp_remote_post('https://api.stripe.com/v1/payment_methods/' . $payment_method_id . '/attach', array(
            'headers'   => $attach_headers,
            'body'      => http_build_query($attach_data),
            'timeout'   => 30
        ));

        if (is_wp_error($attach_response)) {
            $this->oppio_log('OPPIO: Payment method attach failed: ' . $attach_response->get_error_message());
            return false;
        }

        $attach_code = wp_remote_retrieve_response_code($attach_response);
        $attach_body = wp_remote_retrieve_body($attach_response);
        $attach_result = json_decode($attach_body, true);

        $this->oppio_log('OPPIO: Payment method attach response code: ' . $attach_code);
        $this->oppio_log('OPPIO: Payment method attach response: ' . $attach_body);

        // Skontroluj či attach prebehlo úspešne
        if ($attach_code !== 200) {
            if ($attach_code === 400 && isset($attach_result['error']['code'])) {
                $err = $attach_result['error']['code'];
                if ($err === 'resource_already_exists') {
                    $this->oppio_log('OPPIO: PM already attached to customer - set as default and continue');

                    // ✅ Nastav default aj v tomto prípade
                    $customer_update_headers = $headers;
                    $customer_update_headers['Idempotency-Key'] = $oppio_idem($order->get_id(), 'customer-default');

                    $resp = wp_remote_post('https://api.stripe.com/v1/customers/' . $customer_id, [
                        'headers' => $customer_update_headers,
                        'body'    => http_build_query([
                            'invoice_settings[default_payment_method]' => $payment_method_id,
                        ]),
                        'timeout' => 30,
                    ]);
                    // (voliteľné) zaloguj výsledok:
                    $this->oppio_log('OPPIO: Set default PM on customer (already attached) - code: ' .
                        (int) wp_remote_retrieve_response_code($resp));

                    // pokračuj ďalej
                } elseif ($err === 'payment_method_unexpected_state') {
                    $this->oppio_log('OPPIO: Payment method attached to different customer - require new card');
                    return ['error' => 'payment_method_belongs_to_other_customer'];
                } else {
                    $this->oppio_log('OPPIO: Payment method attach failed: ' . $attach_body);
                    return false;
                }
            } else {
                $this->oppio_log('OPPIO: Payment method attach failed with code: ' . $attach_code . ' body: ' . $attach_body);
                return false;
            }
        } 
        else {
            $this->oppio_log('OPPIO: Payment method successfully attached to customer');

            // ✅ Nastav default PM (štandardný prípad po úspešnom attach)
            $customer_update_headers = $headers;
            $customer_update_headers['Idempotency-Key'] = $oppio_idem($order->get_id(), 'customer-default');

            $resp = wp_remote_post('https://api.stripe.com/v1/customers/' . $customer_id, [
                'headers' => $customer_update_headers,
                'body'    => http_build_query([
                    'invoice_settings[default_payment_method]' => $payment_method_id,
                ]),
                'timeout' => 30,
            ]);
            // (voliteľné) zaloguj výsledok:
            $this->oppio_log('OPPIO: Set default PM on customer - code: ' .
                (int) wp_remote_retrieve_response_code($resp));
        }


        // ✅ 100% zľava iba na PRVÝ invoice (bez trialu)
        $first_order_coupon_id = 'first_order_free'; // <- TU daj svoje skutočné ID kupónu zo Stripe
        $this->oppio_log('OPPIO: Applying one-time 100% coupon on first invoice: ' . $first_order_coupon_id);

        // ✅ KROK 4: VYTVOR SUBSCRIPTION S SCA PODPOROU
        // ✅ KROK 4: VYTVOR SUBSCRIPTION S IHNED SUBSCRIBE
        $subscription_data = array(
            'customer' => $customer_id,
            'default_payment_method' => $payment_method_id,
            
            // ✅ IHNED SUBSCRIBE - OKAMŽITÉ STIAHNUTIE PRVEJ PLATBY
            'payment_behavior' => 'default_incomplete',
            'collection_method' => 'charge_automatically',
            
            // ✅ NOVÉ: 100% ZĽAVA IBA NA PRVÝ INVOICE (žiadny trial)
            'discounts' => [
                [
                    'coupon' => $first_order_coupon_id, // napr. 'first_order_free'
                ],
            ],

            // ⬇️ NOVÉ – nech Stripe uloží kartu pre obnovy
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],

            // ✅ ROZŠÍR RESPONSE aby sme videli PaymentIntent
            'expand' => [
                'latest_invoice.payment_intent',
                'latest_invoice.subscription'
            ],
            
            'items' => array(
                array(
                    'price'     => $price_id,
                    'quantity'  => $quantity
                )
            ),
            'metadata' => array(
                'woocommerce_order_id'          => $order->get_id(),
                'oppio_subscription_type'       => $subscription_type,
                'oppio_subscription_frequency'  => $subscription_frequency,
                'oppio_customer_email'          => $order->get_billing_email(),
                'oppio_mode'                    => $mode_label,
                'oppio_site_url'                => get_site_url(),
                'oppio_site_locale'             => get_locale(),
                'oppio_currency'                => $order->get_currency(),
                'oppio_country'                 => $order->get_billing_country(),
                'oppio_product_id'              => $product->get_id(),
                'oppio_price_id'                => $price_id
            )
        );

        $this->oppio_log('OPPIO: Creating Stripe Subscription with SCA support: ' . print_r($subscription_data, true));

        $subscription_headers = $headers;
        $subscription_headers['Idempotency-Key'] = $oppio_idem($order->get_id(), 'subscription');

        $subscription_response = wp_remote_post('https://api.stripe.com/v1/subscriptions', array(
            'headers'   => $subscription_headers,
            'body'      => http_build_query($subscription_data),
            'timeout'   => 30
        ));

        if (is_wp_error($subscription_response)) {
            $this->oppio_log('OPPIO: Subscription creation failed: ' . $subscription_response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($subscription_response);
        $body = wp_remote_retrieve_body($subscription_response);
        $result = json_decode($body, true);

        // Po úspešnom vytvorení subscribe ulož ID-čka
        // === ULOŽ DÔLEŽITÉ META, ABY SA DALA PLATBA DOKONČIŤ ===
        $sub_id      = $result['id'] ?? '';
        $sub_status  = isset($result['status']) ? strtolower(trim((string)$result['status'])) : '';
        $invoice_id  = $result['latest_invoice']['id'] ?? '';
        $pi          = $result['latest_invoice']['payment_intent'] ?? [];
        $pi_id       = $pi['id'] ?? '';
        $pi_secret   = $pi['client_secret'] ?? '';
        $sca_url     = $pi['next_action']['redirect_to_url']['url'] ?? '';

        if ($sub_id !== '')      { $order->update_meta_data('_oppio_stripe_subscription_id', $sub_id); }
        if ($sub_status !== '')  { $order->update_meta_data('_oppio_subscription_status', $sub_status); }
        if ($invoice_id !== '')  { $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id); }
        if ($pi_id !== '')       { $order->update_meta_data('_oppio_stripe_pi_id', $pi_id); }
        if ($pi_secret !== '')   { $order->update_meta_data('_oppio_stripe_payment_intent_secret', $pi_secret); }
        if ($sca_url !== '')     { $order->update_meta_data('_oppio_sca_redirect_url', $sca_url); }

        $order->save();


        $this->oppio_log('OPPIO: Stripe Subscription API response code: ' . $response_code);
        $this->oppio_log('OPPIO: Stripe Subscription API response body: ' . $body);

        if ($response_code !== 200) {
            $this->oppio_log('OPPIO: Subscription API HTTP error code: ' . $response_code);
            return false;
        }

        if (isset($result['error'])) {
            $this->oppio_log('OPPIO: Stripe subscription error: ' . $result['error']['message']);
            if (isset($result['error']['code'])) {
                $this->oppio_log('OPPIO: Stripe error code: ' . $result['error']['code']);
            }
            return false;
        }

        // ✅ KROK 5: SKONTROLUJ SCA STAV A SPRACUJ
        $subscription_status = $result['status'];
        $this->oppio_log('OPPIO: Subscription created with status: ' . $subscription_status);

        if (empty($result['latest_invoice']['payment_intent']['client_secret'])) {
            $this->oppio_log('OPPIO: MISSING payment_intent.client_secret in subscription creation');
            return [
                'status' => 'error',
                'message' => 'Platobný proces sa nepodarilo inicializovať. Skúste znova.',
            ];
        }

        // Skontroluj či potrebuje ďalšiu autentifikáciu
        if ($subscription_status === 'incomplete') {
            $this->oppio_log('OPPIO: Subscription is incomplete - checking payment intent');
            
            // Skontroluj PaymentIntent stav
            if (isset($result['latest_invoice']['payment_intent'])) {
                $payment_intent = $result['latest_invoice']['payment_intent'];
                $pi_status = $payment_intent['status'];
                
                $this->oppio_log('OPPIO: PaymentIntent status: ' . $pi_status);
                
                if ($pi_status === 'requires_action') {
                    $this->oppio_log('OPPIO: PaymentIntent requires action (3D Secure)');
                    
                    // Uložme client_secret pre frontend handling
                    $client_secret = $payment_intent['client_secret'];
                    $order->update_meta_data('_oppio_stripe_payment_intent_secret', $client_secret);
                    $order->update_meta_data('_oppio_stripe_subscription_incomplete', 'yes');
                    $order->update_meta_data('_oppio_stripe_subscription_id', $result['id']); // ✅ Ulož subscription ID
                    $order->update_meta_data('_oppio_stripe_payment_intent_id', $payment_intent['id']); // ✅ Ulož PI ID
                    $order->add_order_note('⚠️ Subscription vyžaduje dodatočnú autentifikáciu (3D Secure)');
                    $order->save();

                    // ⬇️ NOVÉ – ulož aj priamy 3DS link, nech vieš zobraziť "Dokončiť overenie"
                    $sca_url = $payment_intent['next_action']['redirect_to_url']['url'] ?? '';
                    if ($sca_url !== '') { $order->update_meta_data('_oppio_sca_redirect_url', $sca_url); }
                    $order->update_meta_data('_oppio_subscription_status', 'incomplete');
                    $order->save();

                    // Vratíme partial success s dodatočnými info
                    return array(
                        'id'               => $result['id'],
                        'status'           => 'incomplete',
                        'requires_action'  => true,
                        'client_secret'    => $client_secret,
                        'payment_intent_id'=> $payment_intent['id'],
                        // kam presmerovať po úspešnom 3DS
                        'success_url'      => $order->get_checkout_order_received_url(),
                    );

                } 
                elseif ($pi_status === 'requires_confirmation') {
                    $client_secret = $payment_intent['client_secret'] ?? null;
                    return [
                        'id'               => $result['id'],
                        'status'           => 'incomplete',
                        'requires_action'  => true,
                        'client_secret'    => $client_secret,
                        'payment_intent_id'=> $payment_intent['id'],
                        'success_url'      => $order->get_checkout_order_received_url(),
                    ];

                } elseif ($pi_status === 'requires_payment_method') {
                    // (3DS zlyhalo)
                    $this->oppio_log('OPPIO: PaymentIntent requires new payment method - SCA failed');
                    $retry_url = $this->oppio_handle_requires_payment_method($order, $result['latest_invoice']['payment_intent'] ?? array());

                    // vyčisti starý client_secret, aby sa nerecykloval ten istý PI
                    $order->delete_meta_data('_oppio_stripe_payment_intent_secret');
                    $order->save();

                    return array(
                        'status'     => 'requires_payment_method',
                        // Woo „order-pay“ URL – nový pokus vytvorí nový PaymentIntent
                        'retry_url'  => $order->get_checkout_payment_url(true),
                        'message'    => '3D Secure overenie zlyhalo, skúste znova s novou kartou.',
                    );
                    
                } elseif ($pi_status === 'succeeded' || $pi_status === 'processing') {
                    $this->oppio_log('OPPIO: PaymentIntent succeeded - subscription should be active soon');
                    $order->add_order_note('✅ Stripe subscription vytvorené (čaká na aktiváciu): ' . $result['id']);
                    
                    $this->oppio_sync_latest_pm($customer_id, $payment_method_id, $result['id'] ?? null);
                
                    // Poisti sa, že máme aktuálny stav objednávky
                    $order = wc_get_order( $order->get_id() );

                    /*
                    // Pošli notifikácie len raz
                    if ( $order && $order->has_status( array( 'processing', 'completed' ) ) && 'yes' !== $order->get_meta('_oppio_processing_mail_sent') ) {
                        try {
                            $mailer = WC()->mailer();
                            $emails = is_object( $mailer ) ? $mailer->get_emails() : array();

                            // Zákazník: "Objednávka spracovávaná"
                            if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
                                $emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id() );
                            }

                            // Admin: "Nová objednávka"
                            if ( isset( $emails['WC_Email_New_Order'] ) ) {
                                $emails['WC_Email_New_Order']->trigger( $order->get_id() );
                            }

                            // Zamedz duplicitám
                            $order->update_meta_data( '_oppio_processing_mail_sent', 'yes' );
                            $order->save();

                            $this->oppio_log( 'OPPIO: Transactional emails triggered (processing/completed) for order ' . $order->get_id() );
                        } catch ( \Throwable $e ) {
                            $this->oppio_log( 'OPPIO: Email trigger error: ' . $e->getMessage() );
                        }
                    }
                        */

                    return array(
                        'success'     => true,
                        'redirect'    => $order->get_checkout_order_received_url(),
                        'subscription_id' => $result['id'] ?? null,
                    );

                } else {
                    $this->oppio_log('OPPIO: PaymentIntent failed with status: ' . $pi_status);
                    $order->add_order_note('❌ Subscription payment failed: ' . $pi_status);
                    return false;
                }
            }
            
        } 
        elseif ($subscription_status === 'active') {
            $this->oppio_log('OPPIO: Subscription is immediately active');
            $order->add_order_note('✅ Stripe subscription úspešne vytvorené a aktivované: ' . $result['id']);
            
            $pi = $result['latest_invoice']['payment_intent'];

            $this->oppio_sync_latest_pm($customer_id, $payment_method_id, $result['id'] ?? null);

            return [
                'success'         => true,
                'subscription_id' => $result['id'],
                'invoice_id'      => $result['latest_invoice']['id'],
                'client_secret'   => $pi['client_secret'],
                'redirect'        => $order->get_checkout_order_received_url()
            ];

        } 
        elseif ($subscription_status === 'trialing') {
            $this->oppio_log('OPPIO: Subscription is in trial period');
            $order->add_order_note('✅ Stripe subscription v skúšobnom období: ' . $result['id']);
            
            $pi = $result['latest_invoice']['payment_intent'];

            $this->oppio_sync_latest_pm($customer_id, $payment_method_id, $result['id'] ?? null);

            return [
                'success'         => true,
                'subscription_id' => $result['id'],
                'invoice_id'      => $result['latest_invoice']['id'],
                'client_secret'   => $pi['client_secret'],
                'redirect'        => $order->get_checkout_order_received_url()
            ];

            
        } 
        else {
            $this->oppio_log('OPPIO: Unexpected subscription status: ' . $subscription_status);
            $order->add_order_note('⚠️ Subscription vytvorené s neočakávaným stavom: ' . $subscription_status);
        }

        $this->oppio_log('OPPIO: Stripe subscription created successfully: ' . $result['id']);
        return $result;
    }

    /**
     * Pokúsi sa nájsť Stripe subscription pre danú objednávku a uložiť ho do meta.
     * Priorita:
     * 1) search /v1/subscriptions/search podľa metadata["woocommerce_order_id"]
     * 2) ak zlyhá, prejde invoices zákazníka a vezme subscription z faktúr
     * Vráti pole s nájdeným subscription (id, status) alebo prázdne pole.
     */
    private function repair_and_attach_subscription_id( WC_Order $order ) : array {
        $out = [];

        // API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if ($api_key === '') return $out;

        $order_id = (string) $order->get_id();

        // 1) Skús Search API na subscriptions podľa metadata woocommerce_order_id
        $query = 'metadata["woocommerce_order_id"]:"' . $order_id . '"';
        $url = 'https://api.stripe.com/v1/subscriptions/search?query=' . rawurlencode($query) . '&limit=1';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp && $code === 200) {
            $data = json_decode($resp, true);
            $sub  = $data['data'][0] ?? [];
            if (!empty($sub['id'])) {
                $order->update_meta_data('_oppio_stripe_subscription_id', $sub['id']);
                if (!empty($sub['status'])) {
                    $order->update_meta_data('_oppio_subscription_status', strtolower(trim((string)$sub['status'])));
                }
                $order->save();
                return ['id' => $sub['id'], 'status' => strtolower(trim((string)($sub['status'] ?? '')))];
            }
        }

        // 2) Fallback – cez posledné faktúry zákazníka (podľa emailu)
        $billing_email = $order->get_billing_email();
        if ($billing_email === '') return $out;

        // nájdi zákazníka podľa emailu
        $cust_url = 'https://api.stripe.com/v1/customers/search?query=' . rawurlencode('email:"' . $billing_email . '"') . '&limit=1';
        $ch = curl_init($cust_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $cresp = curl_exec($ch);
        $ccode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $cus_id = '';
        if ($cresp && $ccode === 200) {
            $cdata = json_decode($cresp, true);
            $cus_id = (string)($cdata['data'][0]['id'] ?? '');
        }
        if ($cus_id === '') return $out;

        // zober posledných pár faktúr a vezmi subscription z niektorej
        $inv_url = 'https://api.stripe.com/v1/invoices?customer=' . rawurlencode($cus_id) . '&limit=10';
        $ch = curl_init($inv_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $iresp = curl_exec($ch);
        $icode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($iresp && $icode === 200) {
            $idata = json_decode($iresp, true);
            $invoices = $idata['data'] ?? [];
            foreach ($invoices as $inv) {
                if (!empty($inv['subscription'])) {
                    $sub_id = (string)$inv['subscription'];
                    // ešte si stiahni status subscription
                    $surl = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($sub_id);
                    $ch = curl_init($surl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
                    $sresp = curl_exec($ch);
                    $scode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $sub_status = '';
                    if ($sresp && $scode === 200) {
                        $sdata = json_decode($sresp, true);
                        $sub_status = strtolower(trim((string)($sdata['status'] ?? '')));
                    }

                    $order->update_meta_data('_oppio_stripe_subscription_id', $sub_id);
                    if ($sub_status !== '') {
                        $order->update_meta_data('_oppio_subscription_status', $sub_status);
                    }
                    $order->save();

                    return ['id' => $sub_id, 'status' => $sub_status];
                }
            }
        }

        return $out;
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
     * Keď 3DS/authentifikácia zlyhá a PI skončí na requires_payment_method,
     * uprac poriadne order + meta a priprav "Zaplatiť znova".
     */
    private function oppio_handle_requires_payment_method($order, $pi_array = array()) {
        // 1) Log & order note s užitočnými kódmi
        $code      = $pi_array['last_payment_error']['code']         ?? '';
        $msg_code  = $pi_array['last_payment_error']['message_code']  ?? '';
        $pm_last4  = $pi_array['last_payment_error']['payment_method']['card']['last4'] ?? '';
        $note = sprintf('❌ 3DS/SCA authentication failed. code=%s, msg_code=%s, card=%s',
                        $code ?: '-', $msg_code ?: '-', $pm_last4 ?: '-');
        $order->add_order_note($note);

        // 2) Vyčisti všetky uložené PI/3DS meta, aby sa to NESPÚŠŤALO znova po reloade
        $order->delete_meta_data('_oppio_stripe_payment_intent_secret');
        $order->delete_meta_data('_oppio_stripe_payment_intent_id');
        $order->delete_meta_data('_oppio_stripe_subscription_incomplete');
        $order->delete_meta_data('_oppio_stripe_pm_invalid');
        $order->save();

        // 3) Nastav status tak, aby Woo dovolil "Zaplatiť znova" (pending/failed sú OK)
        if (!in_array($order->get_status(), array('processing','completed','on-hold'))) {
            $order->update_status('failed', 'Platba zlyhala na 3DS – zákazník musí zadať novú kartu.');
        }

        // 4) Vráť "pay" URL pre opakovaný pokus (Woo si na tejto stránke vytvorí nový PI)
        return $order->get_checkout_payment_url(true);
    }

    /*
     * ✅ NOVÁ FUNKCIA: Synchronizuje default PM na customerovi (pre budúce faktúry)
     */
    private function oppio_sync_latest_pm($customer_id, $pm_id, $maybe_subscription_id = null) {
        if (!$customer_id || !$pm_id) return;

        $stripe_settings = get_option('woocommerce_stripe_settings', []);
        $test_mode = !empty($stripe_settings['testmode']) && $stripe_settings['testmode']==='yes';
        $api_key = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if (!$api_key) return;

        $headers = [
            'Authorization'  => 'Bearer '.$api_key,
            'Stripe-Version' => '2024-06-20',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        // 1) nastav default PM na customerovi (pre budúce faktúry)
        wp_remote_post("https://api.stripe.com/v1/customers/{$customer_id}", [
            'headers' => $headers,
            'body'    => http_build_query([
                'invoice_settings[default_payment_method]' => $pm_id,
            ]),
            'timeout' => 30,
        ]);

        // 2) ak je známe subscription, nastav rovnaký PM aj tam
        if ($maybe_subscription_id) {
            wp_remote_post("https://api.stripe.com/v1/subscriptions/{$maybe_subscription_id}", [
                'headers' => $headers,
                'body'    => http_build_query([
                    'default_payment_method' => $pm_id,
                ]),
                'timeout' => 30,
            ]);
        }
    }

    /**
     * AJAX endpoint pre vytvorenie Stripe Subscription
     */
    public function ajax_create_subscription() {
        // Bez nonce a bez prísnych kontrol – pridaj si podľa potreby
        $order_id         = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $customer_id      = sanitize_text_field($_POST['customer_id'] ?? '');
        $payment_method_id= sanitize_text_field($_POST['payment_method_id'] ?? '');

        if (!$order_id || !$payment_method_id || !$customer_id) {
            wp_send_json_error(array('message' => 'Missing input'), 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'), 404);
        }

        $res = $this->create_stripe_subscription_api_working($order, $customer_id, $payment_method_id);

        if (is_array($res)) {
            // POZOR: vraciame priamo to, čo JS patch očakáva
            wp_send_json($res);
        }

        // fallback
        wp_send_json_error(array('message' => 'Subscription creation failed'), 500);
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

    /*
     * OVERENIE KARTY PRE ACTIVOVANIE SUBSCRIBE
     */
    public function render_thankyou_card_setup($order_id){
        $this->oppio_renew_log('thankyou:start order_id='.$order_id);

        if (!$order_id){ $this->oppio_renew_log('thankyou: no order_id'); return; }
        $order = wc_get_order($order_id);
        if (!$order){ $this->oppio_renew_log('thankyou: order not found'); return; }

        // povolíme buď prihláseného vlastníka, alebo guest s platným order key z URL
        $user_ok = is_user_logged_in() && (int)$order->get_user_id() === get_current_user_id();
        $key_ok  = isset($_GET['key']) && hash_equals($order->get_order_key(), wc_clean(wp_unslash($_GET['key'])));
        if (!$user_ok && !$key_ok){ $this->oppio_renew_log('thankyou: user/key mismatch'); return; }

        $subId = $order->get_meta('_oppio_stripe_subscription_id');
        if (!$subId){ $this->oppio_renew_log('thankyou: no _oppio_stripe_subscription_id'); return; }

        if ($order->get_meta('_oppio_onboarded_pm')){
            $this->oppio_renew_log('thankyou: already onboarded');
            return;
        }

        // skripty
        wp_enqueue_script('jquery');
        wp_enqueue_script('oppio-stripe-js', 'https://js.stripe.com/v3/', [], null, true);

        // UI blok
        $nonce = wp_create_nonce('oppio_onboard_'.$order_id);
        echo '<div id="oppio-onboard-box" style="margin:18px 0;padding:16px;border:1px solid #ddd;background:#fff;max-width:460px">
                <h3 style="margin-top:0">Dokončiť uloženie karty pre obnovy</h3>
                <p>Over prosím kartu (jednorazovo). Budúce platby potom prebehnú automaticky.</p>
                <div id="oppio-card-element" style="margin:10px 0"></div>
                <button id="oppio-onboard-btn" class="button" 
                        data-order="'.$order_id.'" 
                        data-nonce="'.$nonce.'">Overiť a uložiť kartu</button>
                <div id="oppio-onboard-msg" style="margin-top:10px;font-size:13px;color:#444"></div>
            </div>';

        // JS (naviažeme na stripe handle – jQuery je už enqueued)
        $ajax = admin_url('admin-ajax.php');
        $js = <<<JS
        (function($){
        async function startOnboarding(){
            var btn   = $('#oppio-onboard-btn');
            var msg   = $('#oppio-onboard-msg');
            var wrap  = $('#oppio-card-element');
            var oid   = btn.data('order');
            var nonce = btn.data('nonce');

            btn.prop('disabled', true).text('Pripravujem…');
            msg.text('');

            try{
            const init = await $.post('{$ajax}', { action:'oppio_init_si_onboarding', order_id: oid, _nonce: nonce });
            if(!init || init.success !== true){
                msg.text('Inicializácia zlyhala: ' + (init?.data?.msg || 'neznáma chyba'));
                btn.prop('disabled', false).text('Overiť a uložiť kartu');
                return;
            }
            if(init.data.mode === 'already_set'){
                msg.text('Karta je už nastavená.');
                location.reload();
                return;
            }

            var stripe = window.Stripe(init.data.publishable_key);
            var elements = stripe.elements();
            var card = elements.create('card');
            wrap.empty();
            card.mount('#oppio-card-element');

            btn.text('Overujem…');

            const out = await stripe.confirmCardSetup(init.data.setup_client_secret, { payment_method: { card }});
            if(out.error){
                msg.text(out.error.message || 'Overenie zlyhalo.');
                btn.prop('disabled', false).text('Overiť a uložiť kartu');
                return;
            }

            const set = await $.post('{$ajax}', { action:'oppio_attach_pm_subscription', order_id: oid, pm_id: out.setupIntent.payment_method, _nonce: nonce });
            if(!set || set.success !== true){
                msg.text('Nepodarilo sa nastaviť kartu k predplatnému.');
                btn.prop('disabled', false).text('Overiť a uložiť kartu');
                return;
            }

            msg.text('Hotovo. Karta je uložená a nastavená pre obnovy.');
            location.reload();
            }catch(err){
            console && console.error && console.error(err);
            msg.text('Chyba prehliadača/AJAX.');
            btn.prop('disabled', false).text('Overiť a uložiť kartu');
            }
        }
        $(document).on('click', '#oppio-onboard-btn', function(e){ e.preventDefault(); startOnboarding(); });
        })(jQuery);
        JS;
        wp_add_inline_script('oppio-stripe-js', $js);

        $this->oppio_renew_log('thankyou: UI printed');
    }

    public function ajax_init_si_onboarding(){
        if (!is_user_logged_in()) wp_send_json_error(['msg'=>'auth']);

        $order_id = (int)($_POST['order_id'] ?? 0);
        $nonce    = sanitize_text_field($_POST['_nonce'] ?? '');
        if (!$order_id || !wp_verify_nonce($nonce, 'oppio_onboard_'.$order_id)) {
            wp_send_json_error(['msg'=>'bad_nonce']);
        }

        $order = wc_get_order($order_id);
        if (!$order || (int)$order->get_user_id() !== get_current_user_id()) {
            wp_send_json_error(['msg'=>'no_order']);
        }

        $subId = $order->get_meta('_oppio_stripe_subscription_id');
        if (!$subId) wp_send_json_error(['msg'=>'no_sub']);

        $env = $order->get_meta('_oppio_subscription_env') ?: 'live';
        $S   = get_option('woocommerce_stripe_settings', []);
        $sk  = $env==='test' ? ($S['test_secret_key'] ?? '') : ($S['secret_key'] ?? '');
        $pk  = $env==='test' ? ($S['test_publishable_key'] ?? '') : ($S['publishable_key'] ?? '');
        if (!$sk || !$pk) wp_send_json_error(['msg'=>'no_keys']);

        // Ak už subscription má default_payment_method, netreba nič
        $sub = $this->stripe_get("/v1/subscriptions/{$subId}", $sk);
        $defpm = ($sub['http']===200) ? ($sub['json']['default_payment_method'] ?? '') : '';
        if ($defpm) {
            $order->update_meta_data('_oppio_onboarded_pm', $defpm);
            $order->save();
            wp_send_json_success(['mode'=>'already_set']);
        }

        // Potrebujeme Stripe customer
        $cus = $order->get_meta('_stripe_customer_id');
        if (!$cus && $sub['http']===200) $cus = $sub['json']['customer'] ?? '';
        if (!$cus) wp_send_json_error(['msg'=>'no_customer']);

        // SetupIntent na uloženie + 3DS
        $si = $this->stripe_post('/v1/setup_intents', $sk, [
            'customer' => $cus,
            'payment_method_types[]' => 'card',
            'usage' => 'off_session',
        ]);
        if (($si['http'] ?? 0) !== 200) {
            wp_send_json_error(['msg'=>'si_fail']);
        }

        wp_send_json_success([
            'mode' => 'need_setup',
            'publishable_key' => $pk,
            'setup_client_secret' => $si['json']['client_secret'],
            'subscription_id' => $subId,
        ]);
    }

    public function ajax_attach_pm_subscription(){
        if (!is_user_logged_in()) wp_send_json_error();

        $order_id = (int)($_POST['order_id'] ?? 0);
        $pm_id    = sanitize_text_field($_POST['pm_id'] ?? '');
        $nonce    = sanitize_text_field($_POST['_nonce'] ?? '');
        if (!$order_id || !$pm_id || !wp_verify_nonce($nonce, 'oppio_onboard_'.$order_id)) {
            wp_send_json_error();
        }

        $order = wc_get_order($order_id);
        if (!$order || (int)$order->get_user_id() !== get_current_user_id()) wp_send_json_error();

        $subId = $order->get_meta('_oppio_stripe_subscription_id');
        if (!$subId) wp_send_json_error();

        $env = $order->get_meta('_oppio_subscription_env') ?: 'live';
        $S   = get_option('woocommerce_stripe_settings', []);
        $sk  = $env==='test' ? ($S['test_secret_key'] ?? '') : ($S['secret_key'] ?? '');
        if (!$sk) wp_send_json_error();

        // 1) nastav default PM priamo na subscription
        $set = $this->stripe_post("/v1/subscriptions/{$subId}", $sk, [
            'default_payment_method' => $pm_id,
        ]);
        if (($set['http'] ?? 0) !== 200) {
            wp_send_json_error($set);
        }

        // 2) (odporúčané) nastav default PM aj na zákazníka
        //    aby Stripe použil túto kartu aj pri iných PI.
        $sub = $set['json'];
        $cus = $sub['customer'] ?? '';
        if ($cus) {
            $this->stripe_post("/v1/customers/{$cus}", $sk, [
                'invoice_settings[default_payment_method]' => $pm_id,
            ]);
        }

        $order->update_meta_data('_oppio_onboarded_pm', $pm_id);
        $order->add_order_note('✅ Karta overená a nastavená ako predvolená pre predplatné.');
        $order->save();

        wp_send_json_success();
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
            $client_secret = $order->get_meta('_oppio_stripe_payment_intent_secret');
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

        // ✅ ROZŠÍRENÉ premenné vrátane client_secret
        $inline = sprintf(
            'window.OPPIO_IS_SUBSCRIPTION=%s;window.OPPIO_STRIPE_PK=%s;window.OPPIO_ORDER_ID=%s;window.OPPIO_CUSTOMER_ID=%s;window.OPPIO_AJAX_URL=%s;window.OPPIO_CLIENT_SECRET=%s;',
            $has_subscription ? 'true' : 'false',
            json_encode($pk ?: ''),
            json_encode($order_id ?: ''),
            json_encode($customer_id ?: ''),
            json_encode( admin_url('admin-ajax.php') ),
            json_encode($client_secret ?: '') // ✅ PRIDANÉ
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

    /* UCET ZAKAZNIKA & zrusenie preplatneho */
    /**
     * Pridá endpoint pre subscription záložku
     */
    /*
    public function add_subscription_account_endpoint() {
        add_rewrite_endpoint('moje-predplatne', EP_ROOT | EP_PAGES);
    }
        */

    /**
     * Pridá záložku do account menu
     */
    /*
    public function add_subscription_menu_item($items) {
        // Pridaj pred logout
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['moje-predplatne'] = __('Moje predplatné', 'oppio-subscriptions'); //'Moje predplatné';
        $items['customer-logout'] = $logout;

        return $items;
    }
        */
    /**
     * Pridá endpoint pre /moje-predplatne
     */
    public function add_subscription_account_endpoint() {
        add_rewrite_endpoint('moje-predplatne', EP_ROOT | EP_PAGES);
        // voliteľné, ale nevadí:
        add_filter('query_vars', function($vars){
            if (!in_array('moje-predplatne', $vars, true)) {
                $vars[] = 'moje-predplatne';
            }
            return $vars;
        });
    }

    /**
     * Pridá záložku do Account menu (pred Logout)
     */
    public function add_subscription_menu_item($items) {
        if (!isset($items['customer-logout'])) {
            // ochrana, ak niektoré témy preusporiadajú menu
            $items['moje-predplatne'] = __('Moje predplatné', 'oppio-subscriptions');
            return $items;
        }

        $logout = $items['customer-logout'];
        unset($items['customer-logout']);

        // kľúč MUSÍ byť presne názov endpointu
        $items['moje-predplatne'] = __('Moje predplatné', 'oppio-subscriptions');
        $items['customer-logout'] = $logout;

        return $items;
    }


    /**
     * Enqueue scripts pre account stránku - S PRELOŽITEĽNÝMI TEXTAMI
     */
    public function enqueue_account_scripts() {
        if (is_account_page()) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);

            wp_enqueue_script('oppio-account-subscription', plugin_dir_url(__FILE__) . 'account-subscription.js', array('jquery'), '1.0.0', true);



            wp_localize_script('oppio-account-subscription', 'oppio_account_ajax', array(
                'ajax_url'          => admin_url('admin-ajax.php'),
                                 
                 'stripe_pk' => (function () {
                    $s = get_option('woocommerce_stripe_settings', array());
                    $test = isset($s['testmode']) && $s['testmode'] === 'yes';
                    return $test ? ($s['test_publishable_key'] ?? '') : ($s['publishable_key'] ?? '');
                })(),
                'sca_nonce' => wp_create_nonce('oppio_sca'),

                'nonce'             => wp_create_nonce('cancel_subscription_nonce'),
                'checkout_url'      => wc_get_checkout_url(),
                
                // ✅ PRELOŽITEĽNÉ TEXTY PRE POPUP
                'texts' => array(
                    // Chybové správy
                    'missing_data_error'    => __('Chýbajúce údaje pre spracovanie požiadavky', 'oppio-subscriptions'),
                    'server_error'          => __('Chyba pri komunikácii so serverom', 'oppio-subscriptions'),
                    
                    // ⬇️⬇️ PRIDAJ TOTO (texty pre SCA)
                    'sca_not_found'         => __('Nenašiel som žiadny SCA link pre toto predplatné.', 'oppio-subscriptions'),
                    'sca_fetch_error'       => __('Chyba pri získaní 3D Secure linku.', 'oppio-subscriptions'),
                    // ⬆️⬆️ PRIDAJ TOTO

                    // Cancel popup texty
                    'cancel_title'          => __('Naozaj si želáš zrušiť svoje predplatné?', 'oppio-subscriptions'),
                    'cancel_description'    => __('Prídeš o výhody ako <strong>automatické doručenie</strong> alebo <strong>zvýhodnenú cenu</strong>.', 'oppio-subscriptions'),
                    'cancel_reminder'       => __('<strong>Pripomenutie:</strong> Predplatné môžeš kedykoľvek obnoviť vo svojom účte.', 'oppio-subscriptions'),
                    'confirm_cancel'        => __('Áno, zrušiť predplatné', 'oppio-subscriptions'),
                    'cancelling'            => __('Ruším predplatné...', 'oppio-subscriptions'),
                    
                    // Renew popup texty
                    'renew_title'           => __('Obnoviť predplatné?', 'oppio-subscriptions'),
                    'renew_description'     => __('Produkty z pôvodného predplatného budú pridané do košíka a budete presmerovaný na pokladňu.', 'oppio-subscriptions'),
                    'renew_info'            => __('<strong>Info:</strong> Obnovenie vytvorí nové predplatné s aktuálnymi cenami a podmienkami.', 'oppio-subscriptions'),
                    'confirm_renew'         => __('Áno, obnoviť predplatné', 'oppio-subscriptions'),
                    'renewing'              => __('Obnovujem predplatné...', 'oppio-subscriptions'),
                    
                    // Všeobecné texty
                    'popup_cancel'          => __('Zrušiť', 'oppio-subscriptions'),
                    'status_cancelled'      => __('Zrušené', 'oppio-subscriptions'),
                )
            ));
        }
    }

    public function ajax_oppio_confirm_pi() {
        check_ajax_referer('oppio_sca');

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

    /**
     * Obsah záložky predplatných
     */
    public function subscription_account_content() {
        if (!is_user_logged_in()) {
            echo '<div class="woocommerce-MyAccount-content"><p>' . esc_html__('Musíte byť prihlásený.', 'oppio-subscriptions') . '</p></div>';
            return;
        }

        $current_user_id = get_current_user_id();
        echo '<div class="oppio-subscriptions-wrapper">';
        echo '<h2>' . esc_html__('Moje predplatné', 'oppio-subscriptions') . '</h2>';

        // Bezpečne získaj subscriptions
        $subscriptions = [];
        if (method_exists($this, 'get_user_subscriptions')) {
            $subscriptions = (array) $this->get_user_subscriptions($current_user_id);
        }
        
        if (empty($subscriptions)) {
            // Vylepšený empty state s call-to-action
            echo '<div class="oppio-empty-subscriptions" style="text-align: center; padding: 40px 20px; background: #f8f9fa; border-radius: 8px; margin: 20px 0;">';
            
            // Ikona
            echo '<div style="font-size: 64px; margin-bottom: 20px; opacity: 0.6;">📦</div>';
            
            // Hlavný text  
            echo '<h3 style="color: #333; margin-bottom: 15px; font-size: 24px;">' . __('Nemáte žiadne predplatné', 'oppio-subscriptions') . '</h3>';
            
            // Popis
            echo '<p style="color: #666; font-size: 16px; line-height: 1.6; max-width: 500px; margin: 0 auto 25px;">';
            echo __('Zatiaľ ste si neobjednali žiadne predplatné. Vyberte si z našej ponuky a ušetrite až 20% s pravidelným dodaním kombuchi.', 'oppio-subscriptions');
            echo '</p>';
            
            // Call-to-action tlačidlá
            echo '<div class="oppio-cta-buttons" style="margin-top: 25px;">';
            
            // Hlavné CTA tlačidlo
            echo '<a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button" style="background: #194A37; color: white; padding: 12px 30px; font-size: 16px; border-radius: 6px; text-decoration: none; margin-right: 15px; display: inline-block;">';
            echo __('🛒 Objednať predplatné', 'oppio-subscriptions');
            echo '</a>';
            
            // Sekundárne tlačidlo
            echo '<a href="' . esc_url(home_url()) . '" class="button" style="background: transparent; color: #194A37; padding: 12px 30px; font-size: 16px; border: 2px solid #194A37; border-radius: 6px; text-decoration: none; display: inline-block;">';
            echo __('ℹ️ Zistiť viac', 'oppio-subscriptions');
            echo '</a>';
            
            echo '</div>'; // End CTA buttons
            
            // Výhody predplatného
            echo '<div class="oppio-benefits" style="margin-top: 35px; padding-top: 25px; border-top: 1px solid #dee2e6;">';
            echo '<h4 style="color: #333; margin-bottom: 20px; font-size: 18px;">' . __('Výhody predplatného:', 'oppio-subscriptions') . '</h4>';
            
            echo '<div style="display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; max-width: 600px; margin: 0 auto;">';
            
            // Benefit 1
            echo '<div style="text-align: center; min-width: 150px;">';
            echo '<div style="font-size: 32px; margin-bottom: 10px;">💰</div>';
            echo '<div style="font-weight: 600; color: #333; margin-bottom: 5px;">' . __('Až 20% zľava', 'oppio-subscriptions') . '</div>';
            echo '<div style="color: #666; font-size: 14px;">' . __('Na všetky produkty.', 'oppio-subscriptions') . '</div>';
            echo '</div>';
            
            // Benefit 2  
            echo '<div style="text-align: center; min-width: 150px;">';
            echo '<div style="font-size: 32px; margin-bottom: 10px;">🚚</div>';
            echo '<div style="font-weight: 600; color: #333; margin-bottom: 5px;">' . __('Pravidelné dodanie', 'oppio-subscriptions') . '</div>';
            echo '<div style="color: #666; font-size: 14px;">' . __('Každý mesiac alebo každých 14 dní.', 'oppio-subscriptions') . '</div>';
            echo '</div>';
            
            // Benefit 3
            echo '<div style="text-align: center; min-width: 150px;">';
            echo '<div style="font-size: 32px; margin-bottom: 10px;">⚙️</div>';
            echo '<div style="font-weight: 600; color: #333; margin-bottom: 5px;">' . __('Flexibilné riadenie', 'oppio-subscriptions') . '</div>';
            echo '<div style="color: #666; font-size: 14px;">' . __('Pozastaviť alebo zrušiť kedykoľvek', 'oppio-subscriptions') . '</div>';
            echo '</div>';
            
            echo '</div>'; // End benefits grid
            echo '</div>'; // End benefits section
            
            echo '</div>'; // End empty subscriptions wrapper
            echo '</div>'; // End main wrapper
            return;
        }

        echo '<div class="oppio-subscriptions-table-wrapper">';
            echo '<table class="shop_table shop_table_responsive oppio-subscriptions-table">';
                echo '<thead>';
                    echo '<tr>';

                        // echo '<th>' . __('Objednávka', 'oppio-subscriptions') . '</th>';
                        // echo '<th>' . __('Typ predplatného', 'oppio-subscriptions') . '</th>';
                        // echo '<th>' . __('Produkty', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Stav', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Suma', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Ďalšie doručenie', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Detail', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Akcia', 'oppio-subscriptions') . '</th>';

                    echo '</tr>';
                echo '</thead>';

                echo '<tbody>';
                    foreach ($subscriptions as $subscription) {
                        $this->render_subscription_row($subscription);
                    }
                echo '</tbody>';
            echo '</table>';
        echo '</div>';

        // CSS sa pridava z OPPIO plugin z assets/css/sin-oppio__subscribe-account.css

        echo '</div>';

        // ===== OPPIO: ADMIN STRIPE DIAGNOSTIKA =====
        /*
        if ( is_user_logged_in() && ( current_user_can('administrator') || wp_get_current_user()->user_email === 'juraj@sin.sk') ) {

            // Pomocné
            $S = get_option('woocommerce_stripe_settings', []);
            $keys = [
                'live' => $S['secret_key']      ?? '',
                'test' => $S['test_secret_key'] ?? '',
            ];
            $mask = fn($v)=> $v ? substr($v,0,8).str_repeat('*', max(0, strlen($v)-8)) : '(empty)';

            $get = function($url,$key){
                $r = wp_remote_get($url, [
                    'headers'=>['Authorization'=>'Bearer '.$key,'Stripe-Version'=>'2024-06-20'],
                    'timeout'=>20,
                ]);
                if (is_wp_error($r)) return ['err',0,$r->get_error_message(),null];
                $code=(int)wp_remote_retrieve_response_code($r);
                $body=wp_remote_retrieve_body($r);
                $j=json_decode($body,true);
                return [$code>=200&&$code<300?'ok':'fail',$code,$body,$j];
            };

            echo '<div style="margin-top:24px;padding:18px;border:2px dashed #ccc;background:#fffef6">';
            echo '<h3>🔎 Stripe diagnostika (iba admin)</h3>';
            echo '<p style="margin:.5em 0">LIVE key: <code>'.$mask($keys['live']).'</code><br>TEST key: <code>'.$mask($keys['test']).'</code></p>';

            // Prejdi to, čo práve zobrazuješ
            foreach (($subscriptions ?? []) as $sub) {
                if (empty($sub['order']) || !($sub['order'] instanceof WC_Order)) continue;
                /** @var WC_Order $order * /
                $order = $sub['order'];
                $oid   = $order->get_id();
                $subId = $sub['stripe_subscription_id'] ?? $order->get_meta('_oppio_stripe_subscription_id');
                $subId = trim((string)$subId);

                $cached  = (string)$order->get_meta('_oppio_subscription_status');
                $envMeta = (string)$order->get_meta('_oppio_subscription_env') ?: (string)$order->get_meta('_oppio_status_env');
                $lastChk = (int)$order->get_meta('_oppio_status_last_check');

                if (!$subId) continue; // nič na diagnostiku

                // urči poradie prostredí
                $try = in_array($envMeta,['live','test'],true) ? [$envMeta, $envMeta==='live'?'test':'live'] : ['live','test'];

                $diag = ['sub'=>null,'sub_code'=>0,'inv'=>null,'pi'=>null,'pi_err'=>null,'env'=>null];
                foreach ($try as $env) {
                    if (empty($keys[$env])) continue;
                    [$ok1,$code1,$body1,$j1] = $get('https://api.stripe.com/v1/subscriptions/'.rawurlencode($subId), $keys[$env]);
                    if ($ok1==='ok' && !empty($j1['id'])) {
                        $diag['env'] = $env;
                        $diag['sub'] = $j1;
                        $diag['sub_code'] = $code1;

                        // latest invoice (ak nie je v objekte, skús vyhľadať poslednú)
                        $invId = $j1['latest_invoice']['id'] ?? ($j1['latest_invoice'] ?? '');
                        if (!$invId) {
                            [$okL,$codeL,$bodyL,$jL] = $get('https://api.stripe.com/v1/invoices?subscription='.rawurlencode($subId).'&limit=1', $keys[$env]);
                            if ($okL==='ok' && !empty($jL['data'][0]['id'])) $invId = $jL['data'][0]['id'];
                        }
                        if ($invId) {
                            [$oki,$codei,$bodyi,$ji] = $get('https://api.stripe.com/v1/invoices/'.rawurlencode($invId), $keys[$env]);
                            if ($oki==='ok') {
                                $diag['inv'] = $ji + ['_http'=>$codei];
                                $piId = $ji['payment_intent']['id'] ?? ($ji['payment_intent'] ?? '');
                                if ($piId) {
                                    [$okp,$codep,$bodyp,$jp] = $get('https://api.stripe.com/v1/payment_intents/'.rawurlencode($piId), $keys[$env]);
                                    if ($okp==='ok') {
                                        $diag['pi'] = $jp + ['_http'=>$codep];
                                    } else {
                                        $jpe = json_decode($bodyp,true);
                                        $diag['pi_err'] = ['http'=>$codep,'type'=>$jpe['error']['type'] ?? 'n/a','msg'=>$jpe['error']['message'] ?? substr($bodyp,0,120)];
                                    }
                                }
                            }
                        }
                        break; // úspech v jednom env stačí
                    }
                }

                // Render
                echo '<div style="margin:14px 0;padding:12px;background:#f9f9f9;border:1px solid #ddd">';
                echo '<div><strong>Order #'.$oid.'</strong> &middot; sub: <code>'.$subId.'</code> &middot; env: <code>'.($diag['env'] ?: '??').'</code>';
                echo ' &middot; cached=<code>'.$cached.'</code> &middot; last='.($lastChk?date('Y-m-d H:i',$lastChk):'-').'</div>';

                if (!$diag['sub']) {
                    echo '<div style="color:#b00;margin-top:6px">❌ Subscription sa nenašlo v LIVE ani TEST (alebo chýbajú kľúče). Preto vidíš “Neznámy/Chyba API”.</div>';
                } else {
                    $s = $diag['sub'];
                    echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #eee;padding:8px;margin-top:8px">';
                    echo "SUB status: ".($s['status'] ?? 'n/a')." | cancel_at: ".($s['cancel_at'] ?? '–')." | pause: ".(($s['pause_collection']['behavior'] ?? '–'))."\n";
                    if ($diag['inv']) {
                        $i = $diag['inv'];
                        echo "INV #".($i['id'] ?? 'n/a')." status=".($i['status']??'n/a')." total=".($i['amount_due']??'n/a')." next_attempt=".(!empty($i['next_payment_attempt'])?date('Y-m-d H:i',$i['next_payment_attempt']):'–')."\n";
                        if ($diag['pi']) {
                            $p = $diag['pi'];
                            echo "PI #".($p['id']??'n/a')." status=".($p['status']??'n/a')." last_payment_error=".($p['last_payment_error']['message'] ?? '–')."\n";
                        } elseif ($diag['pi_err']) {
                            echo "PI ERROR http=".$diag['pi_err']['http']." type=".$diag['pi_err']['type']." msg=".$diag['pi_err']['msg']."\n";
                        } else {
                            echo "PI: –\n";
                        }
                    } else {
                        echo "No invoice found.\n";
                    }
                    echo "</pre>";

                    // Odporúčanie
                    $hint = 'OK';
                    $status = $s['status'] ?? '';
                    if (in_array($status,['incomplete','past_due','unpaid'],true)) {
                        $hint = 'Potrebná akcia zákazníka: dokončiť platbu alebo pridať kartu.';
                    } elseif (!$diag['inv']) {
                        $hint = 'Bez otvorenej faktúry – skontroluj plán/sub schedule.';
                    }
                    echo '<div style="margin-top:6px"><em>Hint:</em> '.$hint.'</div>';
                }
                echo '</div>';
            }

            echo '</div>';
        }
        */

    }

    /**
     * Získa všetky predplatné používateľa
     */
    private function get_user_subscriptions($user_id) {
        $orders = wc_get_orders(array(
            'customer_id'   => $user_id,
            'meta_key'      => '_oppio_is_subscription',
            'meta_value'    => 'yes',
            'limit'         => 40,
            'orderby'       => 'date',
            'order'         => 'DESC'
        ));

        $subscriptions = array();

        foreach ($orders as $order) {
            $subscription_type = $order->get_meta('_oppio_subscription_type');
            $stripe_subscription_id = $order->get_meta('_oppio_stripe_subscription_id');

            if ($subscription_type) {
                // Získaj stav Stripe subscription
                $stripe_status = $this->get_stripe_subscription_status($stripe_subscription_id);
                
                $subscriptions[] = array(
                    'order'                     => $order,
                    'subscription_type'         => $subscription_type,
                    'stripe_subscription_id'    => $stripe_subscription_id,
                    'stripe_status'             => $stripe_status,
                    'products'                  => $this->get_subscription_products($order),
                    'total'                     => $order->get_total(),
                    'created'                   => $order->get_date_created(),
                );
            }
        }

        return $subscriptions;
    }

    /**
     * Získa stav Stripe subscription
     */
    /*
    private function get_stripe_subscription_status($stripe_subscription_id) {
        if (empty($stripe_subscription_id)) {
            return 'neznámy';
        }

        // Získaj Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

        if ($test_mode) {
            $api_key = isset($stripe_settings['test_secret_key']) ? $stripe_settings['test_secret_key'] : '';
        } 
        else {
            $api_key = isset($stripe_settings['secret_key']) ? $stripe_settings['secret_key'] : '';
        }

        if (empty($api_key)) {
            return 'chyba-api';
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20'
        );

        $response = wp_remote_get('https://api.stripe.com/v1/subscriptions/' . $stripe_subscription_id, array(
            'headers' => $headers,
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            return 'chyba-api';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['status'])) {
            return $data['status'];
        }

        return __('neznámy', 'oppio-subscriptions');
    }
        */
    /**
     * Zí­ska stav Stripe subscription - S CACHE SYSTÉMOM
     * moje-predplatne
     */
    private function get_stripe_subscription_status($stripe_subscription_id) {
        $stripe_subscription_id = trim((string)$stripe_subscription_id);
        if ($stripe_subscription_id === '') return 'unknown';

        $normalize = function($s){
            $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$s)) : strtolower(trim((string)$s));
            if ($s === '' || $s === 'unknown' || $s === 'neznámy' || $s === 'neznamy') return 'unknown';
            if ($s === 'api_error' || $s === 'chyba-api') return 'api_error';
            $ok = ['active','canceled','incomplete','incomplete_expired','past_due','unpaid','trialing'];
            return in_array($s,$ok,true) ? $s : 'unknown';
        };

        // nájdi order pre cache / env
        $orders = wc_get_orders(['meta_key'=>'_oppio_stripe_subscription_id','meta_value'=>$stripe_subscription_id,'limit'=>1]);
        /** @var WC_Order|null $order */
        $order  = !empty($orders) ? $orders[0] : null;

        // Woo kľúče
        $set  = get_option('woocommerce_stripe_settings', []);
        $keys = [
            'live' => $set['secret_key']      ?? '',
            'test' => $set['test_secret_key'] ?? '',
        ];
        $woo_env = (!empty($set['testmode']) && $set['testmode']==='yes') ? 'test' : 'live';

        // preferované env z objednávky alebo z Woo
        $saved_env = $order instanceof WC_Order ? ($order->get_meta('_oppio_subscription_env') ?: $order->get_meta('_oppio_status_env')) : '';
        $pref_env  = in_array($saved_env, ['live','test'], true) ? $saved_env : $woo_env;

        // CACHE (platí iba ak sedí env + sub_id a nie je unknown/api_error)
        if ($order instanceof WC_Order) {
            $c_status = $normalize($order->get_meta('_oppio_subscription_status'));
            $c_env    = (string)$order->get_meta('_oppio_status_env');
            $c_subid  = (string)$order->get_meta('_oppio_status_sub_id');
            $last     = (int)$order->get_meta('_oppio_status_last_check');
            if ($c_status!=='unknown' && $c_status!=='api_error' && $c_env===$pref_env && $c_subid===$stripe_subscription_id && $last && (time()-$last)<600) {
                return $c_status;
            }
        }

        // volanie Stripe pre zvolené env
        $fetch = function($env) use($keys,$stripe_subscription_id){
            $key = $keys[$env] ?? '';
            if ($key==='') return ['fail','no_key',null];
            $r = wp_remote_get('https://api.stripe.com/v1/subscriptions/'.rawurlencode($stripe_subscription_id),[
                'headers'=>['Authorization'=>'Bearer '.$key,'Stripe-Version'=>'2024-06-20'],
                'timeout'=>20,
            ]);
            if (is_wp_error($r)) return ['fail','wp_error',$r->get_error_message()];
            $code = (int) wp_remote_retrieve_response_code($r);
            $body = wp_remote_retrieve_body($r);
            $j    = json_decode($body,true);
            if ($code>=200 && $code<300 && empty($j['error'])) return ['ok',$j['status']??'unknown',null];
            $etype = $j['error']['type'] ?? ('http_'.$code);
            return ['fail',$etype,$body];
        };

        // poradie pokusov: preferované env -> druhé env pri 404/resource_missing
        $order_of_envs = [$pref_env, $pref_env==='live' ? 'test' : 'live'];

        foreach ($order_of_envs as $idx => $env) {
            [$res,$st] = $fetch($env);
            if ($res==='ok') {
                $norm = $normalize($st);
                if ($order instanceof WC_Order) {
                    // trvalé priradenie env k objednávke
                    $order->update_meta_data('_oppio_subscription_env', $env);
                    // cache
                    $order->update_meta_data('_oppio_subscription_status', $norm);
                    $order->update_meta_data('_oppio_status_env', $env);
                    $order->update_meta_data('_oppio_status_sub_id', $stripe_subscription_id);
                    $order->update_meta_data('_oppio_status_last_check', time());
                    $order->save();
                }
                return $norm;
            }
            // ak prvý pokus bol 404/resource_missing, skús druhé env
            if ($idx===0 && in_array($st, ['resource_missing','http_404'], true)) {
                continue;
            }
            // ostatné chyby: skús ďalšie env len ak sme ešte neskúšali, inak padni nižšie
        }

        // neúspech v oboch env – neprepíš dobrú cache chybou
        if ($order instanceof WC_Order) {
            $cur = $normalize($order->get_meta('_oppio_subscription_status'));
            if (in_array($cur,['unknown','api_error'],true)) {
                $order->update_meta_data('_oppio_subscription_status','api_error');
                $order->update_meta_data('_oppio_status_env',$pref_env);
                $order->update_meta_data('_oppio_status_sub_id',$stripe_subscription_id);
                $order->update_meta_data('_oppio_status_last_check',time());
                $order->save();
            }
        }
        return 'api_error';
    }


    /**
     * Získa produkty z predplatného
     */
    private function get_subscription_products($order) {
        $products = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $products[] = array(
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total()
                );
            }
        }

        return $products;
    }

    /**
     * Vykreslí riadok tabuľky pre predplatné
     */
    /*
    private function render_subscription_row($subscription) {
        $order = $subscription['order'];
        $stripe_status = $subscription['stripe_status'];

        // Mapovanie Stripe statusov
        $status_labels = array(
            'active'                => array('label' => __('Aktívne', 'oppio-subscriptions') , 'class' => 'active'),
            'canceled'              => array('label' => __('Zrušené', 'oppio-subscriptions') , 'class' => 'cancelled'),
            'incomplete'            => array('label' => __('Neúplné', 'oppio-subscriptions') , 'class' => 'pending'),
            'incomplete_expired'    => array('label' => __('Vypršané', 'oppio-subscriptions') , 'class' => 'expired'),
            'past_due'              => array('label' => __('Po splatnosti', 'oppio-subscriptions') , 'class' => 'on-hold'),
            'unpaid'                => array('label' => __('Nezaplatené', 'oppio-subscriptions') , 'class' => 'pending'),
            'trialing'              => array('label' => __('Skúšobné', 'oppio-subscriptions') , 'class' => 'active'),
            'neznámy'               => array('label' => __('Neznámy', 'oppio-subscriptions') , 'class' => 'pending'),
            'chyba-api'             => array('label' => __('Chyba API', 'oppio-subscriptions') , 'class' => 'failed')
        );

        $order->update_meta_data('_oppio_subscription_status', $status);

        $neznamy = __('Neznámy', 'oppio-subscriptions');
        $status_info = $status_labels[$stripe_status] ?? array('label' => $neznamy, 'class' => 'pending');

        // Typ predplatného
        $type_labels = array(
            'monthly'   => __('Mesačné predplatné', 'oppio-subscriptions'),
            'biweekly'  => __('14-dňové predplatné', 'oppio-subscriptions'),
            'daily'     => __('Denné predplatné', 'oppio-subscriptions'),
        );

        $type_label = $type_labels[$subscription['subscription_type']] ?? $neznamy;

        echo '<tr class="subscription-row">';
            
            // Stav
            echo '<td data-title="' . __('Stav', 'oppio-subscriptions') . '" class="stav">';
            echo '<span class="subscription-status status-' . $status_info['class'] . '">';
            echo esc_html($status_info['label']);
            echo '</span>';
            echo '</td>';

            // Suma
            echo '<td data-title="' . __('Suma', 'oppio-subscriptions') . '" class="suma">';
            echo '<span class="subscription-total">' . wc_price($subscription['total']) . '</span>';
            echo '</td>';

            // Ďalšie doručenie
            echo '<td data-title="'. __('Ďalšie doručenie', 'oppio-subscriptions') . '" class="dorucenie">';
                // Vypočítaj dátum ďalšieho doručenia len pre aktívne predplatné
                if ($stripe_status === 'active') {
                    $next_delivery_dates = $this->calculate_next_delivery_range($subscription['subscription_type'], $subscription['created']);
                    echo '<span>'. $next_delivery_dates . '</span>';
                } else {
                    echo '<span>—</span>';
                }
            echo '</td>';

            // Zobraziť viac informácií o objednávke
            echo '<td data-title="'. __('Zobraziť viac', 'oppio-subscriptions') . '" class="zobraz-viac">';
                echo '<a class="button" href="' . esc_url($order->get_view_order_url()) . '">' . __('Zobraziť viac', 'oppio-subscriptions'). '</a>';
            echo '</td>';

            // Zrušiť / Obnoviť predplatné
            echo '<td data-title="'. __('Zrušiť / Obnoviť', 'oppio-subscriptions') . '" class="akcie">';
                if ($stripe_status === 'active') {
                    // Tlačidlo pre zrušenie aktívneho predplatného
                    echo '<button type="button" class="button cancel-subscription" ';
                    echo 'data-subscription-id="' . esc_attr($subscription['stripe_subscription_id']) . '" ';
                    echo 'data-order-id="' . esc_attr($order->get_id()) . '">';
                    echo __('Zrušiť', 'oppio-subscriptions');
                    echo '</button>';
                } elseif ($stripe_status === 'canceled') {
                    // Tlačidlo pre obnovenie zrušeného predplatného
                    echo '<button type="button" class="button renew-subscription" ';
                    echo 'data-order-id="' . esc_attr($order->get_id()) . '" ';
                    echo 'data-subscription-type="' . esc_attr($subscription['subscription_type']) . '">';
                    echo __('Obnoviť', 'oppio-subscriptions');
                    echo '</button>';
                } else {
                    // Pre ostatné stavy
                    echo '<span class="no-actions">—</span>';
                }
            echo '</td>';
        echo '</tr>';
    }
        */
    /**
     * Vykreslí­ riadok tabuľky pre predplatné - OPRAVENÉ
     * moje-predplatne
     */
    /*
    private function render_subscription_row($subscription) {
        $order = $subscription['order'];
        $stripe_status = $subscription['stripe_status'];
        
        // ✅ SKÚS NAJPRV CACHED STATUS
        $cached_status = $order->get_meta('_oppio_subscription_status');
        $actual_status = !empty($cached_status) ? $cached_status : $stripe_status;

        // ✅ ULOŽ DEBUG INFO DO GLOBAL ARRAY
        global $oppio_debug_info;
        if (!isset($oppio_debug_info)) {
            $oppio_debug_info = array();
        }
        $oppio_debug_info[] = [
            'order_id' => $order->get_id(),
            'stripe_status' => $stripe_status,
            'cached_status' => $cached_status,
            'actual_status' => $actual_status,
            'last_check' => $order->get_meta('_oppio_status_last_check'),
            'subscription_id' => $order->get_meta('_oppio_stripe_subscription_id')
        ];
                    
        // Mapovanie Stripe statusov
       $status_labels = [
            'active'             => ['label' => __('Aktívne','oppio-subscriptions'), 'class'=>'active'],
            'canceled'           => ['label' => __('Zrušené','oppio-subscriptions'), 'class'=>'cancelled'],
            'incomplete'         => ['label' => __('Neúplné','oppio-subscriptions'), 'class'=>'pending'],
            'incomplete_expired' => ['label' => __('Vypršané','oppio-subscriptions'), 'class'=>'expired'],
            'past_due'           => ['label' => __('Po splatnosti','oppio-subscriptions'), 'class'=>'on-hold'],
            'unpaid'             => ['label' => __('Nezaplatené','oppio-subscriptions'), 'class'=>'pending'],
            'trialing'           => ['label' => __('Skúšobné','oppio-subscriptions'), 'class'=>'active'],
            'unknown'            => ['label' => __('Neznámy','oppio-subscriptions'), 'class'=>'pending'],
            'api_error'          => ['label' => __('Chyba API','oppio-subscriptions'), 'class'=>'failed'],
        ];

        $neznamy = __('Neznámy', 'oppio-subscriptions');
        $status_info = $status_labels[$actual_status] ?? array('label' => $neznamy, 'class' => 'pending');

        // Typ predplatného
        $type_labels = array(
            'monthly'   => __('Mesačné predplatné', 'oppio-subscriptions'),
            'biweekly'  => __('14-dňové predplatné', 'oppio-subscriptions'),
            'daily'     => __('Denné predplatné', 'oppio-subscriptions'),
        );

        $type_label = $type_labels[$subscription['subscription_type']] ?? $neznamy;

        // ⬇️ NOVÉ – vezmeme uložený 3DS link (ak existuje)
        $sca_url = trim((string) $order->get_meta('_oppio_sca_redirect_url'));
        $has_sca_url = !empty($sca_url);

        echo '<tr class="subscription-row">';
            
            // ✅ STAV - POUŽÍVA ACTUAL STATUS
            echo '<td data-title="' . __('Stav', 'oppio-subscriptions') . '" class="stav">';
            echo '<span class="subscription-status status-' . $status_info['class'] . '">';
            echo esc_html($status_info['label']);
            echo '</span>';
            echo '</td>';

            // Suma
            echo '<td data-title="' . __('Suma', 'oppio-subscriptions') . '" class="suma">';
            echo '<span class="subscription-total">' . wc_price($subscription['total']) . '</span>';
            echo '</td>';

            // Ďalšie doručenie
            echo '<td data-title="'. __('Ďalšie doručenie', 'oppio-subscriptions') . '" class="dorucenie">';
                // Vypočítaj dátum ďalšieho doručenia len pre aktívne predplatné
                if ($actual_status === 'active') {
                    $next_delivery_dates = $this->calculate_next_delivery_range($subscription['subscription_type'], $subscription['created']);
                    echo '<span>'. $next_delivery_dates . '</span>';
                } else {
                    echo '<span>—</span>';
                }
            echo '</td>';

            // Zobraziť viac informácií o objednávke
            echo '<td data-title="'. __('Zobraziť viac', 'oppio-subscriptions') . '" class="zobraz-viac">';
                echo '<a class="button" href="' . esc_url($order->get_view_order_url()) . '">' . __('Zobraziť viac', 'oppio-subscriptions'). '</a>';
            echo '</td>';

            // ✅ ZRUŠIŤ / OBNOVIŤ / DOKONČIŤ PLATBU
            echo '<td data-title="'. esc_attr__('Zrušiť / Obnoviť', 'oppio-subscriptions') . '" class="akcie">';

                // Aktívne → Zrušiť
                if (in_array($actual_status, ['active','trialing'], true)) {
                    echo '<button type="button" class="button cancel-subscription" '
                    . 'data-subscription-id="' . esc_attr($subscription['stripe_subscription_id']) . '" '
                    . 'data-order-id="' . esc_attr($order->get_id()) . '">'
                    . esc_html__('Zrušiť', 'oppio-subscriptions')
                    . '</button>';

                // Zrušené → Obnoviť
                } 
                elseif ($actual_status === 'canceled') {
                    echo '<button type="button" class="button renew-subscription" '
                    . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                    . 'data-subscription-type="' . esc_attr($subscription['subscription_type']) . '">'
                    . esc_html__('Obnoviť', 'oppio-subscriptions')
                    . '</button>';

                // Neúplné / Po splatnosti / Nezaplatené → Dokončiť platbu
                }
                elseif (in_array($actual_status, ['incomplete','past_due','unpaid'], true)) {
                    $nonce = wp_create_nonce('oppio_retry_' . $order->get_id());
                    echo '<button type="button" class="button complete-payment" '
                    . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                    . 'data-nonce="'   . esc_attr($nonce) . '">'
                    . esc_html__('Dokončiť platbu', 'oppio-subscriptions')
                    . '</button>';

                // Neúplné / Po splatnosti / Nezaplatené → Dokončiť platbu / 3DS link
                }
                elseif (in_array($actual_status, ['incomplete','past_due','unpaid'], true)) {

                    // Ak už máme uložený 3DS redirect link, ukážeme ho priamo ako tlačidlo
                    if ($has_sca_url) {
                        echo '<a class="button" href="' . esc_url($sca_url) . '" target="_blank" rel="noopener">'
                        . esc_html__('Dokončiť overenie 3D Secure', 'oppio-subscriptions')
                        . '</a>';

                        // voliteľne: sekundárne tlačidlo na nový pokus (ak by odkaz expiroval)
                        $nonce = wp_create_nonce('oppio_retry_' . $order->get_id());
                        echo ' <button type="button" class="button complete-payment" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                        . 'data-nonce="'   . esc_attr($nonce) . '">'
                        . esc_html__('Skúsiť znova', 'oppio-subscriptions')
                        . '</button>';

                    } else {
                        // fallback – ak 3DS link nemáme, nechávame pôvodné tlačidlo na nový pokus
                        $nonce = wp_create_nonce('oppio_retry_' . $order->get_id());
                        echo '<button type="button" class="button complete-payment" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                        . 'data-nonce="'   . esc_attr($nonce) . '">'
                        . esc_html__('Dokončiť platbu', 'oppio-subscriptions')
                        . '</button>';
                    }

                // Iné stavy (unknown/api_error/incomplete_expired …)
                }
                else {
                    echo '<span class="no-actions">—</span>';
                }

            echo '</td>';


        echo '</tr>';
    }
        */

    /*
     * AJAX: Vyziadanie zo Stripe 3DS link len podľa subscription_id
     */
    public function ajax_get_sca_url() {
        // nonce (musí sa zhodovať s tým, čo posiela JS)
        check_ajax_referer('oppio_sca');

        // 1) Vstupy
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $sub_id   = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        $order    = $order_id > 0 ? wc_get_order($order_id) : null;

        // 2) Stripe kľúč
        $s = get_option('woocommerce_stripe_settings', array());
        $test = isset($s['testmode']) && $s['testmode'] === 'yes';
        $api_key = $test ? ($s['test_secret_key'] ?? '') : ($s['secret_key'] ?? '');
        if ($api_key === '') wp_send_json_error(['message' => 'Chýba Stripe API key.']);

        $headers = [
            'Authorization'  => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        // 3) Máme uložený link? Vráť ho
        if ($order) {
            $sca = trim((string) $order->get_meta('_oppio_sca_redirect_url'));
            if ($sca !== '') wp_send_json_success(['sca_url' => $sca]);
            if ($sub_id === '') $sub_id = (string) $order->get_meta('_oppio_stripe_subscription_id');
        }
        if ($sub_id === '') wp_send_json_error(['message' => 'Chýba subscription_id.']);

        // helper – uloží URL a vráti JSON
        $save_and_return = function($url, $pi_secret = '', $pi_id = '') use ($order) {
            if ($order && $url !== '') {
                $order->update_meta_data('_oppio_sca_redirect_url', $url);
                $order->save();
            }
            wp_send_json_success([
                'sca_url'          => $url,
                'pi_client_secret' => $pi_secret,
                'pi_id'            => $pi_id,
            ]);
        };

        // 4) Pozri posledné faktúry (expand PI)
        $inv_list_url = 'https://api.stripe.com/v1/invoices?subscription=' . rawurlencode($sub_id) . '&limit=10&expand[]=data.payment_intent';
        $list = wp_remote_get($inv_list_url, ['headers' => $headers, 'timeout' => 30]);
        $latest_invoice = null;

        if (!is_wp_error($list) && (int) wp_remote_retrieve_response_code($list) === 200) {
            $invoices = json_decode(wp_remote_retrieve_body($list), true)['data'] ?? [];

            foreach ($invoices as $inv) {
                if ($latest_invoice === null) $latest_invoice = $inv; // prvá je najnovšia

                $pi         = $inv['payment_intent'] ?? [];
                $pi_status  = isset($pi['status']) ? strtolower((string)$pi['status']) : '';
                $last_err   = isset($pi['last_payment_error']['code']) ? strtolower((string)$pi['last_payment_error']['code']) : '';
                $sca_url    = $pi['next_action']['redirect_to_url']['url'] ?? '';
                $pi_secret  = $pi['client_secret'] ?? '';
                $pi_id      = $pi['id'] ?? '';
                $hosted_url = $inv['hosted_invoice_url'] ?? '';

                // 4a) Priamy 3DS
                if ($pi_status === 'requires_action' || $last_err === 'authentication_required') {
                    if ($sca_url !== '') $save_and_return($sca_url, $pi_secret, $pi_id);
                    if ($pi_secret !== '') {
                        wp_send_json_success([
                            'sca_url'          => '',
                            'pi_client_secret' => $pi_secret,
                            'pi_id'            => $pi_id,
                            'message'          => 'Použi Stripe.js na overenie.',
                        ]);
                    }
                }

                // 4b) Fallback: hosted invoice (Payment page)
                if ($hosted_url !== '') $save_and_return($hosted_url);
            }

            // 5) Skús /pay na najnovšej faktúre – vytvorí nový PI
            if ($latest_invoice) {
                $inv_id     = $latest_invoice['id'];
                $inv_status = strtolower((string)($latest_invoice['status'] ?? ''));
                $hosted_url = $latest_invoice['hosted_invoice_url'] ?? '';

                if ($inv_status === 'draft') {
                    wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($inv_id) . '/finalize', [
                        'headers' => $headers, 'body' => http_build_query([]), 'timeout' => 30,
                    ]);
                }

                $pay = wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($inv_id) . '/pay', [
                    'headers' => $headers,
                    'body'    => http_build_query(['expand[]' => 'payment_intent']),
                    'timeout' => 30,
                ]);

                if (!is_wp_error($pay) && (int) wp_remote_retrieve_response_code($pay) === 200) {
                    $paid     = json_decode(wp_remote_retrieve_body($pay), true);
                    $pi       = $paid['payment_intent'] ?? [];
                    $pi_stat  = strtolower((string)($pi['status'] ?? ''));
                    $sca_url  = $pi['next_action']['redirect_to_url']['url'] ?? '';
                    $pi_secret= $pi['client_secret'] ?? '';
                    $pi_id    = $pi['id'] ?? '';

                    if ($pi_stat === 'requires_action' && $sca_url !== '') $save_and_return($sca_url, $pi_secret, $pi_id);
                    if ($pi_stat === 'requires_action' && $pi_secret !== '') {
                        wp_send_json_success([
                            'sca_url'          => '',
                            'pi_client_secret' => $pi_secret,
                            'pi_id'            => $pi_id,
                            'message'          => 'Použi Stripe.js na overenie.',
                        ]);
                    }
                    if ($pi_stat === 'succeeded' || (isset($paid['status']) && $paid['status'] === 'paid')) {
                        wp_send_json_error(['message' => 'Faktúra sa už zaplatila – obnov stránku.']);
                    }
                    if ($hosted_url !== '') $save_and_return($hosted_url);

                    wp_send_json_error(['message' => 'Treba novú platobnú metódu. Použi „Skúsiť znova“.']);
                }
            }
        }

        // 6) Nič sme nenašli
        wp_send_json_error(['message' => 'Nenašiel som žiadny SCA link pre toto predplatné.']);
    }


    /**
     * Nájde najnovšiu FAILED/akciu vyžadujúcu invoice pre daný order->subscription
     * a vráti sca_url + navrhnutý status ('past_due'), ak to dáva zmysel.
     */
    private function fetch_sca_url_from_failed_child_invoice( $order ) {
        $result = ['status' => '', 'sca_url' => ''];

        // 1) API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if ($api_key === '') return $result;

        // 2) Subscription ID z order meta
        $sub_id = (string) $order->get_meta('_oppio_stripe_subscription_id');
        if ($sub_id === '') return $result;

        // 3) Zoznam invoices pre subscription (rozbalíme payment_intent)
        $url = 'https://api.stripe.com/v1/invoices?subscription=' . rawurlencode($sub_id) . '&limit=10&expand[]=data.payment_intent';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $code !== 200) return $result;

        $data = json_decode($resp, true);
        $invoices = $data['data'] ?? [];

        // 4) Prejdi faktúry od najnovšej a hľadaj takú, ktorá:
        // - má PaymentIntent, ktorý vyžaduje akciu (3DS), alebo
        // - posledná chyba = authentication_required, alebo
        // - je označená ako failed
        foreach ($invoices as $inv) {
            $pi = $inv['payment_intent'] ?? [];
            $pi_status     = isset($pi['status']) ? strtolower(trim((string)$pi['status'])) : '';
            $last_err_code = isset($pi['last_payment_error']['code']) ? strtolower(trim((string)$pi['last_payment_error']['code'])) : '';
            $sca_url       = $pi['next_action']['redirect_to_url']['url'] ?? '';

            $is_failed = (isset($inv['status']) && strtolower($inv['status']) === 'open' && $last_err_code === 'authentication_required')
                        || ($pi_status === 'requires_action')
                        || ($last_err_code === 'authentication_required');

            if ($is_failed) {
                if ($sca_url !== '') {
                    $result['sca_url'] = $sca_url;
                }
                // Pri takomto stave chceme v UI „past_due“, aby sa ukázalo tlačidlo
                $result['status'] = 'past_due';
                break;
            }
        }

        return $result;
    }


    private function render_subscription_row($subscription) {
        $order = $subscription['order'];
        $stripe_status = $subscription['stripe_status'];

        // 1) cached vs stripe + normalizácia
        $cached_status = $order->get_meta('_oppio_subscription_status');
        $actual_status = !empty($cached_status) ? $cached_status : $stripe_status;
        $actual_status = is_string($actual_status) ? strtolower(trim($actual_status)) : '';
        if ($actual_status === '') { $actual_status = 'unknown'; }

        // 2) debug do globálu (nezobrazuje sa, len na prípadné logovanie)
        global $oppio_debug_info;
        if (!isset($oppio_debug_info)) { $oppio_debug_info = array(); }
        $oppio_debug_info[] = [
            'order_id'        => $order->get_id(),
            'stripe_status'   => $stripe_status,
            'cached_status'   => $cached_status,
            'actual_status'   => $actual_status,
            'last_check'      => $order->get_meta('_oppio_status_last_check'),
            'subscription_id' => $order->get_meta('_oppio_stripe_subscription_id'),
        ];

        // 3) mapovanie vizuálnych štítkov
        $status_labels = [
            'active'             => ['label' => __('Aktívne','oppio-subscriptions'), 'class'=>'active'],
            'canceled'           => ['label' => __('Zrušené','oppio-subscriptions'), 'class'=>'cancelled'],
            'incomplete'         => ['label' => __('Neúplné','oppio-subscriptions'), 'class'=>'pending'],
            'incomplete_expired' => ['label' => __('Vypršané','oppio-subscriptions'), 'class'=>'expired'],
            'past_due'           => ['label' => __('Potvrdenie Autentifikácie','oppio-subscriptions'), 'class'=>'on-hold'],
            'unpaid'             => ['label' => __('Nezaplatené','oppio-subscriptions'), 'class'=>'pending'],
            'trialing'           => ['label' => __('Skúšobné','oppio-subscriptions'), 'class'=>'active'],
            'unknown'            => ['label' => __('Neznámy','oppio-subscriptions'), 'class'=>'pending'],
            'api_error'          => ['label' => __('Chyba API','oppio-subscriptions'), 'class'=>'failed'],
        ];
        $neznamy = __('Neznámy', 'oppio-subscriptions');

        // 4) 3DS link z objednávky (ak už je uložený)
        $sca_url     = trim((string) $order->get_meta('_oppio_sca_redirect_url'));
        $has_sca_url = ($sca_url !== '');

        // 5) ak chýba subscription_id → pokús sa doplniť zo Stripe (search/faktúry)
        $sub_id = (string) $order->get_meta('_oppio_stripe_subscription_id');
        if ($sub_id === '') {
            if (method_exists($this, 'repair_and_attach_subscription_id')) {
                $repaired = $this->repair_and_attach_subscription_id($order);
                if (!empty($repaired['id'])) {
                    $sub_id = $repaired['id'];
                    if (!empty($repaired['status'])) {
                        $actual_status = strtolower(trim((string)$repaired['status']));
                        $order->update_meta_data('_oppio_subscription_status', $actual_status);
                        $order->save();
                    }
                }
            }
        }

        // 6) ak je problémový stav alebo chýba 3DS link → skús „child“ failed invoice
        if (
            $actual_status === 'unknown' ||
            (!$has_sca_url && in_array($actual_status, ['incomplete','past_due','unpaid'], true))
        ) {
            if (method_exists($this, 'fetch_sca_url_from_failed_child_invoice')) {
                $fallback = $this->fetch_sca_url_from_failed_child_invoice($order);
                if (!empty($fallback['sca_url']) && !$has_sca_url) {
                    $sca_url = $fallback['sca_url'];
                    $has_sca_url = true;
                    $order->update_meta_data('_oppio_sca_redirect_url', $sca_url);
                    $order->save();
                }
                if (!empty($fallback['status'])) {
                    $actual_status = strtolower(trim((string)$fallback['status']));
                    $order->update_meta_data('_oppio_subscription_status', $actual_status);
                    $order->save();
                }
            }
        }

        // 7) posledný fallback: ak stále „unknown“ a máme sub_id → načítaj zo Stripe (expand PI)
        if ($actual_status === 'unknown' && $sub_id !== '') {
            $stripe_settings = get_option('woocommerce_stripe_settings', array());
            $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
            $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');

            if ($api_key !== '') {
                $url = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($sub_id) . '?expand[]=latest_invoice.payment_intent';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($resp && $code === 200) {
                    $data = json_decode($resp, true);

                    // stav subscription
                    $sub_status_from_stripe = isset($data['status']) ? strtolower(trim((string)$data['status'])) : '';
                    if ($sub_status_from_stripe !== '') {
                        $actual_status = $sub_status_from_stripe;
                        $order->update_meta_data('_oppio_subscription_status', $actual_status);
                    }

                    // údaje o invoice / PI
                    $invoice_id = $data['latest_invoice']['id'] ?? '';
                    if ($invoice_id !== '') {
                        $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id);
                    }
                    $pi = $data['latest_invoice']['payment_intent'] ?? [];
                    if (!empty($pi['id'])) {
                        $order->update_meta_data('_oppio_stripe_pi_id', $pi['id']);
                    }
                    if (!empty($pi['client_secret'])) {
                        $order->update_meta_data('_oppio_stripe_payment_intent_secret', $pi['client_secret']);
                    }

                    $pi_status     = isset($pi['status']) ? strtolower(trim((string)$pi['status'])) : '';
                    $last_err_code = isset($pi['last_payment_error']['code']) ? strtolower(trim((string)$pi['last_payment_error']['code'])) : '';
                    if ($pi_status === 'requires_action' || $last_err_code === 'authentication_required') {
                        $actual_status = 'past_due';
                        $order->update_meta_data('_oppio_subscription_status', $actual_status);
                    }

                    if (!$has_sca_url && !empty($pi['next_action']['redirect_to_url']['url'])) {
                        $sca_url = $pi['next_action']['redirect_to_url']['url'];
                        $has_sca_url = true;
                        $order->update_meta_data('_oppio_sca_redirect_url', $sca_url);
                    }

                    $order->save();
                }
            }
        }

        // 8) vizuál po všetkých opravách
        $status_info = $status_labels[$actual_status] ?? ['label' => $neznamy, 'class' => 'pending'];

        // typ predplatného (len popis)
        $type_labels = [
            'monthly'  => __('Mesačné predplatné', 'oppio-subscriptions'),
            'biweekly' => __('14-dňové predplatné', 'oppio-subscriptions'),
            'daily'    => __('Denné predplatné', 'oppio-subscriptions'),
        ];
        $type_label = $type_labels[$subscription['subscription_type']] ?? $neznamy;

        // sub_id pre AJAX tlačidlo
        $sub_id_for_ajax = (string) $order->get_meta('_oppio_stripe_subscription_id');

        echo '<tr class="subscription-row">';

            // Stav
            echo '<td data-title="' . esc_attr__('Stav', 'oppio-subscriptions') . '" class="stav">';
                echo '<span class="subscription-status status-' . esc_attr($status_info['class']) . '">';
                echo esc_html($status_info['label']);
                echo '</span>';
            echo '</td>';

            // Suma
            echo '<td data-title="' . esc_attr__('Suma', 'oppio-subscriptions') . '" class="suma">';
                echo '<span class="subscription-total">' . wc_price($subscription['total']) . '</span>';
            echo '</td>';

            // Ďalšie doručenie
            echo '<td data-title="'. esc_attr__('Ďalšie doručenie', 'oppio-subscriptions') . '" class="dorucenie">';
                if ($actual_status === 'active') {
                    $next_delivery_dates = $this->calculate_next_delivery_range($subscription['subscription_type'], $subscription['created']);
                    echo '<span>'. esc_html($next_delivery_dates) . '</span>';
                } else {
                    echo '<span>—</span>';
                }
            echo '</td>';

            // Detail objednávky
            echo '<td data-title="'. esc_attr__('Zobraziť viac', 'oppio-subscriptions') . '" class="zobraz-viac">';
                echo '<a class="button" href="' . esc_url($order->get_view_order_url()) . '">' . esc_html__('Zobraziť viac', 'oppio-subscriptions'). '</a>';
            echo '</td>';

            // Akcie
            echo '<td data-title="'. esc_attr__('Zrušiť / Obnoviť', 'oppio-subscriptions') . '" class="akcie">';

                if (in_array($actual_status, ['active','trialing'], true)) {
                    // Zrušiť
                    echo '<button type="button" class="button cancel-subscription" '
                        . 'data-subscription-id="' . esc_attr($subscription['stripe_subscription_id']) . '" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '">'
                        . esc_html__('Zrušiť', 'oppio-subscriptions')
                        . '</button>';

                } elseif ($actual_status === 'canceled') {
                    // Obnoviť
                    echo '<button type="button" class="button renew-subscription" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                        . 'data-subscription-type="' . esc_attr($subscription['subscription_type']) . '">'
                        . esc_html__('Obnoviť', 'oppio-subscriptions')
                        . '</button>';

                } elseif (in_array($actual_status, ['incomplete','past_due','unpaid'], true)) {

                    if ($has_sca_url) {
                        echo '<a class="button" href="' . esc_url($sca_url) . '" target="_blank" rel="noopener">'
                            . esc_html__('Zaplatiť faktúru', 'oppio-subscriptions')
                            . '</a> ';
                    }
                    else {
                        // AJAX tlačidlo – vytiahne SCA link podľa subscription_id
                        echo '<button type="button" class="button fetch-sca" '
                            . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                            . 'data-subscription-id="' . esc_attr($sub_id_for_ajax) . '">'
                            . esc_html__('Zaplatiť faktúru', 'oppio-subscriptions')
                            . '</button> ';
                    }
                    // sekundárne „Skúsiť znova“
                    $nonce = wp_create_nonce('oppio_retry_' . $order->get_id());
                    echo '<button type="button" class="button complete-payment" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                        . 'data-nonce="'   . esc_attr($nonce) . '">'
                        . esc_html__('Dokončiť overenie', 'oppio-subscriptions')
                        . '</button>';

                } else {
                    // unknown / api_error / incomplete_expired a pod.
                    echo '<span class="no-actions">—</span>';
                }

            echo '</td>';

        echo '</tr>';
    }

    // 7734
    /**
     * Debug info pre order – pozrie uložené meta + Stripe subscription → latest_invoice.payment_intent
     * Vráti pole s kľúčmi (safe na vypísanie).
     */
    private function _oppio_collect_subscription_debug_info( WC_Order $order ) : array {
        $out = [
            'order_id'             => $order->get_id(),
            'cached_status'        => (string) $order->get_meta('_oppio_subscription_status'),
            'stripe_status_input'  => '', // to čo si mu poslal do renderu ako $stripe_status (nevieme tu, doplníme nižšie)
            'actual_status_calc'   => '', // normalizované/opravené
            'subscription_id'      => (string) $order->get_meta('_oppio_stripe_subscription_id'),
            'sca_url_meta'         => (string) $order->get_meta('_oppio_sca_redirect_url'),
            'stripe' => [
                'subscription_status' => '',
                'latest_invoice_id'   => '',
                'latest_invoice_status' => '',
                'pi_status'           => '',
                'pi_last_error_code'  => '',
                'pi_next_action_url'  => '',
            ],
            'notes' => [],
        ];

        // Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');

        if ($api_key === '') {
            $out['notes'][] = 'Chýba Stripe API key.';
            return $out;
        }
        if ($out['subscription_id'] === '') {
            $out['notes'][] = 'V objednávke chýba _oppio_stripe_subscription_id.';
            return $out;
        }

        // GET /v1/subscriptions/{id}?expand[]=latest_invoice.payment_intent
        $url = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($out['subscription_id']) . '?expand[]=latest_invoice.payment_intent';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $code !== 200) {
            $out['notes'][] = 'Stripe API chyba: HTTP ' . $code;
            return $out;
        }

        $data = json_decode($resp, true);
        $sub_status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : '';
        $invoice    = $data['latest_invoice'] ?? [];
        $pi         = $invoice['payment_intent'] ?? [];

        $out['stripe']['subscription_status']   = $sub_status;
        $out['stripe']['latest_invoice_id']     = (string)($invoice['id'] ?? '');
        $out['stripe']['latest_invoice_status'] = isset($invoice['status']) ? strtolower(trim((string)$invoice['status'])) : '';
        $out['stripe']['pi_status']             = isset($pi['status']) ? strtolower(trim((string)$pi['status'])) : '';
        $out['stripe']['pi_last_error_code']    = isset($pi['last_payment_error']['code']) ? strtolower(trim((string)$pi['last_payment_error']['code'])) : '';
        $out['stripe']['pi_next_action_url']    = (string)($pi['next_action']['redirect_to_url']['url'] ?? '');

        return $out;
    }

    private function calculate_next_delivery_range($subscription_type, $created_date) {
        // Intervaly pre rôzne typy predplatného
        $intervals = array(
            'monthly'   => '+1 month',
            'biweekly'  => '+14 days', 
            'daily'     => '+1 day',
        );
        
        $interval = $intervals[$subscription_type] ?? '+1 month';
        
        // Vypočítaj dátum ďalšieho doručenia (od poslednej platby)
        $next_delivery_base = $created_date->getTimestamp();
        $next_delivery_base = strtotime($interval, $next_delivery_base);
        
        // Rozsah doručenia: od základného dátumu do +5 dní
        $delivery_start = date('d.m.Y', $next_delivery_base);
        $delivery_end = date('d.m.Y', strtotime('+5 days', $next_delivery_base));
        
        return $delivery_start . ' - ' . $delivery_end;
    }

    /**
     * AJAX handler pre zrušenie predplatného
     */
    public function ajax_cancel_subscription() {
        // Verifikácia nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cancel_subscription_nonce')) {
            wp_send_json_error(array('message' => 'Neplatný nonce'));
        }

        // Overenie používateľa
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Musíte byť prihlásený'));
        }

        $stripe_subscription_id = sanitize_text_field($_POST['subscription_id']);
        $order_id = intval($_POST['order_id']);

        if (empty($stripe_subscription_id) || empty($order_id)) {
            wp_send_json_error(array('message' => 'Chýbajúce údaje'));
        }

        // Overenie vlastníctva objednávky
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != get_current_user_id()) {
            wp_send_json_error(array('message' => 'Nemáte oprávnenie zrušiť toto predplatné'));
        }

        // Zruš v Stripe
        $stripe_result = $this->cancel_stripe_subscription($stripe_subscription_id);

        if ($stripe_result['success']) {
            // Aktualizuj lokálne údaje
            $order->update_meta_data('_oppio_subscription_cancelled_date', current_time('mysql'));
            $order->update_meta_data('_oppio_subscription_cancelled_by', 'customer');
            $order->add_order_note(__('Predplatné zrušené zákazníkom cez účet', 'oppio-subscriptions'));
            $order->save();
 
            // Pošli email
            $this->send_cancellation_email($order);

            wp_send_json_success(array(
                'message' => __('Predplatné bolo úspešne zrušené', 'oppio-subscriptions'),
            ));
        } 
        else {
            wp_send_json_error(array(
                // 'message' => 'Chyba pri rušení predplatného: ' . $stripe_result['error'],
                sprintf(
                    __('Chyba pri rušení predplatného: %s', 'oppio-subscriptions'), 
                    $stripe_result['error']
                ),
            ));
        }
    }

    /**
     * Zruší Stripe subscription
     */
    private function cancel_stripe_subscription($subscription_id) {
        // Získaj Stripe API key
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

        if ($test_mode) {
            $api_key = isset($stripe_settings['test_secret_key']) ? $stripe_settings['test_secret_key'] : '';
        } else {
            $api_key = isset($stripe_settings['secret_key']) ? $stripe_settings['secret_key'] : '';
        }

        if (empty($api_key)) {
            return array('success' => false, 'error' => 'Chýba Stripe API kľúč');
        }

        $headers = array(
            'Authorization'     => 'Bearer ' . $api_key,
            'Content-Type'      => 'application/x-www-form-urlencoded',
            'Stripe-Version'    => '2024-06-20'
        );

        // Cancel subscription immediately
        $response = wp_remote_request('https://api.stripe.com/v1/subscriptions/' . $subscription_id, array(
            'method'    => 'DELETE',
            'headers'   => $headers,
            'timeout'   => 30
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code === 200 && isset($data['status']) && $data['status'] === 'canceled') {
            return array('success' => true, 'data' => $data);
        }

        $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Neznáma chyba';
        return array('success' => false, 'error' => $error_message);
    }

    /**
     * AJAX handler pre obnovenie predplatného
     */
    public function ajax_renew_subscription() {
        // Verifikácia nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cancel_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Neplatný nonce', 'oppio-subscriptions')));
        }

        // Overenie používateľa
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Musíte byť prihlásený', 'oppio-subscriptions')));
        }

        $order_id = intval($_POST['order_id']);
        $subscription_type = sanitize_text_field($_POST['subscription_type']);

        if (empty($order_id)) {
            wp_send_json_error(array('message' => __('Chýbajúce údaje', 'oppio-subscriptions')));
        }

        // Overenie vlastníctva objednávky
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != get_current_user_id()) {
            wp_send_json_error(array('message' => __('Nemáte oprávnenie obnoviť toto predplatné', 'oppio-subscriptions')));
        }

        // Vymaž aktuálny košík
        WC()->cart->empty_cart();

        try {
            // Získaj všetky produkty z pôvodnej objednávky
            $items = $order->get_items();
            $products_added = 0;
            
            foreach ($items as $item_id => $item) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $quantity = $item->get_quantity();
                
                // Skontroluj či produkt ešte existuje a je dostupný
                $product = wc_get_product($variation_id ? $variation_id : $product_id);
                
                if (!$product || !$product->is_purchasable()) {
                    continue; // Preskočí nedostupné produkty
                }

                // Pridaj produkt do košíka
                $cart_item_key = WC()->cart->add_to_cart(
                    $product_id,
                    $quantity,
                    $variation_id
                );

                if ($cart_item_key) {
                    $products_added++;
                    
                    // Pridaj subscription typ do cart item meta
                    WC()->cart->cart_contents[$cart_item_key]['subscription_type'] = $subscription_type;
                }
            }

            if ($products_added === 0) {
                wp_send_json_error(array(
                    'message' => __('Žiadne produkty neboli pridané do košíka. Produkty už nie sú dostupné.', 'oppio-subscriptions')
                ));
            }

            // Nastav subscription session
            WC()->session->set('current_subscription_type', $subscription_type);
            WC()->session->set('pending_subscription_type', $subscription_type);

            // Poznámka do pôvodnej objednávky
            $order->add_order_note(__('Zákazník obnovil predplatné - produkty pridané do košíka', 'oppio-subscriptions'));
            $order->save();

            // Úspešná odpoveď s presmerovaním na pokladňu
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Úspešne pridaných %d produktov do košíka. Presmerovávam na pokladňu...', 'oppio-subscriptions'),
                    $products_added
                ),
                'redirect_url' => wc_get_checkout_url(),
                'products_added' => $products_added
            ));

        } catch (Exception $e) {
            error_log('OPPIO Renew Subscription Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Chyba pri pridávaní produktov do košíka. Skúste to znova.', 'oppio-subscriptions')
            ));
        }
    }

    /**
     * Pošle email o zrušení predplatného
     */    
    private function send_cancellation_email($order) {
        $to = $order->get_billing_email();

        $subject = __('Predplatné bolo zrušené - OPPIO', 'oppio');

        $message = __("Dobrý deň,", 'oppio') . "\n\n";
        $message .= sprintf(
            __("Vaše predplatné č. %s bolo úspešne zrušené.", 'oppio'),
            $order->get_order_number()
        ) . "\n\n";

        $message .= __("Detaily objednávky:", 'oppio') . "\n";
        $message .= sprintf(__("- Objednávka: #%s", 'oppio'), $order->get_order_number()) . "\n";
        $message .= sprintf(__("- Suma: %s", 'oppio'), $order->get_formatted_order_total()) . "\n";
        $message .= sprintf(__("- Dátum zrušenia: %s", 'oppio'), date('d.m.Y H:i')) . "\n\n";

        $message .= __("Ďakujeme za využívanie našich služieb.", 'oppio') . "\n\n";
        $message .= __("Tím OPPIO", 'oppio');

        // $headers = array('Content-Type: text/plain; charset=UTF-8');
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);

        $order->add_order_note(sprintf(
            __('Email o zrušení predplatného odoslaný na: %s', 'oppio'),
            $to
        ));
    }

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

    /**
     * Flush rewrite rules pri aktivácii pluginu
     * Pridaj túto funkciu ak ju nemáš
     */
    public static function flush_rewrite_rules_on_activation() {
        add_rewrite_endpoint('moje-predplatne', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
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
     * Vytvorí renewal objednávku z invoice - S DEBUGGING
     */
    /* DRY RUN */
    /*
    private function create_renewal_order_from_invoice($parent_order, $invoice) {
        // ====== LOG-ONLY / DRY RUN VERZIA ======
        $this->oppio_renew_log('=== [DRY RUN] RENEWAL PREVIEW (from invoice) ===');

        // Parent objednávka
        $parent_id   = $parent_order ? $parent_order->get_id() : 0;
        $customer_id = $parent_order ? $parent_order->get_customer_id() : 0;
        $sub_type    = $parent_order ? $parent_order->get_meta('_oppio_subscription_type') : '';

        $this->oppio_renew_log('Parent order ID: ' . $parent_id);
        $this->oppio_renew_log('Parent customer ID: ' . $customer_id);
        $this->oppio_renew_log('Parent subscription type: ' . ($sub_type ?: 'N/A'));

        // Stripe invoice
        $invoice_id = $invoice['id'] ?? '';
        $billing_reason = $invoice['billing_reason'] ?? '';
        $currency = strtoupper($invoice['currency'] ?? '') ?: ($parent_order ? $parent_order->get_currency() : get_woocommerce_currency());

        $this->oppio_renew_log('Stripe Invoice ID: ' . ($invoice_id ?: 'MISSING'));
        $this->oppio_renew_log('Billing reason: ' . ($billing_reason ?: 'N/A'));
        $this->oppio_renew_log('Currency (used): ' . $currency);

        // Over či už existuje order pre túto invoice (iba info)
        $existing = wc_get_orders([
            'meta_key'   => '_oppio_stripe_invoice_id',
            'meta_value' => $invoice_id ?: '',
            'limit'      => 1,
        ]);
        if ($existing) {
            $this->oppio_renew_log('[DRY RUN] Info: K tejto Stripe invoice už existuje order #' . $existing[0]->get_id());
        }

        // Produkty v parent objednávke
        $parent_products_by_id = [];
        if ($parent_order) {
            foreach ($parent_order->get_items('line_item') as $pi) {
                $p = $pi->get_product();
                if ($p) {
                    $parent_products_by_id[$p->get_id()] = [
                        'id'   => $p->get_id(),
                        'sku'  => $p->get_sku(),
                        'name' => $p->get_name(),
                    ];
                }
            }
        }

        // Prejdi Stripe invoice lines
        $lines   = $invoice['lines']['data'] ?? [];
        $divisor = $this->oppio_minor_unit_divisor($currency);
        $items_count = 0;

        foreach ($lines as $idx => $line) {
            $line_amount_minor = (int)($line['amount'] ?? 0);
            $line_qty          = (int)($line['quantity'] ?? 1);
            if ($line_qty < 1) { $line_qty = 1; }

            $line_total = $divisor ? ($line_amount_minor / $divisor) : 0.0;
            $unit_total = $line_qty > 0 ? ($line_total / $line_qty) : $line_total;

            $oppio_pid = !empty($line['metadata']['oppio_product_id']) ? (int)$line['metadata']['oppio_product_id'] : 0;
            $matched_product = $oppio_pid && isset($parent_products_by_id[$oppio_pid]) ? $parent_products_by_id[$oppio_pid] : null;

            $items_count++;
            $prod_name = $matched_product ? ($matched_product['name'] . ' [ID ' . $matched_product['id'] . ']') : 'NEZNÁMY PRODUKT';

            $this->oppio_renew_log(sprintf(
                '[DRY RUN] Položka %d: %s | qty=%d | unit=%s %s | line=%s %s | (oppio_product_id=%s)',
                $idx,
                $prod_name,
                $line_qty,
                wc_format_decimal($unit_total),
                $currency,
                wc_format_decimal($line_total),
                $currency,
                $oppio_pid ?: 'N/A'
            ));
        }

        if ($items_count === 0) {
            $this->oppio_renew_log('[DRY RUN] Žiadne položky v Stripe invoice!');
        } else {
            $this->oppio_renew_log('[DRY RUN] Spolu položiek: ' . $items_count);
        }

        // Fees len info
        $fees_count = 0;
        if ($parent_order) {
            foreach ($parent_order->get_fees() as $fee_item) {
                $fees_count++;
                $this->oppio_renew_log('[DRY RUN] Fee: ' . $fee_item->get_name() . ' | total=' . $fee_item->get_total());
            }
        }
        $this->oppio_renew_log('[DRY RUN] Počet fee položiek: ' . $fees_count);

        // Finálny sumár
        $this->oppio_renew_log('[DRY RUN] SUMÁR: ' . json_encode([
            'parent_order_id'   => $parent_id,
            'customer_id'       => $customer_id,
            'subscription_type' => $sub_type,
            'stripe_invoice_id' => $invoice_id,
            'currency'          => $currency,
            'items_count'       => $items_count,
            'fees_count'        => $fees_count,
            'note'              => 'Len náhľad, žiadna objednávka nebola vytvorená.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return true; // nič nevytvoríme, len log
    }
        */
    /*
    private function create_renewal_order_from_invoice( $parent_order, $invoice ) {
        // 0) Základná validácia
        if ( ! $parent_order instanceof \WC_Order ) {
            $this->oppio_renew_log('ERROR: parent_order nie je WC_Order');
            return false;
        }
        $invoice_id = $invoice['id'] ?? '';
        if (!$invoice_id) {
            $this->oppio_renew_log('ERROR: chýba invoice_id');
            return false;
        }

        // 1) Ak už existuje renewal pre túto invoice, nič nerob
        $existing = wc_get_orders([
            'meta_key'   => '_oppio_stripe_invoice_id',
            'meta_value' => $invoice_id,
            'limit'      => 1,
            'status'     => array_keys( wc_get_order_statuses() ),
        ]);
        if ($existing) {
            $this->oppio_renew_log('SKIP: order pre invoice '.$invoice_id.' už existuje (#'.$existing[0]->get_id().')');
            return $existing[0];
        }

        // 2) Základné údaje
        $customer_id = (int) $parent_order->get_customer_id();
        $currency    = strtoupper($invoice['currency'] ?? '') ?: $parent_order->get_currency();
        $divisor     = $this->oppio_minor_unit_divisor($currency);
        $this->oppio_renew_log('=== RENEWAL CREATE (invoice '.$invoice_id.') ===');

        // 3) Vytvor order
        $order = wc_create_order(['customer_id' => $customer_id]);
        if (is_wp_error($order) || !($order instanceof \WC_Order)) {
            $this->oppio_renew_log('ERROR: wc_create_order zlyhalo');
            return false;
        }

        // 4) Prepoj a skopíruj adresy/metadá
        $order->set_parent_id( $parent_order->get_id() );
        $order->set_currency( $currency );
        $order->set_payment_method( 'stripe' ); // alebo tvoj gateway ID
        $order->set_payment_method_title( 'Stripe' );

        $order->set_address( $parent_order->get_address('billing'),  'billing' );
        $order->set_address( $parent_order->get_address('shipping'), 'shipping' );

        // 5) Nájdi produkty z parentu podľa oppio_product_id
        $parent_products_by_id = [];
        foreach ($parent_order->get_items('line_item') as $pi) {
            $p = $pi->get_product();
            if ($p) {
                $parent_products_by_id[ $p->get_id() ] = $p;
            }
        }

        // 6) Pridaj položky podľa Stripe invoice
        $lines = $invoice['lines']['data'] ?? [];
        foreach ($lines as $idx => $line) {
            $qty  = max(1, (int)($line['quantity'] ?? 1));
            $amtM = (int)($line['amount'] ?? 0);                  // v minor units
            $line_total = $divisor ? ($amtM / $divisor) : 0.0;    // vo "veľkých" jednotkách
            $unit_total = $qty > 0 ? ($line_total / $qty) : $line_total;

            // nájdi produkt
            $oppio_pid = !empty($line['metadata']['oppio_product_id']) ? (int)$line['metadata']['oppio_product_id'] : 0;
            $product   = ($oppio_pid && isset($parent_products_by_id[$oppio_pid])) ? $parent_products_by_id[$oppio_pid] : null;

            $item = new \WC_Order_Item_Product();
            if ($product) {
                $item->set_product( $product );
                $item->set_name( $product->get_name() );
            } else {
                // fallback – názov z faktúry
                $item->set_name( $line['description'] ?? 'Predplatné' );
            }
            $item->set_quantity( $qty );
            // nastav sumy presne podľa Stripe
            $item->set_subtotal( wc_format_decimal($unit_total * $qty) );
            $item->set_total(    wc_format_decimal($line_total) );
            $order->add_item( $item );
        }

        // 7) Voliteľne skopíruj fee položky z parentu (napr. zľavy ako negatívny fee)
        foreach ($parent_order->get_fees() as $fee_item) {
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name( $fee_item->get_name() );
            $fee->set_total( $fee_item->get_total() );           // zachová rovnakú sumu (môže byť záporná)
            $fee->set_tax_class( $fee_item->get_tax_class() );
            $fee->set_tax_status( $fee_item->get_tax_status() );
            $order->add_item( $fee );
        }

        // 8) Ulož meta a prepojenia
        $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id);
        $order->update_meta_data('_oppio_stripe_subscription_id',
            $invoice['parent']['subscription_details']['subscription'] ?? ''
        );
        $order->update_meta_data('_oppio_subscription_type',
            $parent_order->get_meta('_oppio_subscription_type')
        );

        // 9) Prepočítaj a označ ako zaplatené (alebo 'processing')
        $order->calculate_totals(); // sčíta položky a fee
        $txn = $invoice['payment_intent'] ?? $invoice_id; // čo máš k dispozícii
        $order->payment_complete( $txn ); // nastaví status na 'processing' / 'completed' podľa typu produktu

        $order->add_order_note('🧾 Renewal vytvorený z Stripe invoice '.$invoice_id.'.');
        $order->save();

        $this->oppio_renew_log('SUCCESS: Renewal order #'.$order->get_id().' pre invoice '.$invoice_id);
        return $order;
    }
        */

    private function create_renewal_order_from_invoice($parent_order, $invoice) {
        // ZÁKLADNÁ FUNGUJÚCA VERZIA – vytvorí reálnu renewal objednávku podľa Stripe invoice
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

        // idempotencia – ak už máme renewal s touto invoice, skonči
        $exists = wc_get_orders([
            'meta_key'   => '_oppio_stripe_invoice_id',
            'meta_value' => $invoice_id,
            'limit'      => 1,
            'status'     => array_keys( wc_get_order_statuses() ),
        ]);
        if ($exists) {
            $this->oppio_renew_log('Renewal already exists: #'.$exists[0]->get_id());
            return $exists[0];
        }

        $currency = strtoupper($invoice['currency'] ?? '') ?: $parent_order->get_currency();
        $divisor  = method_exists($this, 'oppio_minor_unit_divisor') ? $this->oppio_minor_unit_divisor($currency) : 100;
        $lines    = $invoice['lines']['data'] ?? [];
        $customer_id = (int) $parent_order->get_customer_id();

        // 1) vytvor novú objednávku
        $order = wc_create_order(['customer_id' => $customer_id]);
        $order->set_currency($currency);

        // skopíruj adresy z parentu
        $order->set_address($parent_order->get_address('billing'), 'billing');
        $order->set_address($parent_order->get_address('shipping'), 'shipping');

        // 2) položky podľa invoice (jednoduché mapovanie cez oppio_product_id)
        $sum = 0.0;
        foreach ($lines as $idx => $line) {
            $amount_minor = (int)($line['amount'] ?? 0);
            $qty          = max(1, (int)($line['quantity'] ?? 1));
            $line_total   = $divisor ? ($amount_minor / $divisor) : 0.0;
            $sum         += $line_total;

            $oppio_pid = !empty($line['metadata']['oppio_product_id']) ? (int)$line['metadata']['oppio_product_id'] : 0;
            $desc      = $line['description'] ?? 'Obnova (stripe)';

            if ($oppio_pid && ($product = wc_get_product($oppio_pid))) {
                $item = new \WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_name($product->get_name());
                $item->set_quantity($qty);
                $item->set_total($line_total);   // nastavíme rovno total podľa Stripe
                $order->add_item($item);
            } else {
                // ak produkt nevieme nájsť, dáme aspoň fee s popisom
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name($desc);
                $fee->set_amount($line_total);
                $fee->set_total($line_total);
                $order->add_item($fee);
            }
        }

        // 3) meta a prepojenia
        $order->update_meta_data('_oppio_stripe_invoice_id', $invoice_id);
        $order->update_meta_data('_oppio_parent_order_id', $parent_order->get_id());
        $order->update_meta_data('_oppio_subscription_type', $parent_order->get_meta('_oppio_subscription_type'));
        $order->update_meta_data('_oppio_stripe_subscription_id', $parent_order->get_meta('_oppio_stripe_subscription_id'));
        $order->update_meta_data('_oppio_subscription_env', $parent_order->get_meta('_oppio_subscription_env'));
        if ($parent_order->get_meta('_oppio_stripe_customer_id')) {
            $order->update_meta_data('_oppio_stripe_customer_id', $parent_order->get_meta('_oppio_stripe_customer_id'));
        }

        // 4) platobná brána/poznámka
        $order->set_payment_method('stripe'); // alias môže byť u teba iný, ale nevadí ak ostane prázdne
        $order->set_payment_method_title('Stripe – obnova predplatného');

        // 5) prepočet a uloženie
        $order->calculate_totals();
        $order->add_order_note('🧾 Renewal vytvorený zo Stripe invoice '.$invoice_id.'.');
        $order->save();

        $this->oppio_renew_log('Renewal created: #'.$order->get_id().' total='.$order->get_total().' '.$currency);

        // podľa typu produktu môžeš dať rovno "completed"
        // if ($order->get_downloadable_items() || $order->get_items() && všetko je virtuálne) $order->update_status('completed');

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
     * Customer section HTML
     */
    private function render_customer_section_html($order) {
        $customer_id = $order->get_customer_id();
        $is_logged_in = is_user_logged_in() && $customer_id > 0 && get_current_user_id() == $customer_id;

        echo '<div class="oppio-customer-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">';
            echo '<h3 style="margin: 0 0 15px 0; color: #495057; font-size: 18px;">👤 '. __('Zákaznícky účet', 'oppio-subscriptions') .'</h3>';

            if ($is_logged_in) {
                $account_url = wc_get_page_permalink('myaccount');
                echo '<p style="margin: 0; font-size: 16px;">✅ '. __('Ste prihlásený', 'oppio-subscriptions') .'</p>';
                echo '<p style="margin: 10px 0 0 0;"><a href="' . esc_url($account_url) . '" class="button" style="background: #28a745; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; display: inline-block;">🔗 '. __('Spravovať v zákaznickom účte', 'oppio-subscriptions') .'</a></p>';
            } 
            else {
                $login_url = wc_get_page_permalink('myaccount');
                echo '<p style="margin: 0; font-size: 16px;">📧 '. __('Prihlasovacie údaje odoslané na email: ', 'oppio-subscriptions') .'<strong>' . esc_html($order->get_billing_email()) . '</strong></p>';
                echo '<p style="margin: 10px 0 0 0;"><a href="' . esc_url($login_url) . '" class="button" style="background: #007cba; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; display: inline-block;">🔗 '. __('Prihlásiť sa do účtu', 'oppio-subscriptions') .'</a></p>';
            }

        echo '</div>';
    }

    /**
     * Subscription section HTML
     */
    private function render_subscription_section_html($order) {
        $subscription_type = $order->get_meta('_oppio_subscription_type');

        // Získaj subscription info
        $subscription_info = $this->get_subscription_display_info($subscription_type);
        $next_delivery = $this->calculate_next_delivery_date($subscription_type);
        $products = $this->get_subscription_products_list($order);
        $savings_info = $this->calculate_subscription_savings($order, $subscription_type);

        echo '<div class="oppio-subscription-section" style="background: #e8f5e8; padding: 20px; border-radius: 8px; border-left: 4px solid #194A37;">';
            echo '<h3 style="margin: 0 0 15px 0; color: #2e7d32; font-size: 18px;">🎉 '. __('Vaše predplatné je aktívne', 'oppio-subscriptions') .'</h3>';

            // Typ predplatného a zľava
            echo '<div style="margin-bottom: 15px;">';
                echo '<p style="margin: 0; font-size: 16px; font-weight: 600;">📦 ' . esc_html($subscription_info['label']) . '</p>';
                if ($savings_info['percent'] > 0) {
                    echo '<p style="margin: 5px 0 0 0; color: #2e7d32; font-weight: 600;">💰 ' . __('Ušetrili ste ', 'oppio-subscriptions') . '' . $savings_info['percent'] . '% (' . wc_price($savings_info['amount']) . ')</p>';
                }
            echo '</div>';

            // Ďalšie dodanie
            echo '<div style="margin-bottom: 15px;">';
                echo '<p style="margin: 0; font-size: 16px;">🚚 ' . __('Ďalšie dodanie:', 'oppio-subscriptions') . '<strong>' . esc_html($next_delivery) . '</strong></p>';
            echo '</div>';

            // Produkty v predplatnom
            if (!empty($products)) {
                echo '<div style="margin-bottom: 15px;">';
                    echo '<p style="margin: 0 0 10px 0; font-size: 16px; font-weight: 600;">📋 ' . __('Produkty v predplatnom:', 'oppio-subscriptions') . '</p>';
                    echo '<ul style="margin: 0; padding-left: 20px;">';
                    foreach ($products as $product) {
                        echo '<li style="margin: 5px 0;">' . esc_html($product['name']) . ' × ' . $product['quantity'] . '</li>';
                    }
                    echo '</ul>';
                echo '</div>';
            }

            // Informácie o objednávke
            echo '<div style="margin-bottom: 15px; padding: 15px; background: rgba(255,255,255,0.7); border-radius: 5px;">';
                echo '<p style="margin: 0 0 5px 0; font-size: 14px;"><strong>📋 ' . __('Detaily objednávky:', 'oppio-subscriptions') . '</strong></p>';
                echo '<p style="margin: 0; font-size: 14px;">• ' . __('Číslo objednávky: ', 'oppio-subscriptions') . '#' . $order->get_order_number() . '</p>';
                echo '<p style="margin: 0; font-size: 14px;">• ' . __('Celková suma: ', 'oppio-subscriptions') . '' . $order->get_formatted_order_total() . '</p>';
                echo '<p style="margin: 0; font-size: 14px;">• ' . __('Dátum objednávky: ', 'oppio-subscriptions') . '' . $order->get_date_created()->date_i18n('j.n.Y H:i') . '</p>';
            echo '</div>';

            // Poznámka o spravovaní
            echo '<div style="background: rgba(255,255,255,0.7); padding: 15px; border-radius: 5px; margin-top: 15px;">';
                echo '<p style="margin: 0; font-size: 14px; color: #666;">ℹ️ ' . __('Predplatné môžete kedykoľvek spravovať, pozastaviť alebo zrušiť vo svojom zákaznickom účte.', 'oppio-subscriptions') . '</p>';
            echo '</div>';

        echo '</div>';
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

    private function get_subscription_products_list($order) {
        $products = array();

        foreach ($order->get_items() as $item) {
            $products[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total()
            );
        }
        return $products;
    }

    private function calculate_subscription_savings($order, $subscription_type) {
        $savings_percent = 0;

        switch ($subscription_type) {
            case 'monthly':
                $savings_percent = 15;
                break;
            case 'biweekly': 
                $savings_percent = 20;
                break;
            case 'daily':
                $savings_percent = 10;
                break;
        }

        // Vypočítaj úspory na základe fees v objednávke
        $discount_amount = 0;
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_total() < 0) {
                $discount_amount += abs($fee->get_total());
            }
        }

        return array(
            'percent' => $savings_percent,
            'amount' => $discount_amount
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

                    echo '<div style="font-weight: 600; color: #333;">' . wc_price($item->get_total()) . '</div>';
                echo '</div>';
            }
        }

            // Totals section
            echo '<div style="border-top: 2px solid #eee; padding-top: 15px;">';

                // Subtotal
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                    echo '<span style="color: #666;">' . __('Medzisúčet', 'oppio-subscriptions') . '</span>';
                    echo '<span style="color: #333;">' . wc_price($order->get_subtotal()) . '</span>';
                echo '</div>';

                // Fees (discounts)
                foreach ($order->get_fees() as $fee) {
                    echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                        echo '<span style="color: #194A37;">' . __('Zľava predplatné', 'oppio-subscriptions') .'</span>';
                        echo '<span style="color: #194A37;">' . wc_price($fee->get_total()) . '</span>';
                    echo '</div>';
                }

                // Shipping
                if ($order->get_shipping_total() > 0) {
                    echo '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                        echo '<span style="color: #666;">' . __('Doprava', 'oppio-subscriptions') . '</span>';
                        echo '<span style="color: #333;">' . wc_price($order->get_shipping_total()) . '</span>';
                    echo '</div>';
                }

                // Total
                echo '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 16px; padding-top: 8px; border-top: 1px solid #eee;">';
                echo '<span>' . __('Spolu', 'oppio-subscriptions') . '</span>';
                echo '<span style="color: #333;">' . $order->get_formatted_order_total() . '</span>';
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

// ====== PRIDAJ AKTIVAČNÝ HOOK NA KONIEC SÚBORU ======
register_activation_hook(__FILE__, array('OPPIO_Subscription_Manager', 'flush_rewrite_rules_on_activation'));

/**
 * 4) Flush rewrite pri aktivácii pluginu (aby endpoint hneď fungoval)
 *    POZOR: flush iba na aktivácii/deaktivácii, nie pri každom loade.
 */
register_activation_hook(__FILE__, function(){
    add_rewrite_endpoint('moje-predplatne', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){
    flush_rewrite_rules();
});