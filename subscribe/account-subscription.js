/**
 * OPPIO Account Subscription JS - S DIZAJNOVÝM POPUP A PRELOŽITEĽNÝMI TEXTAMI
 * Nahradí pôvodný account-subscription.js súbor
 */

console.log('OPPIO Account Subscription JS loaded');

jQuery(document).ready(function($) {
    console.log('OPPIO Account Subscription JS ready');
    
    // Handler pre zrušenie predplatného
    $(document).on('click', '.cancel-subscription', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var subscriptionId = button.data('subscription-id');
        var orderId = button.data('order-id');
        
        // Validácia
        if (!subscriptionId || !orderId) {
            showNotification(oppio_account_ajax.texts.missing_data_error, 'error');
            return;
        }
        
        // Zobraz cancel popup
        showCancelPopup(subscriptionId, orderId, button);
    });
    
    // Handler pre obnovenie predplatného
    $(document).on('click', '.renew-subscription', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var orderId = button.data('order-id');
        var subscriptionType = button.data('subscription-type');
        
        // Validácia
        if (!orderId || !subscriptionType) {
            showNotification(oppio_account_ajax.texts.missing_data_error, 'error');
            return;
        }
        
        // Zobraz renew popup
        showRenewPopup(orderId, subscriptionType, button);
    });
    
    /**
     * CANCEL POPUP - Dizajnový popup pre zrušenie
     */
    function showCancelPopup(subscriptionId, orderId, button) {
        // Odstráň existujúci popup ak existuje
        $('.oppio-account-popup-overlay').remove();
        
        var popupHTML = `
            <div class="oppio-account-popup-overlay" style="
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
                <div class="oppio-account-popup" style="
                    background: var(--project-light, #FDFBEF);
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
                    <button class="oppio-popup-close" style="
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
                    ">×</button>
                    
                    <!-- Header -->
                    <div style="margin-bottom: 25px;">
                        <div style="
                            width: 60px;
                            height: 60px;
                            background: #dc3545;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0 auto 20px;
                            font-size: 24px;
                        ">⚠️</div>
                        <h2 style="
                            margin: 0;
                            color: var(--txt, rgba(0,0,0,.8));
                            font-size: 24px;
                            font-weight: 600;
                            line-height: 1.3;
                        ">${oppio_account_ajax.texts.cancel_title}</h2>
                    </div>
                    
                    <!-- Main content -->
                    <div style="margin-bottom: 30px; color: var(--project-gray-light, #4e4e4e); font-size: 16px; line-height: 1.6;">
                        <p style="margin: 0 0 20px 0;">
                            ${oppio_account_ajax.texts.cancel_description}
                        </p>
                        
                        <div style="
                            background: var(--project-msg-yellow, #fff3cd);
                            color: var(--project-msg-txt, #333);
                            padding: 15px;
                            border-radius: 8px;
                            margin: 20px 0;
                            border-left: 4px solid #ffc107;
                            font-size: 14px;
                        ">
                            ${oppio_account_ajax.texts.cancel_reminder}
                        </div>
                    </div>
                    
                    <!-- Buttons -->
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <button class="oppio-confirm-cancel" style="
                            background: #dc3545;
                            color: white;
                            border: none;
                            padding: 15px 30px;
                            border-radius: 8px;
                            font-size: 16px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        ">${oppio_account_ajax.texts.confirm_cancel}</button>
                        
                        <button class="oppio-popup-cancel" style="
                            background: var(--project-light-2, #E8E2DD);
                            color: var(--txt, rgba(0,0,0,.8));
                            border: none;
                            padding: 15px 25px;
                            border-radius: 8px;
                            font-size: 16px;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        ">${oppio_account_ajax.texts.popup_cancel}</button>
                    </div>
                    
                    <!-- Loading message -->
                    <div class="oppio-popup-message" style="margin-top: 20px; display: none;"></div>
                </div>
            </div>
        `;
        
        // Pridaj do DOM
        $('body').append(popupHTML);
        
        // Zobraz s animáciou
        setTimeout(function() {
            $('.oppio-account-popup-overlay').css({
                'opacity': '1',
                'visibility': 'visible'
            });
            $('.oppio-account-popup').css('transform', 'translateY(0)');
        }, 50);
        
        // Event handlers pre popup
        setupCancelPopupHandlers(subscriptionId, orderId, button);
    }
    
    /**
     * RENEW POPUP - Dizajnový popup pre obnovenie
     */
    function showRenewPopup(orderId, subscriptionType, button) {
        // Odstráň existujúci popup ak existuje
        $('.oppio-account-popup-overlay').remove();
        
        var popupHTML = `
            <div class="oppio-account-popup-overlay" style="
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
                <div class="oppio-account-popup" style="
                    background: var(--project-light, #FDFBEF);
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
                    <button class="oppio-popup-close" style="
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
                    ">×</button>
                    
                    <!-- Header -->
                    <div style="margin-bottom: 25px;">
                        <div style="
                            width: 60px;
                            height: 60px;
                            background: var(--project-green-dark, #194A37);
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0 auto 20px;
                            font-size: 24px;
                        ">🔄</div>
                        <h2 style="
                            margin: 0;
                            color: var(--txt, rgba(0,0,0,.8));
                            font-size: 24px;
                            font-weight: 600;
                            line-height: 1.3;
                        ">${oppio_account_ajax.texts.renew_title}</h2>
                    </div>
                    
                    <!-- Main content -->
                    <div style="margin-bottom: 30px; color: var(--project-gray-light, #4e4e4e); font-size: 16px; line-height: 1.6;">
                        <p style="margin: 0 0 20px 0;">
                            ${oppio_account_ajax.texts.renew_description}
                        </p>
                        
                        <div style="
                            background: var(--project-light-2, #E8E2DD);
                            padding: 15px;
                            border-radius: 8px;
                            margin: 20px 0;
                            border-left: 4px solid var(--project-green-dark, #194A37);
                            font-size: 14px;
                        ">
                            ${oppio_account_ajax.texts.renew_info}
                        </div>
                    </div>
                    
                    <!-- Buttons -->
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <button class="oppio-confirm-renew" style="
                            background: var(--project-green-dark, #194A37);
                            color: var(--project-light, #FDFBEF);
                            border: none;
                            padding: 15px 30px;
                            border-radius: 8px;
                            font-size: 16px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        ">${oppio_account_ajax.texts.confirm_renew}</button>
                        
                        <button class="oppio-popup-cancel" style="
                            background: var(--project-light-2, #E8E2DD);
                            color: var(--txt, rgba(0,0,0,.8));
                            border: none;
                            padding: 15px 25px;
                            border-radius: 8px;
                            font-size: 16px;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        ">${oppio_account_ajax.texts.popup_cancel}</button>
                    </div>
                    
                    <!-- Loading message -->
                    <div class="oppio-popup-message" style="margin-top: 20px; display: none;"></div>
                </div>
            </div>
        `;
        
        // Pridaj do DOM
        $('body').append(popupHTML);
        
        // Zobraz s animáciou
        setTimeout(function() {
            $('.oppio-account-popup-overlay').css({
                'opacity': '1',
                'visibility': 'visible'
            });
            $('.oppio-account-popup').css('transform', 'translateY(0)');
        }, 50);
        
        // Event handlers pre popup
        setupRenewPopupHandlers(orderId, subscriptionType, button);
    }
    
    /**
     * Setup event handlers pre cancel popup
     */
    function setupCancelPopupHandlers(subscriptionId, orderId, originalButton) {
        // Zatvorenie popup - X button
        $('.oppio-popup-close').on('click', function() {
            closePopup();
        });
        
        // Zatvorenie popup - Cancel button
        $('.oppio-popup-cancel').on('click', function() {
            closePopup();
        });
        
        // Zatvorenie popup - klik mimo
        $('.oppio-account-popup-overlay').on('click', function(e) {
            if (e.target === this) {
                closePopup();
            }
        });
        
        // ESC key
        $(document).on('keydown.oppioPopup', function(e) {
            if (e.keyCode === 27) {
                closePopup();
            }
        });
        
        // Potvrdenie zrušenia
        $('.oppio-confirm-cancel').on('click', function() {
            var button = $(this);
            var messageDiv = $('.oppio-popup-message');
            
            // Disable tlačidlá
            $('.oppio-confirm-cancel, .oppio-popup-cancel').prop('disabled', true);
            button.html('🔄 ' + oppio_account_ajax.texts.cancelling);
            messageDiv.show();
            
            // AJAX request
            $.post(oppio_account_ajax.ajax_url, {
                action: 'cancel_oppio_subscription',
                subscription_id: subscriptionId,
                order_id: orderId,
                nonce: oppio_account_ajax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    // Úspešné zrušenie
                    showNotification(response.data.message, 'success');
                    
                    // Aktualizuj UI
                    var row = originalButton.closest('tr');
                    var statusCell = row.find('.subscription-status');
                    
                    statusCell.removeClass('status-active')
                             .addClass('status-cancelled')
                             .text(oppio_account_ajax.texts.status_cancelled);
                    
                    originalButton.replaceWith('<span class="no-actions">' + oppio_account_ajax.texts.status_cancelled + '</span>');
                    
                    // Zavri popup
                    closePopup();
                    
                    // Refresh stránky po 2 sekundách
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    
                } else {
                    // Chyba
                    showNotification(response.data.message, 'error');
                    $('.oppio-confirm-cancel, .oppio-popup-cancel').prop('disabled', false);
                    button.html(oppio_account_ajax.texts.confirm_cancel);
                }
            })
            .fail(function(xhr, status, error) {
                // AJAX chyba
                console.error('AJAX Error:', status, error);
                showNotification(oppio_account_ajax.texts.server_error, 'error');
                $('.oppio-confirm-cancel, .oppio-popup-cancel').prop('disabled', false);
                button.html(oppio_account_ajax.texts.confirm_cancel);
            });
        });
    }
    
    /**
     * Setup event handlers pre renew popup
     */
    function setupRenewPopupHandlers(orderId, subscriptionType, originalButton) {
        // Zatvorenie popup - X button
        $('.oppio-popup-close').on('click', function() {
            closePopup();
        });
        
        // Zatvorenie popup - Cancel button
        $('.oppio-popup-cancel').on('click', function() {
            closePopup();
        });
        
        // Zatvorenie popup - klik mimo
        $('.oppio-account-popup-overlay').on('click', function(e) {
            if (e.target === this) {
                closePopup();
            }
        });
        
        // ESC key
        $(document).on('keydown.oppioPopup', function(e) {
            if (e.keyCode === 27) {
                closePopup();
            }
        });
        
        // Potvrdenie obnovenia
        $('.oppio-confirm-renew').on('click', function() {
            var button = $(this);
            var messageDiv = $('.oppio-popup-message');
            
            // Disable tlačidlá
            $('.oppio-confirm-renew, .oppio-popup-cancel').prop('disabled', true);
            button.html('🔄 ' + oppio_account_ajax.texts.renewing);
            messageDiv.show();
            
            // AJAX request
            $.post(oppio_account_ajax.ajax_url, {
                action: 'renew_oppio_subscription',
                order_id: orderId,
                subscription_type: subscriptionType,
                nonce: oppio_account_ajax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    // Úspešné obnovenie
                    showNotification(response.data.message, 'success');
                    
                    // Zavri popup
                    closePopup();
                    
                    // Presmerovanie na pokladňu po krátkom čakaní
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 1500);
                    
                } else {
                    // Chyba
                    showNotification(response.data.message, 'error');
                    $('.oppio-confirm-renew, .oppio-popup-cancel').prop('disabled', false);
                    button.html(oppio_account_ajax.texts.confirm_renew);
                }
            })
            .fail(function(xhr, status, error) {
                // AJAX chyba
                console.error('AJAX Error:', status, error);
                showNotification(oppio_account_ajax.texts.server_error, 'error');
                $('.oppio-confirm-renew, .oppio-popup-cancel').prop('disabled', false);
                button.html(oppio_account_ajax.texts.confirm_renew);
            });
        });
    }
    
    /**
     * Zatvorí popup
     */
    function closePopup() {
        $('.oppio-account-popup-overlay').css({
            'opacity': '0',
            'visibility': 'hidden'
        });
        $('.oppio-account-popup').css('transform', 'translateY(-30px)');
        
        // Odstráň z DOM po animácii
        setTimeout(function() {
            $('.oppio-account-popup-overlay').remove();
            $(document).off('keydown.oppioPopup');
        }, 300);
    }
    
    /**
     * Zobrazí notifikáciu
     */
    function showNotification(message, type) {
        // Odstráň existujúce notifikácie
        $('.oppio-notification').remove();
        
        var bgColor = type === 'error' ? '#dc3545' : '#28a745';
        var icon = type === 'error' ? '❌' : '✅';
        
        var notification = $('<div class="oppio-notification">')
            .css({
                'position': 'fixed',
                'top': '20px',
                'right': '20px',
                'background': bgColor,
                'color': 'white',
                'padding': '15px 20px',
                'border-radius': '6px',
                'font-size': '16px',
                'z-index': '9999',
                'box-shadow': '0 4px 12px rgba(0,0,0,0.3)',
                'max-width': '400px',
                'word-wrap': 'break-word'
            })
            .html(icon + ' ' + message);
        
        // Pridaj do DOM
        $('body').append(notification);
        
        // Animácia vjazdenia
        notification.hide().slideDown(300);
        
        // Automatické skrytie po 5 sekundách
        setTimeout(function() {
            notification.slideUp(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Kliknutie na zatvorenie
        notification.on('click', function() {
            $(this).slideUp(300, function() {
                $(this).remove();
            });
        });
    }
    
    // Hover effects pre close button
    $(document).on('mouseenter', '.oppio-popup-close', function() {
        $(this).css({'background': '#f5f5f5', 'color': '#333'});
    }).on('mouseleave', '.oppio-popup-close', function() {
        $(this).css({'background': 'none', 'color': '#999'});
    });
});

/*
 * Dokončiť 3D Secure (SCA)
 * - ak backend vráti sca_url → otvoríme ho
 * - ak vráti pi_client_secret → Stripe.js (confirmCardPayment)
 */
document.addEventListener('click', async function (e) {
  const btn = e.target.closest('.fetch-sca');
  if (!btn) return;

  e.preventDefault();
  btn.disabled = true;

  try {
    const form = new FormData();
    form.append('action', 'oppio_get_sca_url');
    form.append('order_id', btn.getAttribute('data-order-id') || '0');
    form.append('subscription_id', btn.getAttribute('data-subscription-id') || '');

    // nonce posielame zo servera v oppio_account_ajax.sca_nonce
    if (window.oppio_account_ajax && oppio_account_ajax.sca_nonce) {
      form.append('_wpnonce', oppio_account_ajax.sca_nonce);
    }

    const ajaxUrl =
      (window.oppio_account_ajax && oppio_account_ajax.ajax_url) ||
      window.ajaxurl ||
      '/wp-admin/admin-ajax.php';

    const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
    const raw = await res.text();

    let payload;
    try { payload = JSON.parse(raw); } catch (e) {
      alert('AJAX odpoveď nie je JSON:\n\n' + raw.slice(0, 500));
      return;
    }

    // očakávaný tvar: { success:true, data:{ sca_url?, pi_client_secret?, pi_id?, message? } }
    const d = (payload && payload.data) ? payload.data : {};

    // 1) Priamy link (alebo Payment page) → otvoríme
    if (payload.success && d.sca_url) {
      window.open(d.sca_url, '_blank', 'noopener');
      return;
    }

    // 2) Stripe.js – máme client_secret? použijeme confirmCardPayment
    if (payload.success && d.pi_client_secret) {
      if (!window.Stripe || !oppio_account_ajax || !oppio_account_ajax.stripe_pk) {
        alert('Stripe.js nie je načítaný alebo chýba publishable key.');
        return;
      }

      const stripe = window.Stripe(oppio_account_ajax.stripe_pk);

      // Toto zobrazí 3DS okno a dokončí platbu
      const {paymentIntent, error} = await stripe.confirmCardPayment(d.pi_client_secret);

      if (error) {
        alert(error.message || '3D Secure overenie zlyhalo.');
        return;
      }

      // úspech → obnov stránku nech sa statusy nahrnú
      window.location.reload();
      return;
    }

    // 3) Iné stavy – ukáž správu zo servera alebo default
    alert(d.message || 'Nenašiel som žiadny SCA link pre toto predplatné.');
  } catch (err) {
    alert('Chyba pri získaní 3D Secure linku.');
  } finally {
    btn.disabled = false;
  }
});
