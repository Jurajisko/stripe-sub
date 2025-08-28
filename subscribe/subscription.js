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

