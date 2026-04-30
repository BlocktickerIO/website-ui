/* BlockTicker v6 — Frontend JS */
(function($){
  'use strict';

  var refreshInterval = (fxlm_data.refresh || 300) * 1000;

  function updateTicker(forex, crypto) {
    var $ticker = $('#fxlm-ticker-inner');
    if (!$ticker.length) return;
    var html = '';
    if (forex && forex.rates) {
      $.each(forex.rates, function(pair, data){
        var chg   = parseFloat(data.change) || 0;
        var arrow = chg >= 0 ? '▲' : '▼';
        var cls   = chg >= 0 ? 'up' : 'down';
        html += '<span class="fxlm-tick"><strong>' + pair + '</strong> ' +
                parseFloat(data.rate).toFixed(4) +
                ' <em class="' + cls + '">' + arrow + ' ' + Math.abs(chg).toFixed(2) + '%</em></span>';
      });
    }
    if (crypto && crypto.coins) {
      $.each(crypto.coins.slice(0, 8), function(i, coin){
        var chg   = parseFloat(coin.price_change_percentage_24h) || 0;
        var arrow = chg >= 0 ? '▲' : '▼';
        var cls   = chg >= 0 ? 'up' : 'down';
        html += '<span class="fxlm-tick"><strong>' + coin.symbol.toUpperCase() + '/USD</strong> $' +
                parseFloat(coin.current_price).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) +
                ' <em class="' + cls + '">' + arrow + ' ' + Math.abs(chg).toFixed(2) + '%</em></span>';
      });
    }
    // Duplicate for seamless scroll
    $ticker.html(html + html);
  }

  function fetchPrices() {
    // FIX PERF-01: prefer lightweight REST API; fall back to admin-ajax
    var restUrl = (fxlm_data.rest_url) ? fxlm_data.rest_url : null;
    if (restUrl) {
      $.getJSON(restUrl, function(data){
        if (data && data.forex) updateTicker(data.forex, data.crypto);
      }).fail(function(){
        // fallback to admin-ajax
        legacyFetch();
      });
    } else {
      legacyFetch();
    }
  }

  function legacyFetch() {
    $.ajax({
      url  : fxlm_data.ajax_url,
      type : 'POST',
      data : { action: 'fxlm_get_prices', nonce: fxlm_data.nonce },
      success: function(res){
        if (!res.success) return;
        updateTicker(res.data.forex, res.data.crypto);
      }
    });
  }

  // ── CRYPTO CONVERTER ──
  function initConverter() {
    var $amount = $('#fxlm-conv-amount');
    var $from   = $('#fxlm-conv-from');
    var $to     = $('#fxlm-conv-to');
    var $result = $('#fxlm-conv-result .fxlm-conv-result-value');
    var $swap   = $('#fxlm-conv-swap');

    if (!$amount.length) return;

    function calculate() {
      var amount    = parseFloat($amount.val()) || 0;
      var fromPrice = parseFloat($from.find(':selected').data('price')) || 1;
      var toPrice   = parseFloat($to.find(':selected').data('price')) || 1;

      if (toPrice === 0) { $result.text('N/A'); return; }

      var usdValue = amount * fromPrice;
      var converted = usdValue / toPrice;

      var fromLabel = $from.find(':selected').text().split('(')[0].trim();
      var toLabel   = $to.find(':selected').text().split('(')[0].trim();

      $result.html(amount.toLocaleString('en-US', {maximumFractionDigits:6}) + ' ' + fromLabel +
                   ' = <strong>' + converted.toLocaleString('en-US', {maximumFractionDigits:8}) + ' ' + toLabel + '</strong>');
    }

    $amount.on('input', calculate);
    $from.on('change', calculate);
    $to.on('change', calculate);
    $swap.on('click', function() {
      var fromVal = $from.val();
      $from.val($to.val());
      $to.val(fromVal);
      calculate();
    });

    // Set default: BTC -> USD
    if ($from.find('option[value="bitcoin"]').length) {
      $from.val('bitcoin');
    }
    calculate();
  }

  // ── NEWSLETTER ──
  function initNewsletter() {
    $(document).on('click', '#fxlm-newsletter-btn', function(e) {
      e.preventDefault();
      var $btn   = $(this);
      var $email = $('#fxlm-newsletter-email');
      var $msg   = $('#fxlm-newsletter-msg');
      var email  = $email.val().trim();

      if (!email) { $msg.text('Please enter your email.').css('color','#ff4d6a'); return; }

      $btn.prop('disabled', true).text('Subscribing...');

      $.ajax({
        url  : fxlm_data.ajax_url,
        type : 'POST',
        data : { action: 'fxlm_subscribe', nonce: fxlm_data.nonce, email: email },
        success: function(res) {
          if (res.success) {
            $msg.text(res.data).css('color', '#00d4aa');
            $email.val('');
          } else {
            $msg.text(res.data || 'Error, please try again.').css('color', '#ff4d6a');
          }
          $btn.prop('disabled', false).text('Subscribe Free →');
        },
        error: function() {
          $msg.text('Network error. Please try again.').css('color', '#ff4d6a');
          $btn.prop('disabled', false).text('Subscribe Free →');
        }
      });
    });
  }

  // ── NEWS TABS ──
  function initNewsTabs() {
    $(document).on('click', '.fxlm-tab', function() {
      var $this = $(this);
      var cat   = $this.data('cat');

      // Update active state
      $('.fxlm-tab').removeClass('active');
      $this.addClass('active');

      // Filter news items
      var $items = $('.fxlm-news-item');
      if (!cat) {
        $items.show();
      } else {
        $items.each(function() {
          var $item  = $(this);
          var source = $item.find('.fxlm-news-cat').text().trim();
          if (source.toLowerCase() === cat.toLowerCase()) {
            $item.show();
          } else {
            $item.hide();
          }
        });
      }
    });
  }

  // ── PRICE BLINK EFFECT ──
  // Stores previous prices to detect changes and animate
  var prevPrices = {};

  function blinkPrice($el, isUp) {
    $el.addClass(isUp ? 'fxlm-blink-up' : 'fxlm-blink-down');
    setTimeout(function(){ $el.removeClass('fxlm-blink-up fxlm-blink-down'); }, 900);
  }

  // ── UPDATE PRICE TABLES with blink on change ──
  function updatePriceTables(crypto) {
    if (!crypto || !crypto.coins) return;
    crypto.coins.forEach(function(coin) {
      var key   = coin.symbol;
      var price = parseFloat(coin.current_price);
      var prev  = prevPrices[key];
      // Update ticker cells that match this coin
      $('.fxlm-asset-price[data-symbol="' + key + '"]').each(function(){
        var $el = $(this);
        var old = parseFloat($el.text().replace(/[^0-9.]/g,''));
        if (prev !== undefined && price !== old) {
          blinkPrice($el, price > old);
        }
        $el.text('$' + price.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}));
      });
      prevPrices[key] = price;
    });
  }
  // ── PRICE POLLING (REST only — Binance WebSocket is geo-blocked in many regions, error 451) ──
  // Uses the plugin's own WordPress REST endpoint → CoinGecko server-side → no CORS/geo issues.
  function startPolling() {
    fetchPrices();
    setInterval(fetchPrices, refreshInterval);
  }

  // Wrap updateTicker to also refresh individual asset price elements
  var origUpdateTicker = updateTicker;
  updateTicker = function(forex, crypto) {
    origUpdateTicker(forex, crypto);
    if (crypto && crypto.coins) updatePriceTables(crypto);
  };

  // ── INIT ──
  $(document).ready(function(){
    startPolling();
    initConverter();
    initNewsletter();
    initNewsTabs();
    // Pulse the "live" indicator on each refresh cycle
    setInterval(function(){
      $('.fxlm-updated').addClass('fxlm-pulse');
      setTimeout(function(){ $('.fxlm-updated').removeClass('fxlm-pulse'); }, 800);
    }, refreshInterval);
  });

})(jQuery);
