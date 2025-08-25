console.log('OPPIO');

jQuery(document).ready(function($) {
    console.log('OPPIO Subscription JS loaded - SECTION VERSION');
    
    // ===== SEKCIE HANDLING =====
    
    // Handler pre klik na celú sekciu
    $(document).on('click', 'input[name="subscription_type"]', function(e) {
        // Zabráň kliknutiu ak už klikol na radio button
        if ($(e.target).is('input[type="radio"]')) {
            return;
        }
        
        var $section = $(this);
        var radioButton = $section.find('input[type="radio"]').first();
        
        if (radioButton.length && !radioButton.is(':checked')) {
            radioButton.prop('checked', true).trigger('change');
        }
    });
    
    // Handler pre zmenu radio buttonov - JEDNODUCHÁ VERZIA
    $(document).on('change', 'input[name="subscription_type"]', function() {
        var selectedType = $(this).val();
        
        console.log('Subscription type changed to:', selectedType);
        
        // UI aktualizácie najprv
        updateSectionActiveStates(selectedType);
        
        if (selectedType === 'monthly' || selectedType === 'biweekly') {
            updateSubscriptionPricing(selectedType);
        }
        
        // ✅ LEN TÁTO FUNKCIA - má vlastný refresh!
        convertCartToSubscriptionType(selectedType);
    });

    /**
     * Aktualizuje active classes na sekciách
     */
    function updateSectionActiveStates(selectedType) {
        // Odstráň všetky active classes
        $('.purchase-option').removeClass('active');
        
        // Pridaj active class na správnu sekciu
        if (selectedType === 'none') {
            $('.purchase-option.one-time').addClass('active');
        } else if (selectedType === 'monthly' || selectedType === 'biweekly') {
            $('.purchase-option.subscription').addClass('active');

            // ✅ AKTUALIZUJ Progress bar
            if (typeof window.updateShippingProgress === 'function') {
                window.updateShippingProgress();
            }
        }
        
        console.log('Section active states updated for:', selectedType);
    }
    
    /**
     * Aktualizuje ceny v subscription sekcii
     */
    function updateSubscriptionPricing(subscriptionType) {
        var regularPrice = parseFloat($('#product_regular_price').val());
        
        if (!regularPrice) {
            console.log('No regular price found');
            return;
        }
        
        var discountPercent, newPrice;
        
        switch(subscriptionType) {
            case 'monthly':
                discountPercent = 15;
                newPrice = regularPrice * 0.85;
                break;
            case 'biweekly': 
                discountPercent = 20;
                newPrice = regularPrice * 0.80;
                break;
            default:
                console.log('Unknown subscription type:', subscriptionType);
                return;
        }
        
        console.log('Updating pricing:', {
            type: subscriptionType,
            regularPrice: regularPrice,
            newPrice: newPrice,
            discount: discountPercent
        });
        
        // Aktualizuj badge
        $('.save-badge').text('Ušetri ' + discountPercent + '%');
        
        // Aktualizuj ceny
        $('.current-price').text(formatPrice(newPrice));
        $('.original-price').text(formatPrice(regularPrice)).show();
    }
    
    // Robustná detekcia meny a locale (WooCommerce → WPML lang → doména)
    function detectLocaleAndCurrency() {
        const htmlLang = (document.documentElement.getAttribute('lang') || '').toLowerCase();
        const onCZ = htmlLang.startsWith('cs') || location.hostname.endsWith('.cz');
        const onSK = htmlLang.startsWith('sk') || location.hostname.endsWith('.sk');

        // 1) Preferuj doménu/jazyk (WPML)
        if (onCZ) return { locale: 'cs-CZ', currency: 'CZK' };
        if (onSK) return { locale: 'sk-SK', currency: 'EUR' };

        // 2) Potom skús WooCommerce (ak by bežalo multicurrency a vedelo odovzdať kód)
        try {
            if (window.wcSettings?.currency?.code) {
            const code = window.wcSettings.currency.code;
            const locale = code === 'CZK' ? 'cs-CZ' : 'sk-SK';
            return { locale, currency: code };
            }
        } catch(e){}

        // 3) Fallback SK
        return { locale: 'sk-SK', currency: 'EUR' };
    }

    function formatPrice(price) {
        const { locale, currency } = detectLocaleAndCurrency();
        const n = Number(price) || 0;
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(n);
    }
    
    // ===== KOŠÍK KONVERZIA (zachované z originálu) =====
    function convertCartToSubscriptionType(subscriptionType) {
        if (typeof oppio_subscription_ajax === 'undefined') {
            console.log('OPPIO AJAX object not loaded');
            return;
        }
        
        console.log('Attempting to convert cart to:', subscriptionType);
        
        $.post(oppio_subscription_ajax.ajax_url, {
            action: 'convert_cart_subscription',
            subscription_type: subscriptionType,
            nonce: oppio_subscription_ajax.nonce
        }, function(conversionResponse) {
            console.log('Conversion response:', conversionResponse);
            
            if (conversionResponse.success) {
                // RESET POPUP VŽDY PRI ÚSPEŠNEJ KONVERZII
                // RESET POPUP SWITCH z display_cart_subscription_switch() oppio manager
                console.log('Resetting popup state...');
                var currentKey = localStorage.getItem('oppio_current_popup_key');
                if (currentKey) {
                    localStorage.removeItem(currentKey);
                    localStorage.removeItem('oppio_current_popup_key');
                    console.log('Popup state cleared');
                }

                if (conversionResponse.data.message !== 'Košík je prázdny - nič na konverziu') {
                    console.log('Cart converted successfully:', conversionResponse.data.message);
                    showConversionNotification(conversionResponse.data.message);

                    setTimeout(function() {
                        refreshCartFragments();
                    }, 500);
                } else {
                    console.log('Cart is empty - no items to convert');
                    showConversionNotification('Typ nákupu nastavený pre nové produkty');
                }
            } else {
                console.log('Cart conversion failed:', conversionResponse.data.message);
                showConversionNotification('Chyba: ' + conversionResponse.data.message, 'error');
            }
        }).fail(function(xhr, status, error) {
            console.log('AJAX conversion failed:', status, error);
            showConversionNotification('Chyba pri komunikácii so serverom', 'error');
        });
    }
    
    function refreshCartFragments() {
    console.log('Refreshing SIN minicart...');
    
    // ✅ Použiť tvoju vlastnú AJAX akciu
    $.post('/wp-admin/admin-ajax.php', { 
        action: 'sin_get_minicart' 
    }, function(response) {
        if (response && response.success) {
            console.log('🔄 SIN minicart refreshed successfully');
            
            // Aktualizuj všetky relevantné elementy
            if (response.data.html) {
                $('.minicart-content').html(response.data.html);
            }
            
            // Aktualizuj počty a ceny
            $('.sin-cart-header__icon--quantity .enter-done, .minicart-count, .sin-cart-count')
                .text(response.data.cart_count || 0);
                
            if (response.data.subtotal_html) {
                $('.sin-subtotal').html(response.data.subtotal_html);
            }
            
            if (response.data.cart_total_text) {
                $('.sin-price--value').text(response.data.cart_total_text.replace(/[^\d.,]/g,'').trim());
            }
            
            // Spusť eventy
            $(document.body).trigger('wc_fragments_refreshed');
            $(document.body).trigger('updated_cart_totals');
            $(document).trigger('cart_converted');
            
        } else {
            console.error('SIN minicart refresh failed:', response);
        }
    }).fail(function(xhr, status, error) {
        console.error('SIN minicart AJAX failed:', status, error);
    });
}
    
    function showConversionNotification(message, type = 'success') {
        $('.oppio-conversion-notification').remove();
        
        var bgColor = type === 'error' ? '#f44336' : '#4caf50';
        var icon = type === 'error' ? '❌' : '✓';
        
        var notification = $('<div class="oppio-conversion-notification" style="position: fixed; top: 20px; right: 20px; background: ' + bgColor + '; color: white; padding: 15px; border-radius: 5px; z-index: 9999; font-size: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); max-width: 300px;">' + icon + ' ' + message + '</div>');
        
        $('body').append(notification);
        
        setTimeout(function() {
            notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // ===== SUBMIT HANDLER (zachovaný) =====
    
    $(document).on('submit', 'form.cart', function() {
        var selectedType = $('input[name="subscription_type"]:checked').val();
        
        // ✅ OPRAVENÝ FALLBACK - zachová vybraný typ
        if (!selectedType) {
            // Ak nič nie je vybraté, skús nájsť default z hidden inputu
            selectedType = $('#actual_subscription_type').val() || 'none';
        }
        
        console.log('Adding to cart with subscription type:', selectedType);
        
        // Zabezpečí že sa pošle správna hodnota
        if (!$(this).find('input[name="subscription_type"][type="hidden"]').length) {
            $(this).append('<input type="hidden" name="subscription_type" value="' + selectedType + '">');
        } else {
            $(this).find('input[name="subscription_type"][type="hidden"]').val(selectedType);
        }
    });
        
    
    // ===== INICIALIZÁCIA =====
    // 1. Získaj hodnotu priamo z dátového atribútu – to je jediný správny zdroj
    var defaultType = $('.oppio-purchase-options').data('default-subscription') || 'biweekly';
    var initialType = defaultType;

    console.log('INIT: Using default subscription type from shortcode data:', initialType);

    // 2. Force zaskrtnutie rádia podľa default
    $('input[name="subscription_type"]').prop('checked', false);
    $('input[name="subscription_type"][value="' + initialType + '"]').prop('checked', true);

    // 3. Vizualizácia podľa typu
    updateSectionActiveStates(initialType);

    if (initialType === 'monthly' || initialType === 'biweekly') {
        updateSubscriptionPricing(initialType);
    }

    updateSelectedSubscribeText();

    // 4. Voliteľne – update shipping info
    if (typeof window.updateShippingProgress === 'function') {
        setTimeout(function() {
            window.updateShippingProgress();
        }, 500);

        if (initialType !== 'none') {
            console.log('INIT: Updating shipping progress for subscription:', initialType);
            setTimeout(function() {
                window.updateShippingProgress();
            }, 1000);
        }
    }
    // ===== INICIALIZÁCIA =====
    
    console.log('OPPIO Subscription JS initialized with sections support');
});

// ===== END OF OPPIO Subscription JS =====

/* ========== FAKE DROPDOWN ============= */
jQuery(document).ready(function($) {
    console.log('Fake dropdown script loaded');

    const $trigger = $('.simulate__selected-subscribe');
    const $dropdown = $('.simulate__select');

    // Otvorí/zavrie dropdown na klik
    $trigger.on('click', function(e) {

        e.preventDefault();              // zabráni default správanie
        e.stopPropagation();            // zastaví bubbling
        e.stopImmediatePropagation();   // zastaví aj ostatné .on('click') handlery

        if ($dropdown.hasClass('open')) {
            $dropdown.removeClass('open');
            console.log('Dropdown closed');
        } 
        else {
            $dropdown.addClass('open');
            console.log('Dropdown opened');
        }
    });

    $('.simulate__select input[name="subscription_type"]').on('change', function() {
        $dropdown.removeClass('open');
        console.log('Dropdown closed');
    });

    // Klik mimo dropdown zavrie
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.simulate__select').length && !$(e.target).is($trigger)) {
            $dropdown.removeClass('open');
            console.log('Dropdown closed by outside click');
        }
    });

});

function updateSelectedSubscribeText() {
    jQuery('.simulate__select').each(function() {
        const $section = jQuery(this).closest('.delivery-frequency'); // alebo iný wrapper podľa potreby
        const $selected = jQuery(this).find('input[type="radio"]:checked');
        
        if ($selected.length) {
            const text = $selected.siblings('strong').text();
            $section.find('.simulate__selected-subscribe').text(text);
        }
    });
}

jQuery(document).on('change', 'input[name="subscription_type"]', function() {
    updateSelectedSubscribeText();
});

/* ===== OPPIO 3DS FAIL PATCH – vložené na konci subscription.js ===== */
window.OppioSubscription = (function () {
  function handleServerResponse(resp) {
    try { if (typeof resp === 'string') resp = JSON.parse(resp); } catch (e) {}
    if (!resp) return;

    // 3DS zlyhalo – PaymentIntent == requires_payment_method
    if (resp.status === 'requires_payment_method' && resp.retry_url) {
      showErrorMessage('3D Secure overenie zlyhalo. Skúste znova s novou kartou.');
      window.location.href = resp.retry_url;
      return;
    }

    // 3DS challenge
    if (resp.requires_action && resp.client_secret) {
      var stripe = window.stripe || (window.oppioStripePk ? Stripe(window.oppioStripePk) : null);
      if (!stripe) {
        showErrorMessage('Stripe nebol inicializovaný.');
        return;
      }

      showProcessingMessage('Prebieha 3D Secure overenie...');
      stripe.confirmCardPayment(resp.client_secret).then(function (result) {
        hideProcessingMessage();

        if (result.error) {
          showErrorMessage('3D Secure overenie zlyhalo. Skúste zaplatiť znova.');
          if (resp.retry_url) window.location.href = resp.retry_url;
          return;
        }

        showSuccessMessage('Platba potvrdená, presmerovávame...');
        setTimeout(function () {
          window.location.href = resp.success_url || resp.redirect || window.location.href;
        }, 1500);
      }).catch(function (error) {
        hideProcessingMessage();
        showErrorMessage('Chyba počas 3D Secure overenia: ' + error.message);
      });
      return;
    }

    // Úspech bez 3DS
    if (resp.redirect) {
      window.location.href = resp.redirect;
      return;
    }

    if (resp.success) {
      window.location.href = resp.success_url || window.location.href;
    }
  }

  return { handleServerResponse: handleServerResponse };
})();


// ✅ HELPER FUNKCIA PRE ERROR MESSAGE S RETRY TLAČIDLOM
function showError3DS(message, retryUrl = null) {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #dc2626; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">🔒</span>
                    <h2 style="margin: 0; font-size: 24px; color: #dc2626; font-weight: 600;">3D Secure Problem</h2>
                </div>
                <p style="margin: 0 0 30px 0; font-size: 18px; color: #333; line-height: 1.4;">${message}</p>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    ${retryUrl ? `
                        <button onclick="window.location.href='${retryUrl}'" style="
                            background: #dc2626; color: white; border: none; 
                            padding: 12px 24px; border-radius: 6px; cursor: pointer;
                            font-size: 16px; font-weight: 500; transition: background 0.2s;
                            min-width: 140px;
                        ">Skúsiť znova</button>
                    ` : `
                        <button onclick="window.location.reload();" style="
                            background: #dc2626; color: white; border: none; 
                            padding: 12px 24px; border-radius: 6px; cursor: pointer;
                            font-size: 16px; font-weight: 500; transition: background 0.2s;
                            min-width: 140px;
                        ">Obnoviť stránku</button>
                    `}
                    <button onclick="window.location.href='/kosik/'" style="
                        background: #6b7280; color: white; border: none; 
                        padding: 12px 24px; border-radius: 6px; cursor: pointer;
                        font-size: 16px; font-weight: 500; transition: background 0.2s;
                        min-width: 140px;
                    ">Späť do košíka</button>
                </div>
            </div>
        </div>
    `);
}

/* ============================================================
    ===== OPPIO – univerzálny odchyt AJAX odpovede s 3DS ===== 
    ============================================================ */
jQuery(document).ajaxSuccess(function (evt, xhr, settings) {
  if (!xhr || !xhr.responseText) return;
  var resp = null;
  try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }

  // Zareaguj iba vtedy, keď odpoveď vyzerá ako naša 3DS payload
  if (
    (resp && resp.requires_action && resp.client_secret) ||                 // 3DS challenge
    (resp && resp.status === 'requires_payment_method' && (resp.retry_url)) // 3DS fail → nový pokus
  ) {
    if (window.OppioSubscription && window.OppioSubscription.handleServerResponse) {
      window.OppioSubscription.handleServerResponse(resp);
    }
  }
});

// FE pre STRIPE
// --- OPPIO: Subscription checkout glue ---
// FE pre STRIPE - CHECKOUT FLOW
(function () {
  if (!window.OPPIO_IS_SUBSCRIPTION) return;

  const pk =
    (window.wc_stripe_params && window.wc_stripe_params.key) ||
    window.OPPIO_STRIPE_PK; // fallback, ak si ho niekde ukladáš
  
  if (!pk) {
    console.error('OPPIO: Missing Stripe publishable key');
    return;
  }

  const stripe = Stripe(pk);
  const elements = stripe.elements();
  
  // Ak už máš vytvorený <div id="oppio-card-element"> a element inde, použi ho.
  // Inak si mountni tu:
  if (!document.getElementById('oppio-card-element')) {
    const mount = document.createElement('div');
    mount.id = 'oppio-card-element';
    document.querySelector('form.checkout')?.prepend(mount);
  }
  
  const card = elements.create('card');
  card.mount('#oppio-card-element');

  const $ = window.jQuery;
  const $form = $('form.checkout');
  const $placeOrder = $('#place_order');
  const $err = $('#oppio-card-errors');

  const getVal = (sel) => (document.querySelector(sel)?.value || '').trim();
  
  // ✅ NAHRADENÁ showErr FUNKCIA - BEZ ALERTU
  const showErr = (m) => {
    const message = m || 'Chyba platby.';
    
    // Skús najprv error element na stránke
    if ($err.length) {
      $err.text(message);
    } else {
      // ✅ NAMIESTO ALERT POUŽIJ NAŠU MESSAGE FUNKCIU
      if (typeof showErrorMessage === 'function') {
        showErrorMessage(message);
      } else {
        // Fallback ak naše funkcie nie sú dostupné - vytvor inline error
        showCheckoutError(message);
      }
    }
  };
  
  const disable = (on) => {
    $placeOrder.prop('disabled', !!on);
    $placeOrder.toggleClass('disabled', !!on);
  };

  async function oppioHandleSubscriptionSubmit(e) {
    // Zober si kontrolu nad submitom len pri predplatnom
    e.preventDefault();
    disable(true);
    
    // ✅ ZOBRAZ PROCESSING POČAS CHECKOUT
    if (typeof showProcessingMessage === 'function') {
      showProcessingMessage('Spracovávame vašu objednávku...');
    }

    try {
      // 1) PaymentMethod
      const billing = {
        name: (getVal('#billing_first_name') + ' ' + getVal('#billing_last_name')).trim(),
        email: getVal('#billing_email'),
        address: {
          line1: getVal('#billing_address_1'),
          line2: getVal('#billing_address_2'),
          city: getVal('#billing_city'),
          postal_code: getVal('#billing_postcode'),
          country: getVal('#billing_country'),
        },
      };
      
      const pmRes = await stripe.createPaymentMethod({
        type: 'card',
        card,
        billing_details: billing,
      });
      
      if (pmRes.error) {
        throw new Error(pmRes.error.message || 'Zlyhalo vytvorenie platobnej metódy.');
      }

      // 2) Backend: vytvor subscription a vráť client_secret z invoice PI
      const fd = new FormData($form[0]);
      fd.append('action', 'oppio_create_subscription');
      fd.append('order_id', getVal('#oppio_order_id'));
      fd.append('customer_id', getVal('#oppio_customer_id'));
      fd.append('payment_method_id', pmRes.paymentMethod.id);

      const resp = await fetch(wc_checkout_params.ajax_url, { method: 'POST', body: fd });
      const json = await resp.json();

      if (!resp.ok || !json || json.success === false || json.status === 'error') {
        throw new Error(json?.message || 'Vytvorenie predplatného zlyhalo.');
      }

      const clientSecret = json.client_secret || json?.data?.client_secret;
      if (!clientSecret) {
        throw new Error('Chýba client_secret z platobného procesu.');
      }

      // ✅ UPDATE PROCESSING MESSAGE
      if (typeof showProcessingMessage === 'function') {
        showProcessingMessage('Dokončujeme platbu...');
      }

      // 3) Potvrď PRESNE tento invoice PaymentIntent (3DS sa tu vyrieši)
      const confirm = await stripe.confirmCardPayment(clientSecret);
      
      if (confirm.error) {
        throw new Error(confirm.error.message || 'Potvrdenie platby zlyhalo.');
      }

      const piStatus = confirm.paymentIntent?.status;
      
      if (piStatus === 'succeeded' || piStatus === 'processing') {
        // ✅ ZOBRAZ SUCCESS PRED REDIRECTOM
        if (typeof hideProcessingMessage === 'function') {
          hideProcessingMessage();
        }
        
        if (typeof showSuccessMessage === 'function') {
          showSuccessMessage('Objednávka úspešná! Presmerovávame...');
        }
        
        // 4) Redirect po krátkom delay
        setTimeout(() => {
          window.location.href =
            json.redirect ||
            json.success_url ||
            (window.location.origin + '/checkout/order-received/');
        }, 1500);
        return;
      }

      // iné edge stavy
      throw new Error('Platba vyžaduje dodatočné kroky.');
      
    } catch (err) {
      console.error('OPPIO subscription flow error:', err);
      
      // ✅ SKRY PROCESSING MESSAGE
      if (typeof hideProcessingMessage === 'function') {
        hideProcessingMessage();
      }
      
      showErr(err.message || String(err));
      disable(false);
    }
  }

  // Pripni sa na submit checkoutu (nechaj ostatné tvoje handleri bežať)
  $form.off('submit.oppioSub').on('submit.oppioSub', function (e) {
    // Pri non‑subscription nechaj Woo bežať ďalej
    if (!window.OPPIO_IS_SUBSCRIPTION) return;
    oppioHandleSubscriptionSubmit(e);
  });
})();

// ✅ FALLBACK CHECKOUT ERROR FUNKCIA
function showCheckoutError(message) {
  // Odstráň predchádzajúce chyby
  jQuery('#oppio-checkout-error').remove();
  
  // Vytvor inline error message
  const errorHtml = `
    <div id="oppio-checkout-error" style="
      background: #fee2e2; border: 2px solid #dc2626; border-radius: 8px;
      padding: 15px; margin: 15px 0; color: #7f1d1d;
      font-family: -apple-system, sans-serif;
    ">
      <div style="display: flex; align-items: center;">
        <span style="margin-right: 10px; font-size: 18px;">❌</span>
        <strong>Chyba:</strong>&nbsp;${message}
      </div>
    </div>
  `;
  
  // Pridaj pred checkout form
  jQuery('form.checkout').prepend(errorHtml);
  
  // Scroll na error
  jQuery('html, body').animate({
    scrollTop: jQuery('#oppio-checkout-error').offset().top - 50
  }, 500);
  
  // Auto-remove po 10 sekundách
  setTimeout(() => {
    jQuery('#oppio-checkout-error').fadeOut();
  }, 10000);
}

// ===== AUTO-CONFIRM PAYMENT INTENT NA THANKYOU PAGE =====
jQuery(document).ready(function($) {
    console.log('=== DEBUG AUTO-CONFIRM ===');
    console.log('OPPIO_CLIENT_SECRET:', window.OPPIO_CLIENT_SECRET);
    console.log('OPPIO_STRIPE_PK:', window.OPPIO_STRIPE_PK);
    console.log('URL contains order-received:', window.location.href.includes('order-received'));
    
    // ✅ ZJEDNODUŠENÁ LOGIKA - len skontroluj SUCCESS
    const processedKey = 'oppio_payment_processed_' + window.OPPIO_ORDER_ID;
    const processed = sessionStorage.getItem(processedKey);
    
    console.log('SessionStorage status:', processed);
    
    // Preskač len ak už bolo úspešne spracované
    if (processed === 'success') {
        console.log('OPPIO: Already successful, showing success button');
        showSuccessWithButton();
        return;
    }
    
    // ✅ SPUSTI VŽDY (aj pri processing, error, alebo null)
    if (window.OPPIO_CLIENT_SECRET && window.OPPIO_STRIPE_PK && window.location.href.includes('order-received')) {
        console.log('OPPIO: Starting payment confirmation...');
        
        // Zobraz processing
        showProcessingMessage('Overujeme stav vašej platby...');
        
        const stripe = Stripe(window.OPPIO_STRIPE_PK);
        
        // ✅ NAJPRV SKONTROLUJ STAV
        stripe.retrievePaymentIntent(window.OPPIO_CLIENT_SECRET).then(function(result) {
            console.log('OPPIO: Retrieved PaymentIntent:', result.paymentIntent?.status);
            
            if (result.error) {
                throw new Error(result.error.message);
            }
            
            const pi = result.paymentIntent;
            
            if (pi.status === 'succeeded') {
                // ✅ UŽ JE ÚSPEŠNÁ - LEN ZOBRAZ SUCCESS
                console.log('OPPIO: Payment already succeeded!');
                hideProcessingMessage();
                sessionStorage.setItem(processedKey, 'success');
                showSuccessMessage('Vaše predplatné bolo úspešne aktivované!');
                
                setTimeout(() => {
                    showSuccessWithButton();
                }, 2000);
                
            } else if (pi.status === 'requires_confirmation') {
                // ✅ TREBA POTVRDIŤ
                console.log('OPPIO: Confirming payment...');
                showProcessingMessage('Dokončujeme platbu...');
                
                return stripe.confirmPayment({
                    clientSecret: window.OPPIO_CLIENT_SECRET,
                    confirmParams: {
                        return_url: window.location.href
                    }
                });
                
            } else {
                // ✅ INÝ STAV
                console.log('OPPIO: Payment status:', pi.status);
                hideProcessingMessage();
                showWarningMessage('Platba je v stave: ' + pi.status);
            }
            
        }).then(function(confirmResult) {
            if (!confirmResult) return; // už spracované vyššie
            
            console.log('OPPIO: Confirm result:', confirmResult);
            hideProcessingMessage();
            
            if (confirmResult.error) {
                sessionStorage.setItem(processedKey, 'error');
                showErrorMessage('Potvrdenie platby zlyhalo: ' + confirmResult.error.message);
            } else if (confirmResult.paymentIntent?.status === 'succeeded') {
                sessionStorage.setItem(processedKey, 'success');
                showSuccessMessage('Vaše predplatné bolo úspešne aktivované!');
                
                setTimeout(() => {
                    showSuccessWithButton();
                }, 2000);
            }
            
        }).catch(function(error) {
            console.error('OPPIO: Error:', error);
            hideProcessingMessage();
            sessionStorage.setItem(processedKey, 'error');
            showErrorMessage('Chyba pri overení platby: ' + error.message);
        });
    }
    
    console.log('=== DEBUG AUTO-CONFIRM END ===');
});

// ✅ VEĽKÉ CENTROVANÉ MESSAGE FUNKCIE S OVERLAY
function showProcessingMessage(text) {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #0073aa; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">⏳</span>
                    <h2 style="margin: 0; font-size: 24px; color: #0073aa; font-weight: 600;">Spracovávame platbu</h2>
                </div>
                <p style="margin: 0; font-size: 18px; color: #333; line-height: 1.4;">${text}</p>
                <div style="margin-top: 20px;">
                    <div style="
                        width: 40px; height: 40px; border: 4px solid #e3f2fd;
                        border-top: 4px solid #0073aa; border-radius: 50%;
                        animation: oppio-spin 1s linear infinite; margin: 0 auto;
                    "></div>
                </div>
            </div>
        </div>
    `);
    
    // Pridaj CSS animácie ak neexistujú
    if (!document.getElementById('oppio-animations')) {
        const style = document.createElement('style');
        style.id = 'oppio-animations';
        style.textContent = `
            @keyframes oppio-fade-in {
                from { opacity: 0; transform: scale(0.9); }
                to { opacity: 1; transform: scale(1); }
            }
            @keyframes oppio-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }
}
function showSuccessMessage(text) {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #059669; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">✅</span>
                    <h2 style="margin: 0; font-size: 24px; color: #059669; font-weight: 600;">Úspech!</h2>
                </div>
                <p style="margin: 0; font-size: 18px; color: #333; line-height: 1.4;">${text}</p>
            </div>
        </div>
    `);
}

// ✅ OPRAVENÉ - NEMAZAJ sessionStorage v success tlačidlách
function showSuccessWithButton() {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #059669; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">✅</span>
                    <h2 style="margin: 0; font-size: 24px; color: #059669; font-weight: 600;">Platba úspešná!</h2>
                </div>
                <p style="margin: 0 0 30px 0; font-size: 18px; color: #333; line-height: 1.4;">
                    Vaše predplatné bolo úspešne aktivované.<br>
                    Môžete pokračovať v prehliadaní.
                </p>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    
                    <button onclick="jQuery('#oppio-payment-overlay').remove();" style="
                        background: #059669; color: white; border: none; 
                        padding: 12px 24px; border-radius: 6px; cursor: pointer;
                        font-size: 16px; font-weight: 500; transition: background 0.2s;
                        min-width: 140px;
                    " onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                        Zavrieť
                    </button>
                </div>
            </div>
        </div>
    `);
}
function showErrorMessage(text) {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #dc2626; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">❌</span>
                    <h2 style="margin: 0; font-size: 24px; color: #dc2626; font-weight: 600;">Chyba platby</h2>
                </div>
                <p style="margin: 0 0 30px 0; font-size: 18px; color: #333; line-height: 1.4;">${text}</p>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <button onclick="
                        sessionStorage.removeItem('oppio_payment_processed_' + window.OPPIO_ORDER_ID); 
                        window.location.reload();
                    " style="
                        background: #dc2626; color: white; border: none; 
                        padding: 12px 24px; border-radius: 6px; cursor: pointer;
                        font-size: 16px; font-weight: 500; transition: background 0.2s;
                        min-width: 140px;
                    " onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                        Skúsiť znova
                    </button>
                    <button onclick="window.location.href='/kosik/'" style="
                        background: #6b7280; color: white; border: none; 
                        padding: 12px 24px; border-radius: 6px; cursor: pointer;
                        font-size: 16px; font-weight: 500; transition: background 0.2s;
                        min-width: 140px;
                    " onmouseover="this.style.background='#4b5563'" onmouseout="this.style.background='#6b7280'">
                        Späť do košíka
                    </button>
                    <button onclick="jQuery('#oppio-payment-overlay').remove();" style="
                        background: #94a3b8; color: white; border: none; 
                        padding: 12px 24px; border-radius: 6px; cursor: pointer;
                        font-size: 16px; font-weight: 500; transition: background 0.2s;
                        min-width: 140px;
                    " onmouseover="this.style.background='#64748b'" onmouseout="this.style.background='#94a3b8'">
                        Zavrieť
                    </button>
                </div>
            </div>
        </div>
    `);
}
function showWarningMessage(text) {
    hideAllMessages();
    jQuery('body').prepend(`
        <div id="oppio-payment-overlay" style="
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0, 0, 0, 0.7); z-index: 99998;
            display: flex; align-items: center; justify-content: center;
        ">
            <div id="oppio-payment-status" style="
                background: #ffffff; 
                border: 3px solid #d97706; 
                border-radius: 12px; 
                padding: 40px 50px; 
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 500px; width: 90%;
                text-align: center;
                animation: oppio-fade-in 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <span style="margin-right: 15px; font-size: 32px;">⚠️</span>
                    <h2 style="margin: 0; font-size: 24px; color: #d97706; font-weight: 600;">Upozornenie</h2>
                </div>
                <p style="margin: 0 0 30px 0; font-size: 18px; color: #333; line-height: 1.4;">${text}</p>
                <button onclick="jQuery('#oppio-payment-overlay').remove();" style="
                    background: #d97706; color: white; border: none; 
                    padding: 12px 24px; border-radius: 6px; cursor: pointer;
                    font-size: 16px; font-weight: 500; transition: background 0.2s;
                    min-width: 140px;
                " onmouseover="this.style.background='#b45309'" onmouseout="this.style.background='#d97706'">
                    OK
                </button>
            </div>
        </div>
    `);
}
function hideAllMessages() {
    jQuery('#oppio-payment-overlay').remove();
    jQuery('#oppio-payment-status').remove();
}

function hideProcessingMessage() {
    jQuery('#oppio-payment-overlay').remove();
    jQuery('#oppio-payment-status').remove();
}


//===============================
//
//===============================
(function($){
  const stripe = window.OPPIO_STRIPE && window.OPPIO_STRIPE.pk ? Stripe(OPPIO_STRIPE.pk) : null;

  async function handleOppioResponse(resp) {
    // 3DS pre SetupIntent (mandát na budúce platby po triali)
    if (resp && resp.mode === 'confirm_setup' && resp.client_secret) {
      if (!stripe) { alert('Stripe nie je inicializovaný'); return; }
      const { error } = await stripe.confirmCardSetup(resp.client_secret);
      if (error) { alert(error.message || 'Overenie karty zlyhalo.'); return; }
      window.location.href = resp.success_url;
      return;
    }

    // 3DS pre PaymentIntent (ak by si účtoval hneď)
    if (resp && resp.requires_action === true && resp.client_secret) {
      if (!stripe) { alert('Stripe nie je inicializovaný'); return; }
      const { error } = await stripe.confirmCardPayment(resp.client_secret);
      if (error) { alert(error.message || 'Platba zlyhala pri overení.'); return; }
      window.location.href = resp.success_url;
      return;
    }

    // Úspech bez potreby akcie
    if (resp && resp.success) {
      window.location.href = resp.redirect || resp.success_url || window.location.href;
    }
  }

  window.oppioHandleServerResponse = handleOppioResponse;
})(jQuery);
