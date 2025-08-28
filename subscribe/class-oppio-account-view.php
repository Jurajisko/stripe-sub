<?php

CLASS OPPIO_Stripe_Status {

    /**
     * Zí­ska stav Stripe subscription - S CACHE SYSTÉMOM
     * moje-predplatne
     */
    public static function get_stripe_subscription_status($stripe_subscription_id) {
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
            if ($c_status!=='unknown' && $c_status!=='api_error' && $c_status!=='active' && $c_env===$pref_env && $c_subid===$stripe_subscription_id && $last && (time()-$last)<600) {
                return $c_status;
            }

        }

        // volanie Stripe pre zvolené env
        $fetch = function($env) use($keys,$stripe_subscription_id){
            $key = $keys[$env] ?? '';
            if ($key==='') return ['fail','no_key',null];
            $url = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($stripe_subscription_id) . '?expand[]=latest_invoice.payment_intent';
            $r = wp_remote_get($url,[
                'headers'=>['Authorization'=>'Bearer '.$key,'Stripe-Version'=>'2024-06-20'],
                'timeout'=>20,
            ]);
            if (is_wp_error($r)) return ['fail','wp_error',$r->get_error_message()];
            $code = (int) wp_remote_retrieve_response_code($r);
            $body = wp_remote_retrieve_body($r);
            $j    = json_decode($body,true);
            if ($code>=200 && $code<300 && empty($j['error'])) return ['ok',$j,null];
            $etype = $j['error']['type'] ?? ('http_'.$code);
            return ['fail',$etype,$body];
        };


        // poradie pokusov: preferované env -> druhé env pri 404/resource_missing
        $order_of_envs = [$pref_env, $pref_env==='live' ? 'test' : 'live'];
        foreach ($order_of_envs as $idx => $env) {
            [$res,$payload,$raw] = $fetch($env);
            if ($res==='ok' && is_array($payload)) {

                // 1) základný stav zo subscription
                $sub_status = $normalize($payload['status'] ?? 'unknown');

                // 2) pozri latest invoice + PI
                $invoice   = $payload['latest_invoice'] ?? [];
                $pi        = is_array($invoice) ? ($invoice['payment_intent'] ?? []) : [];
                $pi_status = strtolower((string)($pi['status'] ?? ''));
                $last_err  = strtolower((string)($pi['last_payment_error']['code'] ?? ''));
                $sca_url   = (string)($pi['next_action']['redirect_to_url']['url'] ?? '');
                $pi_secret = (string)($pi['client_secret'] ?? '');
                $pi_id     = (string)($pi['id'] ?? '');
                $inv_stat  = strtolower((string)($invoice['status'] ?? ''));
                $hosted    = (string)($invoice['hosted_invoice_url'] ?? '');

                // 3) efektívny stav
                $effective = $sub_status;
                if ($pi_status === 'requires_action' || $last_err === 'authentication_required') {
                    $effective = 'past_due';
                } elseif ($inv_stat === 'open' || $pi_status === 'requires_payment_method') {
                    $effective = 'unpaid';
                }

                // 4) cache + meta na objednávku (ak ju vieme nájsť podľa sub_id)
                if ($order instanceof WC_Order) {
                    $order->update_meta_data('_oppio_subscription_env', $env);
                    $order->update_meta_data('_oppio_subscription_status', $effective);
                    $order->update_meta_data('_oppio_status_env', $env);
                    $order->update_meta_data('_oppio_status_sub_id', $stripe_subscription_id);
                    $order->update_meta_data('_oppio_status_last_check', time());

                    if ($pi_id !== '')      $order->update_meta_data('_oppio_stripe_pi_id', $pi_id);
                    if ($pi_secret !== '')  $order->update_meta_data('_oppio_stripe_payment_intent_secret', $pi_secret);
                    if ($sca_url !== '')    $order->update_meta_data('_oppio_sca_redirect_url', $sca_url);
                    elseif ($hosted !== '') $order->update_meta_data('_oppio_sca_redirect_url', $hosted);

                    if (!empty($invoice['id'])) $order->update_meta_data('_oppio_stripe_invoice_id', (string)$invoice['id']);

                    $order->save();
                }

                return $effective;
            }

            // ak prvý pokus bol 404/resource_missing, skús druhé env
            if ($idx===0 && in_array($payload, ['resource_missing','http_404'], true)) {
                continue;
            }
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

    public static function detect_status_without_subscription( WC_Order $order ) {
        // default
        $status = 'unknown';

        // kľúče
        $set     = get_option('woocommerce_stripe_settings', []);
        $is_test = (!empty($set['testmode']) && $set['testmode'] === 'yes');
        $api_key = $is_test ? ($set['test_secret_key'] ?? '') : ($set['secret_key'] ?? '');
        if ($api_key === '') return $status;

        $headers = [
            'Authorization'  => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20',
        ];

        // 1) Stripe customer id, ak ho máš v meta, použi ho; inak skús doľadiť cez email
        $cus_id = (string) $order->get_meta('_oppio_stripe_customer_id');

        if ($cus_id === '') {
            $email = trim((string) $order->get_billing_email());
            if ($email !== '') {
                $q   = 'email:"' . $email . '"';
                $url = 'https://api.stripe.com/v1/customers/search?query=' . rawurlencode($q) . '&limit=1';
                $r = wp_remote_get($url, ['headers'=>$headers, 'timeout'=>20]);
                if (!is_wp_error($r) && (int)wp_remote_retrieve_response_code($r) === 200) {
                    $j = json_decode(wp_remote_retrieve_body($r), true);
                    $cus_id = (string)($j['data'][0]['id'] ?? '');
                    if ($cus_id !== '') {
                        $order->update_meta_data('_oppio_stripe_customer_id', $cus_id);
                        $order->save();
                    }
                }
            }
        }
        if ($cus_id === '') return $status;

        // 2) Pozri posledné faktúry tohto zákazníka, nech nám Stripe povie, čo sa dialo s PI
        $url = 'https://api.stripe.com/v1/invoices?customer=' . rawurlencode($cus_id) . '&limit=10&expand[]=data.payment_intent';
        $r = wp_remote_get($url, ['headers'=>$headers, 'timeout'=>20]);
        if (is_wp_error($r) || (int)wp_remote_retrieve_response_code($r) !== 200) return $status;

        $invoices = json_decode(wp_remote_retrieve_body($r), true)['data'] ?? [];
        if (empty($invoices)) return $status;

        // Budeme hľadať najpravdepodobnejšiu faktúru k tejto objednávke:
        //  - ak invoice obsahuje metadata["woocommerce_order_id"] == current order id → výhra
        //  - inak berieme najnovšiu "open/draft/voided/paid" s payment_intent
        $order_id = (string) $order->get_id();
        $picked   = null;

        foreach ($invoices as $inv) {
            $meta_order_id = (string)($inv['metadata']['woocommerce_order_id'] ?? '');
            if ($meta_order_id !== '' && $meta_order_id === $order_id) { $picked = $inv; break; }
            if ($picked === null && !empty($inv['payment_intent'])) { $picked = $inv; }
        }
        if ($picked === null) return $status;

        // Ulož si id faktúry (pomáha nabudúce)
        if (!empty($picked['id'])) {
            $order->update_meta_data('_oppio_stripe_invoice_id', (string)$picked['id']);
        }

        // 3) Rozhodnutie podľa PaymentIntent / invoice
        $pi         = $picked['payment_intent'] ?? [];
        $pi_status  = strtolower((string)($pi['status'] ?? ''));
        $last_err   = strtolower((string)($pi['last_payment_error']['code'] ?? ''));
        $sca_url    = (string)($pi['next_action']['redirect_to_url']['url'] ?? '');
        $pi_secret  = (string)($pi['client_secret'] ?? '');
        $pi_id      = (string)($pi['id'] ?? '');
        $hosted_url = (string)($picked['hosted_invoice_url'] ?? '');
        $inv_status = strtolower((string)($picked['status'] ?? ''));

        // Ulož, čo sa dá, aby front-end vedel pracovať
        if ($pi_id !== '')      $order->update_meta_data('_oppio_stripe_pi_id', $pi_id);
        if ($pi_secret !== '')  $order->update_meta_data('_oppio_stripe_payment_intent_secret', $pi_secret);

        // Logika stavov (jednoduchá a funkčná):
        //  - ak treba SCA → "past_due" a nastavíme sca_url (alebo hosted_invoice_url)
        if ($pi_status === 'requires_action' || $last_err === 'authentication_required') {
            $status = 'past_due';
            if ($sca_url !== '') {
                $order->update_meta_data('_oppio_sca_redirect_url', $sca_url);
            } elseif ($hosted_url !== '') {
                $order->update_meta_data('_oppio_sca_redirect_url', $hosted_url);
            }
        }
        //  - ak faktúra je otvorená alebo chýba platobná metóda → "unpaid"
        elseif ($inv_status === 'open' || $pi_status === 'requires_payment_method') {
            $status = 'unpaid';
            if ($hosted_url !== '') $order->update_meta_data('_oppio_sca_redirect_url', $hosted_url);
        }
        //  - ak je faktúra draft (nevystavená) → "incomplete"
        elseif ($inv_status === 'draft') {
            $status = 'incomplete';
        }
        //  - inak nevieme – nechaj "unknown"

        // cache pre rýchlosť v UI
        $order->update_meta_data('_oppio_subscription_status', $status);
        $order->update_meta_data('_oppio_status_env', $is_test ? 'test' : 'live');
        $order->update_meta_data('_oppio_status_last_check', time());
        $order->save();

        return $status;
    }

    
}

CLASS OPPIO_ACCOUNT_VIEW {

    public function __construct() {
        
        add_action('wp_enqueue_scripts', array($this, 'enqueue_account_scripts'), 10);

        // Hook pre zákaznícky účet
        add_action('init', array($this, 'add_subscription_account_endpoint'));
        
        add_filter('woocommerce_account_menu_items', array($this, 'add_subscription_menu_item'));

        add_action('woocommerce_account_moje-predplatne_endpoint', array($this, 'subscription_account_content'));

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

    /**
     * Získa všetky predplatné používateľa
     */
    private function get_user_subscriptions($user_id) {
        $orders = wc_get_orders(array(
            'customer_id'   => $user_id,
            'meta_key'      => '_oppio_is_subscription',
            'meta_value'    => 'yes',
            'limit'         => 20,
            'orderby'       => 'date',
            'order'         => 'DESC'
        ));

        $subscriptions = array();

        foreach ($orders as $order) {
            $subscription_type = $order->get_meta('_oppio_subscription_type');
            $stripe_subscription_id = $order->get_meta('_oppio_stripe_subscription_id');

            if ($subscription_type) {
                // Získaj stav Stripe subscription
                if (!empty($stripe_subscription_id)) {
                    $stripe_status = OPPIO_Stripe_Status::get_stripe_subscription_status($stripe_subscription_id);
                } else {
                    // subscription_id chýba → skús zistiť stav z posledných faktúr/PI
                    $stripe_status = OPPIO_Stripe_Status::detect_status_without_subscription($order);
                }

                
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
     * Obsah záložky predplatných
     */
    public function subscription_account_content() {
        if (!is_user_logged_in()) {
            echo '<div class="woocommerce-MyAccount-content"><p>' . esc_html__('Musíte byť prihlásený.', 'oppio-subscriptions') . '</p></div>';
            return;
        }

        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        $show_ids = ( current_user_can('administrator') || ( isset($current_user->user_email) && $current_user->user_email === 'juraj@sin.sk' ) );

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

        // zobraz ID iba pre admina alebo juraj@sin.sk
        $current_user   = wp_get_current_user();
        $show_admin_id  = ( current_user_can('administrator') || ( $current_user && strtolower($current_user->user_email) === 'juraj@sin.sk' ) );


        echo '<div class="oppio-subscriptions-table-wrapper">';
            echo '<table class="shop_table shop_table_responsive oppio-subscriptions-table">';
                echo '<thead>';
                    echo '<tr>';

                        // echo '<th>' . __('Objednávka', 'oppio-subscriptions') . '</th>';
                        // echo '<th>' . __('Typ predplatného', 'oppio-subscriptions') . '</th>';
                        // echo '<th>' . __('Produkty', 'oppio-subscriptions') . '</th>';
                        echo '<th>' . __('Stav', 'oppio-subscriptions') . '</th>';
                        if ( $show_admin_id ) {
                            echo '<th>' . __('ID', 'oppio-subscriptions') . '</th>';
                        }
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
     * Vypočíta rozsah dátumov ďalšieho doručenia na základe typu predplatného a dátumu vytvorenia
     * Predpokladá sa, že doručenie je vždy 5 dní po intervale (napr. mesačné + 5 dní)
     */
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
     * Nájde najnovšiu FAILED/akciu vyžadujúcu invoice pre daný order->subscription
     * a vráti sca_url + navrhnutý status ('past_due'), ak to dáva zmysel.
     */
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

        // ✅ ZJEDNOTENIE VIZUÁLU: past_due zobrazuj ako unpaid (Nezaplatené)
        $display_status = $actual_status;
        if ($actual_status === 'past_due') {
            $display_status = 'unpaid';
        }

        $neznamy = __('Neznámy', 'oppio-subscriptions');

        // 4) 3DS link z objednávky (ak už je uložený)
        $sca_url     = trim((string) $order->get_meta('_oppio_sca_redirect_url'));
        $has_sca_url = ($sca_url !== '');

        // 4a) Zisti, či TÁTO objednávka patrí k NAJNOVŠEJ akčnej faktúre
        $is_active_child = true; // default necháme "true", ale hneď nižšie to skontrolujeme
        $sub_id_for_ajax = (string) $order->get_meta('_oppio_stripe_subscription_id');
        if ($sub_id_for_ajax !== '') {
            $latest = OPPIO_SCA_FLOW::get_latest_actionable_invoice_id($sub_id_for_ajax);
            if (!empty($latest['invoice_id'])) {
                $current_invoice_id = (string) $order->get_meta('_oppio_stripe_invoice_id');
                // „Aktívna“ je len vtedy, keď faktúra na tejto objednávke je NAJNOVŠIA akčná
                $is_active_child = ($current_invoice_id !== '' && $current_invoice_id === $latest['invoice_id']);
            }
        }

        // Ak toto NIE JE aktívna CHILD, preventívne zmaž uložený starý SCA link, aby sa neukazoval
        if (!$is_active_child && $has_sca_url) {
            $order->delete_meta_data('_oppio_sca_redirect_url');
            $order->save();
            $sca_url = '';
            $has_sca_url = false;
        }


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
            if (method_exists('OPPIO_SCA_FLOW', 'fetch_sca_url_from_failed_child_invoice')) {
                $fallback = OPPIO_SCA_FLOW::fetch_sca_url_from_failed_child_invoice($order);
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
        // $status_info = $status_labels[$actual_status] ?? ['label' => $neznamy, 'class' => 'pending'];
        $status_info = $status_labels[$display_status] ?? ['label' => $neznamy, 'class' => 'pending'];


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

            // ID (len admin alebo juraj@sin.sk)
            $show_admin_id = ( current_user_can('administrator') || ( is_user_logged_in() && strtolower(wp_get_current_user()->user_email) === 'juraj@sin.sk' ) );
            if ( $show_admin_id ) {
                $order_no = '#'.$order->get_order_number();
                $sub_id   = trim((string) $order->get_meta('_oppio_stripe_subscription_id'));
                echo '<td data-title="' . esc_attr__('ID', 'oppio-subscriptions') . '" class="id">';
                    echo '<strong>' . esc_html($order_no) . '</strong>';
                    if ( $sub_id !== '' ) {
                        echo '<br><small>' . esc_html($sub_id) . '</small>';
                    }
                echo '</td>';
            }

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

                } 
                elseif ($actual_status === 'canceled') {
                    // Obnoviť
                    echo '<button type="button" class="button renew-subscription" '
                        . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                        . 'data-subscription-type="' . esc_attr($subscription['subscription_type']) . '">'
                        . esc_html__('Obnoviť', 'oppio-subscriptions')
                        . '</button>';

                } 
                elseif (in_array($actual_status, ['incomplete','past_due','unpaid'], true)) {

                     if ($is_active_child) {
                        if ($has_sca_url) {
                            echo '<a class="button" href="' . esc_url($sca_url) . '" target="_blank" rel="noopener">'
                                . esc_html__('Zaplatiť faktúru', 'oppio-subscriptions')
                                . '</a> ';
                        }
                        else {
                            // AJAX tlačidlo – vytiahne SCA link podľa subscription_id (vždy zoberie tú najnovšiu akčnú)
                            echo '<button type="button" class="button fetch-sca" '
                                . 'data-order-id="' . esc_attr($order->get_id()) . '" '
                                . 'data-subscription-id="' . esc_attr($sub_id_for_ajax) . '">'
                                . esc_html__('Zaplatiť faktúru', 'oppio-subscriptions')
                                . '</button> ';
                        }

                    } else {
                        // Táto CHILD už nie je aktívna – nič nezobrazíme
                        echo '<span class="no-actions">—</span>';
                    }
                }

            echo '</td>';

        echo '</tr>';
    }

}


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





CLASS OPPIO_ACCOUNT_ACTIONS {
    

    public function __construct() {

        add_action('wp_ajax_cancel_oppio_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_nopriv_cancel_oppio_subscription', array($this, 'ajax_cancel_subscription'));

        add_action('wp_ajax_renew_oppio_subscription', array($this, 'ajax_renew_subscription'));
        add_action('wp_ajax_nopriv_renew_oppio_subscription', array($this, 'ajax_renew_subscription'));

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


}

CLASS OPPIO_SCA_FLOW {

    public function __construct() {
        // Dokoncti objednavku - prebrat link z FIALED subscribe po TRIAL statuse
        add_action('wp_ajax_oppio_get_sca_url',        [$this, 'ajax_get_sca_url']);
        add_action('wp_ajax_nopriv_oppio_get_sca_url', [$this, 'ajax_get_sca_url']);

        add_action('wp_ajax_oppio_set_default_pm',        [$this, 'ajax_set_default_pm']);
        add_action('wp_ajax_nopriv_oppio_set_default_pm', [$this, 'ajax_set_default_pm']);

    }

    public function ajax_set_default_pm() {
        check_ajax_referer('oppio_sca'); // POZOR: ak máš inde iný názov, zjednoť to

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $pi_id    = isset($_POST['pi_id']) ? sanitize_text_field($_POST['pi_id']) : '';
        if (!$order_id || !$pi_id) wp_send_json_error(['message'=>'missing params']);

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message'=>'order not found']);

        // Stripe key
        $s = get_option('woocommerce_stripe_settings', []);
        $test = isset($s['testmode']) && $s['testmode']==='yes';
        $api_key = $test ? ($s['test_secret_key'] ?? '') : ($s['secret_key'] ?? '');
        if ($api_key==='') wp_send_json_error(['message'=>'no api key']);

        $headers = [
            'Authorization'  => 'Bearer ' . $api_key,
            'Stripe-Version' => '2024-06-20',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        // zisti PM z PI
        $pi_r = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id), [
            'headers'=>$headers,'timeout'=>20
        ]);
        if (is_wp_error($pi_r) || (int)wp_remote_retrieve_response_code($pi_r)!==200)
            wp_send_json_error(['message'=>'pi get fail']);
        $pm_id = json_decode(wp_remote_retrieve_body($pi_r), true)['payment_method'] ?? '';
        if (!$pm_id) wp_send_json_error(['message'=>'no pm']);

        $sub_id = (string)$order->get_meta('_oppio_stripe_subscription_id');
        $cus_id = $order->get_meta('_oppio_stripe_customer_id');

        // nastav default na subscription
        if ($sub_id) {
            wp_remote_post('https://api.stripe.com/v1/subscriptions/' . rawurlencode($sub_id), [
                'headers'=>$headers, 'body'=>http_build_query(['default_payment_method'=>$pm_id]), 'timeout'=>20
            ]);
        }
        // a na customer
        if ($cus_id) {
            wp_remote_post('https://api.stripe.com/v1/customers/' . rawurlencode($cus_id), [
                'headers'=>$headers, 'body'=>http_build_query(['invoice_settings[default_payment_method]'=>$pm_id]), 'timeout'=>20
            ]);
        }

        wp_send_json_success(['ok'=>true]);
    }

    /*
     * AJAX: Vyziadanie zo Stripe 3DS link len podľa subscription_id
     */
    public function ajax_get_sca_url() {
        // nonce (musí sa zhodovať s tým, čo posiela JS)
        check_ajax_referer('oppio_sca', 'sca_nonce');
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
    public static function fetch_sca_url_from_failed_child_invoice( $order ) {
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

    public static function get_latest_actionable_invoice_id( $sub_id ) : array {
        // Výstup: ['invoice_id' => '', 'reason' => '']
        $out = ['invoice_id' => '', 'reason' => ''];

        // Základné kontroly
        $sub_id = trim((string)$sub_id);
        if ($sub_id === '') return $out;

        // Stripe kľúč
        $stripe_settings = get_option('woocommerce_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $api_key   = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        if ($api_key === '') return $out;

        // Stiahni posledné faktúry pre subscription, rozbaľ payment_intent
        $url = 'https://api.stripe.com/v1/invoices?subscription=' . rawurlencode($sub_id) . '&limit=10&expand[]=data.payment_intent';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_key));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $code !== 200) return $out;

        $data = json_decode($resp, true);
        $invoices = $data['data'] ?? [];

        // Prechádzame od najnovšej – prvá v poli je najnovšia
        foreach ($invoices as $inv) {
            $inv_id     = (string)($inv['id'] ?? '');
            $inv_status = strtolower((string)($inv['status'] ?? ''));
            $hosted_url = (string)($inv['hosted_invoice_url'] ?? '');

            $pi         = $inv['payment_intent'] ?? [];
            $pi_status  = strtolower((string)($pi['status'] ?? ''));
            $last_err   = strtolower((string)($pi['last_payment_error']['code'] ?? ''));
            $sca_url    = (string)($pi['next_action']['redirect_to_url']['url'] ?? '');
            $pi_secret  = (string)($pi['client_secret'] ?? '');

            // „Akčná“ znamená: potrebuje akciu alebo je otvorená/bez karty
            $needs_action = ($pi_status === 'requires_action' || $last_err === 'authentication_required');
            $is_open_or_needs_pm = ($inv_status === 'open' || $pi_status === 'requires_payment_method');

            if ($needs_action || $is_open_or_needs_pm) {
                // Toto je NAJNOVŠIA akčná faktúra – tú chceme
                $out['invoice_id'] = $inv_id;
                // pár slov pre debug (nepovinné)
                if     ($needs_action)           $out['reason'] = 'requires_action';
                elseif ($is_open_or_needs_pm)    $out['reason'] = 'open_or_requires_pm';
                else                              $out['reason'] = 'other';
                return $out;
            }
        }

        // Ak nič nenašlo – nechaj prázdne
        return $out;
    }


}
