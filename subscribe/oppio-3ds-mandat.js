console.log('[OPPIO 3DS] mandat.js LOADED', { hasPK: !!(window.OPPIO_STRIPE && window.OPPIO_STRIPE.pk) });
window.OPPIO_3DS_LOADED = true;
window._oppioSetupHandled = false; // anti-double-fire

/* ===== Odchyt AJAX odpovedí – hľadáme SetupIntent ===== */
jQuery(document).ajaxSuccess(function (_evt, xhr) {
  let resp; try { resp = JSON.parse(xhr.responseText); } catch { return; }
  const p = (resp && typeof resp.data === 'object') ? resp.data : resp;

  const clientSecret =
    (p && p.mode === 'confirm_setup' && p.client_secret) ? p.client_secret :
    (p && p.pending_setup_intent && p.pending_setup_intent.client_secret) ? p.pending_setup_intent.client_secret :
    (p && p.setup_intent_client_secret) ? p.setup_intent_client_secret :
    null;

  if (!clientSecret || window._oppioSetupHandled) return;

  // vezmi pm_id z backendu (ak ho posielaš)
  const pmId = p.pm_id || p.payment_method_id || p.payment_method || p.pm || null;

  window._oppioSetupHandled = true;
  if (typeof window.oppioHandleServerResponse === 'function') {
    window.oppioHandleServerResponse({
      mode: 'confirm_setup',
      client_secret: clientSecret,
      pm_id: pmId,
      success_url: p.success_url || resp.redirect || window.location.href
    });
  }
});

/* (voliteľné) – log AJAX chýb */
jQuery(document).ajaxError(function (_evt, xhr, settings, error) {
  console.groupCollapsed('[OPPIO 3DS][ajaxError]', settings?.url || '');
  console.log('status:', xhr?.status, 'error:', error);
  console.log('responseText (first 300):', (xhr?.responseText || '').slice(0, 300));
  console.groupEnd();
});

/* ===== Handler – potvrdíme IBA mandát (SetupIntent) ===== */
(function ($) {
  function getStripe() {
    return window.OPPIO_STRIPE?.pk ? Stripe(window.OPPIO_STRIPE.pk) : null;
  }

  window.oppioHandleServerResponse = async function(resp) {
    try { if (typeof resp === 'string') resp = JSON.parse(resp); } catch {}
    const stripe = getStripe();
    if (!stripe || resp?.mode !== 'confirm_setup' || !resp?.client_secret) return;

    // ak máme ID karty z backendu, pošli ho – prebijeme tým prípadný 'link'
    const opts = resp.pm_id ? { payment_method: resp.pm_id } : undefined;

    const { error } = await stripe.confirmCardSetup(resp.client_secret, opts);
    if (error) {
      window._oppioSetupHandled = false; // dovoľ opakovať na retry
      alert(error.message || 'Overenie karty zlyhalo.');
      return;
    }
    if (resp.success_url) window.location.href = resp.success_url;
  };
})(jQuery);
