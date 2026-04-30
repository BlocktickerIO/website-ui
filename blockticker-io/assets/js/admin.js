/* BlockTicker — Admin Wizard JS */
(function($){
  'use strict';

  // Safety net: if wp_localize_script failed (e.g. script loaded from cache
  // before the page's inline script ran), fall back to WP globals.
  if (typeof fxlm === 'undefined') {
    window.fxlm = {
      ajax_url: (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
      nonce: ''
    };
    console.error('[BlockTicker] fxlm not localised — using fallback. Nonce will be empty; steps may fail with 403.');
  }

  var steps = [
    'step_settings','step_plugins','step_theme','step_pages','step_menus',
    'step_widgets','step_tools','step_rss','step_cron','step_seo','step_monetization',
    'step_social','step_security','step_autopilot','step_gdpr','step_aiblog','step_ads','step_cleanup'
  ];

  // ── Debug log panel ──────────────────────────────────────────────────────
  // A hidden collapsible panel that records every AJAX attempt / result so
  // the admin can see exactly what's happening without opening DevTools.
  function logDebug(text) {
    var el = document.getElementById('bt-wizard-debug-log');
    if (!el) return;
    var ts = new Date().toLocaleTimeString();
    el.textContent = '[' + ts + '] ' + text + '\n' + el.textContent;
  }

  // ── Step data collection ─────────────────────────────────────────────────
  function getApiData() {
    return {
      fx_api_key            : $('#fxlm_fx_api_key').val()  || '',
      cg_api_key            : $('#fxlm_cg_api_key').val()  || '',
      cmc_api_key           : $('#fxlm_cmc_api_key').val() || '',
      adsense_id            : $('#fxlm_adsense_id').val()  || '',
      adsense_slot          : $('#fxlm_adsense_slot').val()|| '',
      ga_id                 : $('#fxlm_ga_id').val()       || '',
      claude_key            : $('#fxlm_claude_key').val()  || '',
      site_name             : $('#fxlm_site_name').val()   || '',
      og_image              : $('#fxlm_og_image').val()    || '',
      ai_review_mode        : $('#fxlm_ai_review_mode').val() || '',
      bt_google_client_id     : $('#bt_google_client_id').val()     || '',
      bt_google_client_secret : $('#bt_google_client_secret').val() || '',
      bt_github_client_id     : $('#bt_github_client_id').val()     || '',
      bt_github_client_secret : $('#bt_github_client_secret').val() || '',
    };
  }

  // ── UI state helpers ──────────────────────────────────────────────────────
  function setStepState(step, state, msg) {
    var $row = $('#step-row-' + step);
    var $msg = $('#msg-' + step);
    var $btn = $row.find('.fxlm-btn-run');
    $row.removeClass('done error running pending');

    if (state === 'running') {
      $row.addClass('running');
      $msg.html('<span class="fxlm-spinner"></span> Running…');
      $btn.prop('disabled', true).text('Running…');
    } else if (state === 'done') {
      $row.addClass('done');
      $msg.text(msg || 'Completed');
      $btn.prop('disabled', false).text('↺ Re-run');
    } else if (state === 'error') {
      $row.addClass('error');
      $msg.text(msg || 'Error — click to retry');
      $btn.prop('disabled', false).text('↺ Retry');
    }
  }

  // ── Core AJAX runner ──────────────────────────────────────────────────────
  function runStep(step) {
    return new Promise(function(resolve) {

      // Try/catch the entire body so a JS error (e.g. fxlm undefined) turns
      // into a visible error state rather than a silent frozen "Running…".
      try {
        setStepState(step, 'running');
        logDebug('→ Starting ' + step);

        // Show "still running" hint after 30 s so the admin knows the request
        // is alive — not frozen — for slow steps (plugin installs, etc.).
        var warnTimer = setTimeout(function() {
          var $m = $('#msg-' + step);
          $m.html('<span class="fxlm-spinner"></span> Still running… (slow step). Please wait or retry.');
          logDebug('⏳ ' + step + ' still running after 30 s');
        }, 30000);

        // Hard client-side abort after 3 min — beyond this the server is
        // unresponsive and we must unblock the queue.
        var xhr = $.ajax({
          url     : fxlm.ajax_url,
          type    : 'POST',
          timeout : 180000,
          data    : $.extend(
            { action: 'fxlm_run_step', nonce: fxlm.nonce, step: step },
            getApiData()
          ),
          success : function(res) {
            clearTimeout(warnTimer);
            // Guard: res must be an object with a success key.
            // If the server returned HTML (login page, WAF block, etc.) jQuery
            // may have parsed it as a string — handle gracefully.
            if (typeof res !== 'object' || res === null) {
              var preview = String(res).substring(0, 120);
              logDebug('✗ ' + step + ' — non-JSON response: ' + preview);
              setStepState(step, 'error', 'Server returned unexpected content (not JSON). Check server error log. Preview: ' + preview.substring(0, 60));
              resolve(false);
              return;
            }
            if (res.success) {
              logDebug('✓ ' + step + ' — ' + (res.data || 'OK'));
              setStepState(step, 'done', res.data);
              resolve(true);
            } else {
              logDebug('✗ ' + step + ' — ' + (res.data || 'failed'));
              setStepState(step, 'error', res.data || 'Step failed');
              resolve(false);
            }
          },
          error   : function(xhr, status, err) {
            clearTimeout(warnTimer);
            var msg;
            if (status === 'timeout') {
              msg = 'Request timed out (3 min). Server may still be processing — refresh the page to check progress.';
            } else if (xhr.status === 403) {
              msg = 'Permission denied (403) — nonce may have expired. Refresh the page and try again.';
            } else if (xhr.status === 0) {
              msg = 'No response from server. Check your internet connection or server error log.';
            } else if (xhr.responseText && xhr.responseText.indexOf('{') === -1) {
              msg = 'Server returned non-JSON (HTTP ' + xhr.status + '). Possible PHP fatal error — check WP debug log.';
            } else {
              msg = 'HTTP ' + xhr.status + ' — ' + (err || status) + '. Retry or check WP debug log.';
            }
            logDebug('✗ ' + step + ' error: ' + msg);
            setStepState(step, 'error', msg);
            resolve(false);
          }
        });

        // Store XHR on the row element so the admin can abort a stuck step
        $('#step-row-' + step).data('xhr', xhr);

      } catch (e) {
        // Catch any synchronous JS error (e.g. fxlm undefined, DOM missing)
        // and surface it as a visible error rather than a silent freeze.
        var errMsg = 'JS error: ' + (e.message || String(e)) + '. Check browser console.';
        logDebug('✗ ' + step + ' JS exception: ' + (e.message || e));
        setStepState(step, 'error', errMsg);
        resolve(false);
      }
    });
  }

  // ── Individual step buttons ───────────────────────────────────────────────
  $(document).on('click', '.fxlm-btn-run', function(){
    var step = $(this).data('step');
    runStep(step);
  });

  // ── Save API keys (wizard compact form) ──────────────────────────────────
  $('#fxlm-save-keys').on('click', function(){
    var $btn = $(this);
    $btn.prop('disabled', true).text('Saving…');
    $.ajax({
      url  : fxlm.ajax_url,
      type : 'POST',
      data : $.extend({ action: 'fxlm_run_step', nonce: fxlm.nonce, step: 'step_settings' }, getApiData()),
      success: function(res){
        $('#fxlm-save-msg').text(res.success ? '✓ Saved!' : '✗ Error').css('color', res.success ? '#00d4aa' : '#ff4d6a');
        $btn.prop('disabled', false).text('💾 Save API Keys');
        setTimeout(function(){ $('#fxlm-save-msg').text(''); }, 3000);
      },
      error: function(){
        $('#fxlm-save-msg').text('✗ Request failed').css('color','#ff4d6a');
        $btn.prop('disabled', false).text('💾 Save API Keys');
      }
    });
  });

  // ── Run All — sequential with full error recovery ─────────────────────────
  $('#fxlm-run-all').on('click', async function(){
    var $btn = $(this);
    $btn.prop('disabled', true).html('<span class="fxlm-spinner"></span> Running all steps…');
    logDebug('▶ Run All started');

    // Show debug panel while running
    var $debug = $('#bt-wizard-debug-wrap');
    if ($debug.length) $debug.show();

    var failed = 0;
    for (var i = 0; i < steps.length; i++) {
      try {
        var ok = await runStep(steps[i]);
        if (!ok) failed++;
      } catch(e) {
        // Should never reach here (runStep catches internally) but guard anyway
        logDebug('✗ Uncaught exception on ' + steps[i] + ': ' + e.message);
        setStepState(steps[i], 'error', 'Unexpected JS error: ' + e.message);
        failed++;
      }
      // Small pause between steps to avoid server overload
      await new Promise(function(r){ setTimeout(r, 400); });
    }

    if (failed === 0) {
      $btn.prop('disabled', false).html('✅ All Steps Complete — Reload to Verify');
      logDebug('✓ All steps completed successfully');
    } else {
      $btn.prop('disabled', false).html('⚠ Done with ' + failed + ' error(s) — see steps above');
      logDebug('⚠ Run All finished with ' + failed + ' failed step(s)');
    }
    setTimeout(function(){ location.reload(); }, 3000);
  });

  // ── wordpress.org connectivity test ──────────────────────────────────────
  $(document).on('click', '#bt-test-wporg', function(){
    var $btn = $(this);
    $btn.prop('disabled', true).text('Testing…');
    logDebug('→ Testing wordpress.org connectivity…');
    $.ajax({
      url    : fxlm.ajax_url,
      type   : 'POST',
      timeout: 20000,
      data   : { action: 'bt_test_wporg_connectivity', nonce: fxlm.nonce },
      success: function(res){
        $btn.prop('disabled', false).text('🌐 Test wordpress.org');
        if (res.success && res.data) {
          var color = res.data.reachable ? '#22c55e' : '#f59e0b';
          logDebug(res.data.message);
          $('#bt-ping-result').css('color', color).text(res.data.message);
        }
      },
      error: function(xhr, status){
        $btn.prop('disabled', false).text('🌐 Test wordpress.org');
        logDebug('✗ Connectivity test request failed: ' + status);
        $('#bt-ping-result').css('color','#ef4444').text('❌ Test request failed (' + status + ')');
      }
    });
  });

  // ── AJAX test button ──────────────────────────────────────────────────────
  // A "Ping AJAX" button that fires a trivial endpoint to confirm that
  // admin-ajax.php is reachable and the nonce is valid. If this fails, the
  // problem is environmental (WAF, login expiry) not in the step handlers.
  $(document).on('click', '#bt-ping-ajax', function(){
    var $btn = $(this);
    $btn.prop('disabled', true).text('Testing…');
    logDebug('→ Pinging AJAX…');
    $.ajax({
      url    : fxlm.ajax_url,
      type   : 'POST',
      timeout: 15000,
      data   : { action: 'bt_ping_ajax', nonce: fxlm.nonce },
      success: function(res){
        $btn.prop('disabled', false).text('🏓 Ping AJAX');
        if (res.success) {
          logDebug('✓ Ping OK — nonce valid, AJAX reachable');
          $('#bt-ping-result').css('color','#22c55e').text('✅ AJAX reachable & nonce valid. Steps should work.');
        } else {
          logDebug('✗ Ping failed: ' + JSON.stringify(res));
          $('#bt-ping-result').css('color','#ef4444').text('❌ Ping returned error: ' + (res.data || 'unknown'));
        }
      },
      error: function(xhr, status){
        $btn.prop('disabled', false).text('🏓 Ping AJAX');
        var detail = status === 'timeout' ? 'timeout' : 'HTTP ' + xhr.status;
        logDebug('✗ Ping error: ' + detail);
        $('#bt-ping-result').css('color','#ef4444').text('❌ AJAX unreachable (' + detail + '). Check server / WAF / login.');
      }
    });
  });

})(jQuery);
