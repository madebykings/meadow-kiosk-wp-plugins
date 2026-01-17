/* global MEADOW_ADMIN_TOOLS, jQuery */
jQuery(function($){
  const CFG = window.MEADOW_ADMIN_TOOLS || {};
  const $status = $('.meadow-kiosk-controls .meadow-ctrl-status');

  function setStatus(msg, isErr){
    if(!$status.length) return;
    $status.text(msg).css('color', isErr ? '#b32d2e' : '#2e7d32');
  }

  function postJSON(url, data){
  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (CFG.nonce || '')
    },
    body: JSON.stringify(data || {})
  }).then(r =>
    r.json().catch(()=> ({})).then(j => ({ ok:r.ok, status:r.status, json:j }))
  );
}

  // Reset screen buttons
  $(document).on('click', '[data-meadow-reset]', function(e){
    e.preventDefault();
    const mode = String(this.getAttribute('data-meadow-reset') || 'ads');

    setStatus('Resetting screen…');
    postJSON(CFG.rest.screenReset, { kiosk_post_id: CFG.postId, mode })
      .then(res => {
        if(res.ok && res.json && res.json.ok){
          setStatus('Screen reset → ' + mode);
        } else {
          const msg = (res.json && (res.json.message || res.json.code)) ? (res.json.message || res.json.code) : ('HTTP ' + res.status);
          setStatus('Reset failed: ' + msg, true);
        }
      })
      .catch(err => setStatus('Reset failed: ' + (err && err.message ? err.message : err), true));
  });

  // Pi control actions
  $(document).on('click', '[data-meadow-pi-action]', function(e){
    e.preventDefault();
    const action = String(this.getAttribute('data-meadow-pi-action') || '');
    if(!action) return;

    if(action === 'shutdown' && !confirm('Shutdown Pi now?')) return;
    if(action === 'reboot' && !confirm('Reboot Pi now?')) return;

    setStatus('Sending Pi action: ' + action + '…');
    postJSON(CFG.rest.piControl, {
      kiosk_post_id: CFG.postId,
      action: action,
      payload: {}
    }).then(res => {
      if(res.ok && res.json && res.json.ok){
        const ok = !!res.json.action_ok;
        setStatus((ok ? 'OK: ' : 'FAILED: ') + action + (res.json.action_err ? (' — ' + res.json.action_err) : ''), !ok);
      } else {
        const msg = (res.json && (res.json.message || res.json.code)) ? (res.json.message || res.json.code) : ('HTTP ' + res.status);
        setStatus('Pi action failed: ' + msg, true);
      }
    }).catch(err => setStatus('Pi action failed: ' + (err && err.message ? err.message : err), true));
  });

  // Motor test buttons
  $(document).on('click', '[data-meadow-motor]', function(e){
    e.preventDefault();
    const motor = parseInt(this.getAttribute('data-meadow-motor') || '0', 10);
    if(!motor) return;
    if(!confirm('Spin motor ' + motor + ' now?')) return;

    setStatus('Calling Pi vend-test for motor ' + motor + '…');
    postJSON(CFG.rest.vendTest, {
      kiosk_post_id: CFG.postId,
      motor: motor
    }).then(res => {
      if(res.ok && res.json && res.json.ok){
        setStatus('Motor ' + motor + ' command sent.');
      } else {
        const msg = (res.json && (res.json.message || res.json.code)) ? (res.json.message || res.json.code) : ('HTTP ' + res.status);
        setStatus('Vend-test failed: ' + msg, true);
      }
    }).catch(err => setStatus('Vend-test failed: ' + (err && err.message ? err.message : err), true));
  });
});
