<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Tools {

    public static function register_shortcodes() {
        add_shortcode( 'fxlm_crypto_converter', array( __CLASS__, 'sc_crypto_converter' ) );
        add_shortcode( 'fxlm_fear_greed',       array( __CLASS__, 'sc_fear_greed' ) );
        add_shortcode( 'fxlm_newsletter',        array( __CLASS__, 'sc_newsletter' ) );
        add_shortcode( 'fxlm_search_bar',        array( __CLASS__, 'sc_search_bar' ) );
        add_shortcode( 'fxlm_breadcrumbs',       array( __CLASS__, 'sc_breadcrumbs' ) );
        add_shortcode( 'fxlm_trending_bar',      array( __CLASS__, 'sc_trending_bar' ) );
        add_shortcode( 'fxlm_price_cards',       array( __CLASS__, 'sc_price_cards' ) );
        add_shortcode( 'fxlm_glossary',          array( __CLASS__, 'sc_glossary' ) );

        // Newsletter AJAX
        add_action( 'wp_ajax_fxlm_subscribe',        array( __CLASS__, 'ajax_subscribe' ) );
        add_action( 'wp_ajax_nopriv_fxlm_subscribe', array( __CLASS__, 'ajax_subscribe' ) );

        // Fear & Greed period AJAX (registered for both logged-in and guests)
        add_action( 'wp_ajax_fxlm_fng_period',        array( __CLASS__, 'ajax_fng_period' ) );
        add_action( 'wp_ajax_nopriv_fxlm_fng_period', array( __CLASS__, 'ajax_fng_period' ) );

        // Cron for Fear & Greed
        add_action( 'bt_refresh_fng', array( __CLASS__, 'fetch_fear_greed' ) );

        // Search results — intercept WP default template with our rich results page
        add_action( 'template_redirect', array( __CLASS__, 'render_search_page' ), 5 );
    }

    public static function setup() {
        // Schedule Fear & Greed index fetch
        if ( ! wp_next_scheduled( 'bt_refresh_fng' ) ) {
            wp_schedule_event( time(), 'bt_hourly', 'bt_refresh_fng' );
        }

        // Initial fetch
        self::fetch_fear_greed();

        // Create newsletter subscribers table option
        if ( ! get_option( 'bt_subscribers' ) ) {
            update_option( 'bt_subscribers', array() );
        }

        return array( 'success' => true, 'message' => 'Tools configured: Crypto converter, Fear & Greed Index, newsletter, search bar, breadcrumbs, trending bar, price cards, glossary.' );
    }

    // ── FEAR & GREED INDEX ──

    public static function fetch_fear_greed() {
        $cmc_key = get_option( 'bt_cmc_api_key', '' );

        // Try CMC Fear & Greed first if API key is configured
        if ( $cmc_key ) {
            $body = BT_Utils::http_get_json(
                'https://pro-api.coinmarketcap.com/v3/fear-and-greed/historical?limit=90',
                array(
                    'timeout' => 15,
                    'headers' => array( 'X-CMC_PRO_API_KEY' => $cmc_key ),
                )
            );
            if ( ! is_wp_error( $body ) && ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
                // Normalize CMC format → same shape as Alternative.me
                $normalized = [];
                foreach ( $body['data'] as $entry ) {
                    $normalized[] = array(
                        'value'                  => (string) intval( $entry['score'] ?? $entry['value'] ?? 0 ),
                        'value_classification'   => $entry['sentiment'] ?? $entry['classification'] ?? '',
                        'timestamp'              => strtotime( $entry['timestamp'] ?? '' ) ?: time(),
                        'source'                 => 'cmc',
                    );
                }
                update_option( 'bt_fear_greed_data', array(
                    'data'    => $normalized,
                    'updated' => time(),
                    'source'  => 'cmc',
                ) );
                return;
            }
        }

        // Fallback: Alternative.me (free, no key needed)
        $body = BT_Utils::http_get_json( 'https://api.alternative.me/fng/?limit=90', array( 'timeout' => 15 ) );
        if ( is_wp_error( $body ) || empty( $body['data'] ) ) return;

        update_option( 'bt_fear_greed_data', array(
            'data'    => $body['data'],
            'updated' => time(),
            'source'  => 'alternative.me',
        ) );
    }
    public static function sc_fear_greed( $atts ) {
        $fng = BT_Widgets::get_json_option( 'fxlm_fear_greed_data' );
        if ( empty( $fng['data'] ) ) {
            return '<div class="fxlm-fng-v3"><p style="color:var(--bt-text-3);text-align:center;padding:40px 20px">⏳ Fear &amp; Greed Index loading... <a href="#" onclick="location.reload();return false;" style="color:var(--bt-accent)">Refresh</a></p></div>';
        }

        $source  = $fng['source'] ?? 'alternative.me';
        $current = $fng['data'][0];
        $value   = intval( $current['value'] );
        $history = array_reverse( array_slice( $fng['data'], 0, 7 ) );

        $zones = array(
            array( 'max'=>24,  'color'=>'#e84040', 'bg'=>'rgba(232,64,64,.12)',  'label'=>'Extreme Fear' ),
            array( 'max'=>44,  'color'=>'#f07028', 'bg'=>'rgba(240,112,40,.12)', 'label'=>'Fear' ),
            array( 'max'=>55,  'color'=>'#d4a017', 'bg'=>'rgba(212,160,23,.12)', 'label'=>'Neutral' ),
            array( 'max'=>75,  'color'=>'#6abf69', 'bg'=>'rgba(106,191,105,.12)','label'=>'Greed' ),
            array( 'max'=>100, 'color'=>'#2db87a', 'bg'=>'rgba(45,184,122,.12)', 'label'=>'Extreme Greed' ),
        );
        $zone_color = '#e84040'; $zone_label = 'Extreme Fear'; $zone_bg = 'rgba(232,64,64,.12)';
        foreach ( $zones as $z ) {
            if ( $value <= $z['max'] ) { $zone_color=$z['color']; $zone_label=$z['label']; $zone_bg=$z['bg']; break; }
        }

        $cx=200; $cy=185; $r=130;
        $angle_rad = deg2rad( 180 - ($value/100*180) );
        $nx  = $cx + ($r-18)*cos($angle_rad);
        $ny  = $cy - ($r-18)*sin($angle_rad);
        $bx1 = $cx + 9*cos($angle_rad + M_PI/2);
        $by1 = $cy - 9*sin($angle_rad + M_PI/2);
        $bx2 = $cx + 9*cos($angle_rad - M_PI/2);
        $by2 = $cy - 9*sin($angle_rad - M_PI/2);

        $source_label = ( $source === 'cmc' )
            ? '<a href="https://coinmarketcap.com/charts/fear-and-greed-index/" target="_blank" rel="noopener" style="color:var(--bt-accent);font-size:11px;text-decoration:none">CoinMarketCap ↗</a>'
            : '<a href="https://alternative.me/crypto/fear-and-greed-index/" target="_blank" rel="noopener" style="color:var(--bt-text-3);font-size:11px">Alternative.me ↗</a>';

        ob_start();
        
?>
        <div class="fxlm-fng-v3">
            <div class="fxlm-fng-v3-header">
                <span class="fxlm-fng-v3-title">📊 Fear &amp; Greed Index</span>
                <div style="display:flex;align-items:center;gap:8px">
                    <?php if ($source==='cmc'): ?>
                    <span style="background:rgba(0,153,255,.15);color:var(--bt-accent);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid rgba(0,153,255,.3)">CMC</span>
                    <?php endif; ?>
                    <span class="fxlm-fng-v3-live">● LIVE</span>
                </div>
            </div>

            <div class="fxlm-fng-tabs">
                <button class="fxlm-fng-tab active" data-period="7">1 Week</button>
                <button class="fxlm-fng-tab" data-period="30">1 Month</button>
                <button class="fxlm-fng-tab" data-period="90">3 Months</button>
            </div>

            <div class="fxlm-fng-v3-gauge-wrap">
                <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg" class="fxlm-fng-v3-svg">
                    <defs>
                        <linearGradient id="fng-needle-grad" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:var(--bt-text-2)"/>
                            <stop offset="100%" style="stop-color:#cbd5e1"/>
                        </linearGradient>
                    </defs>
                    <?php
                    $seg_colors = array('#e84040','#f07028','#d4a017','#6abf69','#2db87a');
                    $seg_labels = array('Extreme Fear','Fear','Neutral','Greed','Extreme Greed');
                    $seg_width  = 28;
                    for ( $i = 0; $i < 5; $i++ ) {
                        $a1 = deg2rad(180 - $i*36);
                        $a2 = deg2rad(180 - ($i+1)*36);
                        $gap = deg2rad(1.5);
                        $a1g = $a1 - $gap; $a2g = $a2 + $gap;
                        $x1 = round($cx + $r*cos($a1g),2); $y1 = round($cy - $r*sin($a1g),2);
                        $x2 = round($cx + $r*cos($a2g),2); $y2 = round($cy - $r*sin($a2g),2);
                        echo '<path d="M '.$x1.','.$y1.' A '.$r.','.$r.' 0 0,1 '.$x2.','.$y2.'"';
                        echo ' stroke="'.$seg_colors[$i].'" stroke-width="'.$seg_width.'" fill="none" stroke-linecap="butt"/>';
                        $amid = deg2rad(180 - ($i+0.5)*36);
                        $lxo = round($cx + ($r+22)*cos($amid),1);
                        $lyo = round($cy - ($r+22)*sin($amid),1);
                        $parts = explode(' ', $seg_labels[$i]);
                        if ( count($parts) === 2 ) {
                            echo '<text x="'.$lxo.'" y="'.($lyo-5).'" font-size="9" fill="var(--bt-text)" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="600">'.$parts[0].'</text>';
                            echo '<text x="'.$lxo.'" y="'.($lyo+6).'" font-size="9" fill="var(--bt-text)" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="600">'.$parts[1].'</text>';
                        } else {
                            echo '<text x="'.$lxo.'" y="'.($lyo+3).'" font-size="9" fill="var(--bt-text)" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="600">'.$seg_labels[$i].'</text>';
                        }
                    }
                    ?>
                    <polygon points="<?php echo round($nx,1).','.round($ny,1).' '.round($bx1,1).','.round($by1,1).' '.round($bx2,1).','.round($by2,1); ?>"
                        fill="url(#fng-needle-grad)" opacity="0.92"/>
                    <circle cx="<?php echo $cx;?>" cy="<?php echo $cy;?>" r="18" fill="var(--bt-bg)" stroke="<?php echo $zone_color;?>" stroke-width="2.5"/>
                    <text x="<?php echo $cx;?>" y="<?php echo $cy+7;?>" font-size="15" font-weight="800" fill="<?php echo $zone_color;?>" text-anchor="middle" font-family="system-ui,sans-serif"><?php echo $value;?></text>
                </svg>
                <div class="fxlm-fng-v3-zone" style="color:<?php echo $zone_color;?>;background:<?php echo $zone_bg;?>"><?php echo strtoupper($zone_label);?></div>
            </div>

            <!-- Historical line chart (canvas) -->
            <div class="fxlm-fng-chart-wrap" style="padding:0 10px;margin:12px 0 4px">
                <canvas id="fxlm-fng-chart" height="80" style="width:100%;display:block"></canvas>
            </div>

            <div class="fxlm-fng-v3-history">
                <div class="fxlm-fng-v3-hist-title" id="fxlm-fng-hist-title">7-DAY HISTORY</div>
                <div class="fxlm-fng-v3-bars" id="fxlm-fng-bars">
                <?php foreach ( $history as $day ) :
                    $v = intval($day['value']);
                    $c = $v<=24?'#e84040':($v<=44?'#f07028':($v<=55?'#d4a017':($v<=75?'#6abf69':'#2db87a')));
                    $ds = isset($day['timestamp']) ? date('D',$day['timestamp']) : '';
                ?>
                    <div class="fxlm-fng-v3-bar-col">
                        <div class="fxlm-fng-v3-bar-num" style="color:<?php echo $c;?>"><?php echo $v;?></div>
                        <div class="fxlm-fng-v3-bar-track"><div class="fxlm-fng-v3-bar-fill" style="background:<?php echo $c;?>"></div></div>
                        <div class="fxlm-fng-v3-bar-day"><?php echo esc_html($ds);?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="fxlm-fng-v3-legend">
                <span><span class="fxlm-fng-v3-dot" style="background:#e84040"></span>0–24 Extreme Fear</span>
                <span><span class="fxlm-fng-v3-dot" style="background:#f07028"></span>25–44 Fear</span>
                <span><span class="fxlm-fng-v3-dot" style="background:#d4a017"></span>45–55 Neutral</span>
                <span><span class="fxlm-fng-v3-dot" style="background:#6abf69"></span>56–75 Greed</span>
                <span><span class="fxlm-fng-v3-dot" style="background:#2db87a"></span>76–100 Extreme Greed</span>
                <span style="margin-left:auto"><?php echo $source_label; ?></span>
            </div>
        </div>
        <script>
        (function(){
            var LABELS  = {7:'7-DAY HISTORY',30:'30-DAY HISTORY',90:'3-MONTH HISTORY'};
            var AJAX    = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var allData = <?php echo wp_json_encode( array_values($fng['data']) ); ?>;

            function colorFor(v){ return v<=24?'#e84040':v<=44?'#f07028':v<=55?'#d4a017':v<=75?'#6abf69':'#2db87a'; }

            function renderBars(data) {
                var step  = Math.max(1, Math.floor(data.length/7));
                var shown = []; for(var i=0;i<data.length;i+=step) shown.push(data[i]); shown=shown.slice(0,7).reverse();
                var bars = document.getElementById('fxlm-fng-bars');
                if(!bars) return;
                bars.innerHTML = shown.map(function(day){
                    var v=parseInt(day.value), c=colorFor(v);
                    var dt = day.timestamp ? new Date(day.timestamp*1000).toLocaleDateString('en',{weekday:'short'}) : '';
                    return '<div class="fxlm-fng-v3-bar-col">'
                        +'<div class="fxlm-fng-v3-bar-num" style="color:'+c+'">'+v+'</div>'
                        +'<div class="fxlm-fng-v3-bar-track"><div class="fxlm-fng-v3-bar-fill" style="background:'+c+'"></div></div>'
                        +'<div class="fxlm-fng-v3-bar-day">'+dt+'</div></div>';
                }).join('');
            }

            function renderLineChart(data) {
                var canvas = document.getElementById('fxlm-fng-chart');
                if (!canvas || !canvas.getContext) return;
                var reversed = data.slice().reverse();
                var values = reversed.map(function(d){ return parseInt(d.value); });
                var W = canvas.parentElement.offsetWidth || 400;
                var H = 80;
                canvas.width = W; canvas.height = H;
                var ctx = canvas.getContext('2d');
                ctx.clearRect(0,0,W,H);
                if (values.length < 2) return;
                // Gradient fill under line
                var xStep = W / (values.length - 1);
                ctx.beginPath();
                values.forEach(function(v,i){
                    var x = i * xStep;
                    var y = H - (v / 100 * H);
                    if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
                });
                ctx.lineTo(W, H); ctx.lineTo(0, H); ctx.closePath();
                var fillGrad = ctx.createLinearGradient(0,0,0,H);
                fillGrad.addColorStop(0,'rgba(0,255,102,.18)');
                fillGrad.addColorStop(1,'rgba(0,255,102,.01)');
                ctx.fillStyle = fillGrad; ctx.fill();
                // Line
                ctx.beginPath();
                values.forEach(function(v,i){
                    var x = i * xStep;
                    var y = H - (v / 100 * H);
                    if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
                });
                var lineGrad = ctx.createLinearGradient(0,0,W,0);
                lineGrad.addColorStop(0,'#e84040');
                lineGrad.addColorStop(0.5,'#d4a017');
                lineGrad.addColorStop(1,'#2db87a');
                ctx.strokeStyle = lineGrad;
                ctx.lineWidth = 2; ctx.lineJoin='round'; ctx.lineCap='round';
                ctx.stroke();
            }

            function loadPeriod(period) {
                var subset = allData.slice(0, parseInt(period));
                renderBars(subset);
                renderLineChart(subset);
                var t = document.getElementById('fxlm-fng-hist-title');
                if(t) t.textContent = LABELS[period] || period+'-DAY HISTORY';
            }

            document.querySelectorAll('.fxlm-fng-tab').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.fxlm-fng-tab').forEach(function(b){b.classList.remove('active');});
                    btn.classList.add('active');
                    var period = parseInt(btn.dataset.period);
                    if (allData.length >= period) {
                        loadPeriod(period);
                    } else {
                        fetch(AJAX+'?action=fxlm_fng_period&period='+period)
                        .then(function(r){return r.json();})
                        .then(function(d){
                            if (!d.success||!d.data) return;
                            allData = d.data;
                            loadPeriod(period);
                        }).catch(function(){});
                    }
                });
            });

            // Init
            loadPeriod(7);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    private static function fng_color( $val ) {
        if ( $val <= 25 ) return 'var(--bt-danger)';
        if ( $val <= 45 ) return '#ff8c42';
        if ( $val <= 55 ) return 'var(--bt-accent-warm)';
        if ( $val <= 75 ) return '#84cc16';
        return 'var(--bt-accent)';
    }

    // ── CRYPTO CONVERTER ──

    public static function sc_crypto_converter( $atts ) {
        $a      = shortcode_atts( array( 'show_popular' => 'true' ), $atts );
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coins  = ! empty( $crypto['coins'] ) ? $crypto['coins'] : array();

        // Build forex rates
        $forex_data = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $fiat = array(
            'USD' => array( 'name' => 'United States Dollar "$"', 'symbol' => '$',  'rate' => 1.0 ),
            'EUR' => array( 'name' => 'Euro',                    'symbol' => '€',  'rate' => 0.0 ),
            'GBP' => array( 'name' => 'British Pound',           'symbol' => '£',  'rate' => 0.0 ),
            'JPY' => array( 'name' => 'Japanese Yen',            'symbol' => '¥',  'rate' => 0.0 ),
            'CAD' => array( 'name' => 'Canadian Dollar',         'symbol' => 'C$', 'rate' => 0.0 ),
            'AUD' => array( 'name' => 'Australian Dollar',       'symbol' => 'A$', 'rate' => 0.0 ),
            'CHF' => array( 'name' => 'Swiss Franc',             'symbol' => 'Fr', 'rate' => 0.0 ),
            'CNY' => array( 'name' => 'Chinese Yuan',            'symbol' => '¥',  'rate' => 0.0 ),
            'INR' => array( 'name' => 'Indian Rupee',            'symbol' => '₹',  'rate' => 0.0 ),
            'BRL' => array( 'name' => 'Brazilian Real',          'symbol' => 'R$', 'rate' => 0.0 ),
        );
        $pairs = array( 'EUR/USD','GBP/USD','USD/JPY','USD/CAD','AUD/USD','USD/CHF','USD/CNY','USD/INR','USD/BRL' );
        foreach ( $pairs as $pair ) {
            if ( ! empty( $forex_data['rates'][$pair]['rate'] ) ) {
                $parts = explode('/', $pair);
                $base = $parts[0]; $quote = $parts[1];
                $r = floatval($forex_data['rates'][$pair]['rate']);
                if ( $base === 'USD' && isset($fiat[$quote]) ) $fiat[$quote]['rate'] = round(1/$r,6);
                elseif ( $quote === 'USD' && isset($fiat[$base]) ) $fiat[$base]['rate'] = $r;
            }
        }
        // Fallback rates
        $fallback = array('EUR'=>0.924,'GBP'=>0.787,'JPY'=>0.00667,'CAD'=>0.731,'AUD'=>0.647,'CHF'=>1.120,'CNY'=>0.138,'INR'=>0.01198,'BRL'=>0.183);
        foreach ($fiat as $code => &$f) {
            if ($f['rate'] == 0.0) $f['rate'] = $fallback[$code] ?? 0.0;
        }
        unset($f);

        // Popular conversion pairs
        $popular_pairs = array(
            array('from'=>'bitcoin','to'=>'usd'),array('from'=>'bitcoin','to'=>'eur'),
            array('from'=>'ethereum','to'=>'usd'),array('from'=>'bitcoin','to'=>'gbp'),
            array('from'=>'solana','to'=>'usd'),array('from'=>'bitcoin','to'=>'cad'),
            array('from'=>'ripple','to'=>'usd'),array('from'=>'dogecoin','to'=>'usd'),
            array('from'=>'binancecoin','to'=>'usd'),array('from'=>'bitcoin','to'=>'jpy'),
            array('from'=>'cardano','to'=>'usd'),array('from'=>'bitcoin','to'=>'aud'),
        );

        // Build JS coin prices object
        $js_coins = array();
        foreach ( array_slice($coins, 0, 100) as $c ) {
            $js_coins[$c['id']] = array(
                'name'   => $c['name'],
                'symbol' => strtoupper($c['symbol']),
                'price'  => floatval($c['current_price']),
                'change' => round(floatval($c['price_change_percentage_24h'] ?? 0), 2),
            );
        }
        $js_fiat = array();
        foreach ($fiat as $code => $f) {
            $js_fiat[strtolower($code)] = array(
                'name'   => $f['name'] . ' (' . $code . ')',
                'symbol' => $f['symbol'],
                'rate'   => $f['rate'], // USD per 1 fiat unit
            );
        }

        ob_start();
        echo '<script>window.BT_CONV_COINS=' . wp_json_encode($js_coins) . ';window.BT_CONV_FIAT=' . wp_json_encode($js_fiat) . ';</script>';
        ?>
        <div class="bt-converter-wrap" id="bt-converter">
            <h2 class="bt-converter-title">Cryptocurrency Converter Calculator</h2>

            <div class="bt-converter-card">
                <!-- Amount input -->
                <div class="bt-conv-amount-row">
                    <input type="number" id="bt-conv-amount" value="1" min="0" step="any"
                        class="bt-conv-amount-input" oninput="btConvCalc()">
                </div>

                <!-- From / Swap / To row -->
                <div class="bt-conv-selects-row">
                    <div class="bt-conv-select-wrap">
                        <select id="bt-conv-from" class="bt-conv-select" onchange="btConvCalc()">
                            <optgroup label="Cryptocurrencies">
                            <?php foreach ( array_slice($coins, 0, 100) as $c ):
                                $sel = ($c['id'] === 'bitcoin') ? ' selected' : ''; ?>
                            <option value="crypto:<?php echo esc_attr($c['id']); ?>"<?php echo $sel; ?>>
                                <?php echo esc_html($c['name']); ?> (<?php echo esc_html(strtoupper($c['symbol'])); ?>)
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Fiat Currencies">
                            <?php foreach ($fiat as $code => $f): ?>
                            <option value="fiat:<?php echo esc_attr(strtolower($code)); ?>">
                                <?php echo esc_html($f['name']); ?> (<?php echo esc_html($code); ?>)
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <button class="bt-conv-swap" onclick="btConvSwap()" title="Swap currencies">⇄</button>

                    <div class="bt-conv-select-wrap">
                        <select id="bt-conv-to" class="bt-conv-select" onchange="btConvCalc()">
                            <optgroup label="Fiat Currencies">
                            <?php foreach ($fiat as $code => $f): $sel = ($code === 'USD') ? ' selected' : ''; ?>
                            <option value="fiat:<?php echo esc_attr(strtolower($code)); ?>"<?php echo $sel; ?>>
                                <?php echo esc_html($f['name']); ?> (<?php echo esc_html($code); ?>)
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Cryptocurrencies">
                            <?php foreach ( array_slice($coins, 0, 100) as $c ): ?>
                            <option value="crypto:<?php echo esc_attr($c['id']); ?>">
                                <?php echo esc_html($c['name']); ?> (<?php echo esc_html(strtoupper($c['symbol'])); ?>)
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <!-- Result -->
                <div class="bt-conv-result" id="bt-conv-result">
                    <span id="bt-conv-result-text">—</span>
                </div>

                <button class="bt-conv-refresh" onclick="btConvRefresh()">↻ Refresh Rates</button>
            </div>

            <?php if ( $a['show_popular'] === 'true' && ! empty($popular_pairs) ): ?>
            <div class="bt-conv-popular">
                <h3 class="bt-conv-popular-title">Popular Cryptocurrency Conversions</h3>
                <div class="bt-conv-popular-grid" id="bt-conv-popular-grid">
                    <?php foreach ($popular_pairs as $pair):
                        $from_id = $pair['from'];
                        $to_id   = $pair['to'];
                        $from_coin = null;
                        foreach ($coins as $c) { if ($c['id'] === $from_id) { $from_coin = $c; break; } }
                        if (!$from_coin) continue;
                        $from_price = floatval($from_coin['current_price']);
                        $to_fiat = strtoupper($to_id);
                        $to_rate = isset($fiat[$to_fiat]) ? $fiat[$to_fiat]['rate'] : 1.0;
                        $to_sym  = isset($fiat[$to_fiat]) ? $fiat[$to_fiat]['symbol'] : '$';
                        $result  = $from_price * ($to_rate > 0 ? $to_rate : 1.0);
                        if ($result < 0.01)      $formatted = number_format($result, 6);
                        elseif ($result < 1)     $formatted = number_format($result, 4);
                        elseif ($result < 1000)  $formatted = number_format($result, 2);
                        else                     $formatted = number_format($result, 2);
                        $chg = floatval($from_coin['price_change_percentage_24h'] ?? 0);
                        $chg_class = $chg >= 0 ? 'bt-conv-up' : 'bt-conv-dn';
                        $chg_arrow = $chg >= 0 ? '▲' : '▼';
                    ?>
                    <div class="bt-conv-pop-item">
                        <?php if (!empty($from_coin['image'])): ?>
                        <img src="<?php echo esc_url($from_coin['image']); ?>" width="16" height="16" alt="" loading="lazy">
                        <?php endif; ?>
                        <span class="bt-conv-pop-pair">
                            <strong><?php echo esc_html(ucfirst($from_id)); ?></strong> to <?php echo esc_html($to_fiat); ?>
                        </span>
                        <span class="bt-conv-pop-rate"><?php echo esc_html($to_sym . $formatted); ?></span>
                        <span class="<?php echo $chg_class; ?>"><?php echo $chg_arrow . ' ' . abs(round($chg,2)); ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var COINS = window.BT_CONV_COINS || {};
            var FIAT  = window.BT_CONV_FIAT  || {};

            function getUsdValue(val) {
                var parts = val.split(':');
                var type = parts[0], id = parts[1];
                if (type === 'crypto') return COINS[id] ? COINS[id].price : 0;
                if (type === 'fiat')   return FIAT[id]  ? (FIAT[id].rate > 0 ? 1 / FIAT[id].rate : 0) : 0;
                return 0;
            }
            function getName(val) {
                var parts = val.split(':');
                var type = parts[0], id = parts[1];
                if (type === 'crypto') return COINS[id] ? COINS[id].name + ' (' + COINS[id].symbol + ')' : id;
                if (type === 'fiat') {
                    var f = FIAT[id]; if (!f) return id.toUpperCase();
                    var code = id.toUpperCase();
                    return f.name || (code);
                }
                return id;
            }
            window.btConvCalc = function() {
                var amount = parseFloat(document.getElementById('bt-conv-amount').value) || 0;
                var fromVal = document.getElementById('bt-conv-from').value;
                var toVal   = document.getElementById('bt-conv-to').value;
                var fromUsd = getUsdValue(fromVal);
                var toUsd   = getUsdValue(toVal);
                var result  = document.getElementById('bt-conv-result-text');
                if (!fromUsd || !toUsd) { result.innerHTML = '—'; return; }
                var converted = amount * fromUsd / toUsd;
                var fmt = converted < 0.0001 ? converted.toFixed(8) : converted < 1 ? converted.toFixed(4) : converted < 10000 ? converted.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) : converted.toLocaleString(undefined,{maximumFractionDigits:2});
                result.innerHTML = '<span class="bt-conv-amount-display">' + amount.toLocaleString() + '</span> ' + getName(fromVal) + ' <span class="bt-conv-eq">=</span> <span class="bt-conv-result-num">' + fmt + '</span> ' + getName(toVal);
            };
            window.btConvSwap = function() {
                var f = document.getElementById('bt-conv-from');
                var t = document.getElementById('bt-conv-to');
                var tmp = f.value; f.value = t.value; t.value = tmp;
                btConvCalc();
            };
            window.btConvRefresh = function() {
                var btn = document.querySelector('.bt-conv-refresh');
                btn.textContent = '↻ Refreshing...'; btn.disabled = true;
                fetch('<?php echo esc_url(rest_url("blockticker/v1/prices")); ?>')
                    .then(function(r){return r.json();})
                    .then(function(d){
                        if (d.success && d.data && d.data.crypto) {
                            d.data.crypto.forEach(function(c){ if(COINS[c.id]) COINS[c.id].price = c.current_price; });
                        }
                        btConvCalc();
                        btn.textContent = '↻ Refresh Rates'; btn.disabled = false;
                    }).catch(function(){ btn.textContent = '↻ Refresh Rates'; btn.disabled = false; });
            };
            // Init
            document.addEventListener('DOMContentLoaded', btConvCalc);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

        // ── NEWSLETTER ──

    public static function sc_newsletter( $atts ) {
        $a = shortcode_atts( array( 'style' => 'inline', 'headline' => '', 'subtext' => '' ), $atts );
        $headline = $a['headline'] ?: '📬 Stay Ahead of the Market';
        $subtext  = $a['subtext']  ?: 'Free daily digest: top crypto & forex news, signals, and analysis — delivered to your inbox every morning.';
        $is_banner = $a['style'] === 'banner';
        $wrap_class = $is_banner ? 'bt-newsletter-full' : '';

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrap_class); ?>">
        <div class="fxlm-newsletter-v2 <?php echo $is_banner ? 'fxlm-newsletter-v2-banner' : ''; ?>" id="fxlm-newsletter">
            <div class="fxlm-newsletter-v2-left">
                <div class="fxlm-newsletter-v2-icon">📬</div>
                <div>
                    <div class="fxlm-newsletter-v2-title"><?php echo esc_html( ltrim($headline, '📬 ') ); ?></div>
                    <div class="fxlm-newsletter-v2-sub"><?php echo esc_html( $subtext ); ?></div>
                    <!-- Trust signals -->
                    <div class="fxlm-newsletter-v2-trust">
                        <span>✓ Free forever</span>
                        <span>✓ No spam</span>
                        <span>✓ Unsubscribe anytime</span>
                    </div>
                </div>
            </div>
            <div class="fxlm-newsletter-v2-right">
                <div class="fxlm-newsletter-v2-form">
                    <input type="email" id="fxlm-newsletter-email" placeholder="your@email.com" required class="fxlm-newsletter-v2-input">
                    <button id="fxlm-newsletter-btn" class="fxlm-newsletter-v2-btn">
                        Subscribe Free →
                    </button>
                </div>
                <div class="fxlm-newsletter-msg" id="fxlm-newsletter-msg"></div>
                <div class="fxlm-newsletter-v2-freq">Daily at 08:00 UTC · Crypto + Forex + Signals</div>
            </div>
        </div>
        <?php if ($is_banner): ?></div><?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public static function ajax_subscribe() {
        BT_Utils::verify_public_ajax( 'fxlm_prices' );

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Please enter a valid email address.' );
        }

        $subscribers = get_option( 'bt_subscribers', array() );

        // Check duplicate
        if ( in_array( $email, array_column( $subscribers, 'email' ) ) ) {
            wp_send_json_error( 'You\'re already subscribed!' );
        }

        $subscribers[] = array(
            'email'      => $email,
            'subscribed' => current_time( 'mysql' ),
            'ip'         => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        );
        update_option( 'bt_subscribers', $subscribers );

        // Trigger hook for external integrations (Mailchimp, Sendinblue, etc.)
        do_action( 'fxlm_new_subscriber', $email );

        wp_send_json_success( 'Welcome aboard! You\'ll receive our next market digest.' );
    }

    // ── SEARCH BAR ──

    public static function sc_search_bar( $atts ) {
        ob_start();
        ?>
        <div class="fxlm-search-v2">
            <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="fxlm-search-v2-form">
                <div class="fxlm-search-v2-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--bt-text-3)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
                <input type="search" name="s" placeholder="Search news, coins, signals, pairs…"
                    value="<?php echo esc_attr( get_search_query() ); ?>"
                    class="fxlm-search-v2-input" autocomplete="off">
                <button type="submit" class="fxlm-search-v2-btn">Search</button>
            </form>
            <!-- Quick filters -->
            <div class="fxlm-search-v2-quick">
                <span class="fxlm-search-v2-label">Quick:</span>
                <a href="<?php echo esc_url( home_url('/?s=bitcoin') ); ?>" class="fxlm-search-v2-tag">Bitcoin</a>
                <a href="<?php echo esc_url( home_url('/?s=ethereum') ); ?>" class="fxlm-search-v2-tag">Ethereum</a>
                <a href="<?php echo esc_url( home_url('/?s=EUR/USD') ); ?>" class="fxlm-search-v2-tag">EUR/USD</a>
                <a href="<?php echo esc_url( home_url('/?s=signals') ); ?>" class="fxlm-search-v2-tag">Signals</a>
                <a href="<?php echo esc_url( home_url('/?s=DeFi') ); ?>" class="fxlm-search-v2-tag">DeFi</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── BREADCRUMBS ──

    public static function sc_breadcrumbs( $atts ) {
        if ( is_front_page() ) return '';

        $items = array();
        $items[] = '<a href="' . esc_url( home_url() ) . '">Home</a>';

        if ( is_category() ) {
            $items[] = '<span>' . single_cat_title( '', false ) . '</span>';
        } elseif ( is_single() ) {
            $cats = get_the_category();
            if ( $cats ) {
                $items[] = '<a href="' . esc_url( get_category_link( $cats[0]->term_id ) ) . '">' . esc_html( $cats[0]->name ) . '</a>';
            }
            $items[] = '<span>' . get_the_title() . '</span>';
        } elseif ( is_page() ) {
            $items[] = '<span>' . get_the_title() . '</span>';
        } elseif ( is_search() ) {
            $items[] = '<span>Search: ' . esc_html( get_search_query() ) . '</span>';
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array(),
        );
        foreach ( $items as $i => $item ) {
            $schema['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => strip_tags( $item ),
            );
        }

        ob_start();
        echo '<nav class="fxlm-breadcrumbs" aria-label="Breadcrumb">';
        echo implode( ' <span class="fxlm-bc-sep">›</span> ', $items );
        echo '</nav>';
        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
        return ob_get_clean();
    }

    // ── TRENDING BAR ──

    public static function sc_trending_bar( $atts ) {
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return '';

        // Sort by absolute 24h change to find most volatile
        $coins = $crypto['coins'];
        usort( $coins, function( $a, $b ) {
            return abs( $b['price_change_percentage_24h'] ?? 0 ) <=> abs( $a['price_change_percentage_24h'] ?? 0 );
        });

        ob_start();
        echo '<div class="fxlm-trending-bar">';
        echo '<span class="fxlm-trending-label">🔥 Trending</span>';
        foreach ( array_slice( $coins, 0, 6 ) as $coin ) {
            $chg = floatval( $coin['price_change_percentage_24h'] ?? 0 );
            $cls = $chg >= 0 ? 'up' : 'down';
            echo '<a href="' . esc_url( home_url( '/crypto-markets/' ) ) . '" class="fxlm-trending-item">';
            echo '<img src="' . esc_url( $coin['image'] ) . '" width="16" height="16" alt="">';
            echo '<strong>' . esc_html( strtoupper( $coin['symbol'] ) ) . '</strong>';
            echo '<span class="' . $cls . '">' . ( $chg >= 0 ? '▲' : '▼' ) . ' ' . number_format( abs( $chg ), 1 ) . '%</span>';
            echo '</a>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // ── PRICE CARDS (hero section) ──

    // Coin ID → asset page slug mapping
    private static $coin_id_to_slug = array(
        'bitcoin'          => 'bitcoin',
        'ethereum'         => 'ethereum',
        'solana'           => 'solana',
        'ripple'           => 'xrp',
        'binancecoin'      => 'binance-coin',
        'dogecoin'         => 'dogecoin',
        'cardano'          => 'cardano',
        'avalanche-2'      => 'avalanche',
        'chainlink'        => 'chainlink',
        'polkadot'         => 'polkadot',
        'tron'             => 'tron',
        'monero'           => 'monero',
        'litecoin'         => 'litecoin',
        'stellar'          => 'stellar',
        'hyperliquid'      => 'hyperliquid',
        'uniswap'          => 'uniswap',
        'near'             => 'near-protocol',
        'bitcoin-cash'     => 'bitcoin-cash',
        'internet-computer'=> 'internet-computer',
        'aptos'            => 'aptos',
    );

    public static function sc_price_cards( $atts ) {
        $a   = shortcode_atts( array( 'coins' => 'bitcoin,ethereum,solana,ripple' ), $atts );
        $ids = array_map( 'trim', explode( ',', $a['coins'] ) );

        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return '';

        ob_start();
        echo '<div class="fxlm-price-cards">';
        foreach ( $crypto['coins'] as $coin ) {
            if ( ! in_array( $coin['id'], $ids ) ) continue;
            $chg   = floatval( $coin['price_change_percentage_24h'] ?? 0 );
            $cls   = $chg >= 0 ? 'up' : 'down';
            $slug  = self::$coin_id_to_slug[ $coin['id'] ] ?? sanitize_title( $coin['name'] );
            $url   = home_url( '/crypto/' . $slug . '/' );
            $arrow = $chg >= 0 ? '▲' : '▼';
            // Wrap entire card in anchor for clickability (FIX screenshot4)
            echo '<a href="' . esc_url($url) . '" class="fxlm-price-card fxlm-price-card-link" title="View ' . esc_attr($coin['name']) . ' details">';
            echo '<div class="fxlm-price-card-top">';
            echo '<img src="' . esc_url( $coin['image'] ) . '" width="28" loading="lazy" alt="' . esc_attr($coin['name']) . '">';
            echo '<div><strong>' . esc_html( $coin['name'] ) . '</strong><small>' . esc_html( strtoupper( $coin['symbol'] ) ) . '</small></div>';
            echo '</div>';
            echo '<div class="fxlm-price-card-price" data-symbol="' . esc_attr(strtolower($coin['symbol'])) . '">$' . number_format( $coin['current_price'], 2 ) . '</div>';
            echo '<div class="fxlm-price-card-change ' . $cls . '">' . $arrow . ' ' . number_format( abs( $chg ), 2 ) . '%</div>';
            echo '<div class="fxlm-price-card-mcap">MCap: $' . self::format_large( $coin['market_cap'] ?? 0 ) . '</div>';
            echo '<div class="fxlm-price-card-cta">View Details →</div>';
            echo '</a>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function format_large( $n ) {
        if ( $n >= 1e12 ) return number_format( $n / 1e12, 2 ) . 'T';
        if ( $n >= 1e9 )  return number_format( $n / 1e9,  2 ) . 'B';
        if ( $n >= 1e6 )  return number_format( $n / 1e6,  2 ) . 'M';
        return number_format( $n, 0 );
    }

    // ── FNG PERIOD AJAX ──
    public static function ajax_fng_period() {
        $period = intval( $_GET['period'] ?? 7 );
        $period = in_array( $period, array(7, 30, 90) ) ? $period : 7;
        $body = BT_Utils::http_get_json( 'https://api.alternative.me/fng/?limit=' . $period, array( 'timeout' => 10 ) );
        if ( is_wp_error( $body ) ) { wp_send_json_error( 'Fetch failed' ); return; }
        if ( empty( $body['data'] ) ) { wp_send_json_error( 'No data' ); return; }
        wp_send_json_success( $body['data'] );
    }

    // ── GLOSSARY ──

    public static function sc_glossary( $atts ) {
        $terms = array(
            'Altcoin' => 'Any cryptocurrency other than Bitcoin. Examples include Ethereum, Solana, and XRP.',
            'ATH (All-Time High)' => 'The highest price a cryptocurrency has ever reached.',
            'Bear Market' => 'A market condition where prices are falling or expected to fall over a sustained period.',
            'Blockchain' => 'A decentralized, distributed digital ledger that records transactions across many computers.',
            'Bull Market' => 'A market condition where prices are rising or expected to rise.',
            'CEX' => 'Centralized Exchange — a crypto trading platform run by a company (e.g., Binance, Coinbase).',
            'DeFi' => 'Decentralized Finance — financial services built on blockchain without traditional intermediaries.',
            'DEX' => 'Decentralized Exchange — a peer-to-peer crypto exchange with no central authority.',
            'DYOR' => 'Do Your Own Research — a reminder to investigate before investing.',
            'Forex' => 'Foreign Exchange — the global market for trading national currencies against each other.',
            'Gas Fees' => 'Transaction fees paid to validators on a blockchain network (mainly Ethereum).',
            'HODL' => 'Hold On for Dear Life — a strategy of holding crypto long-term regardless of price swings.',
            'Leverage' => 'Using borrowed funds to increase potential returns (and risks) on a trade.',
            'Liquidity' => 'How easily an asset can be bought or sold without significantly affecting its price.',
            'Market Cap' => 'Total value of a cryptocurrency: current price × circulating supply.',
            'NFT' => 'Non-Fungible Token — a unique digital asset stored on a blockchain.',
            'Pip' => 'The smallest price movement in forex trading, typically 0.0001 for most currency pairs.',
            'Spread' => 'The difference between the buy (ask) and sell (bid) price of an asset.',
            'Stablecoin' => 'A cryptocurrency pegged to a stable asset like the US Dollar (e.g., USDT, USDC).',
            'Staking' => 'Locking up crypto to support network operations and earn rewards.',
            'Stop-Loss' => 'An order to automatically sell an asset when it reaches a specified price to limit losses.',
            'Wallet' => 'A tool (software or hardware) for storing, sending, and receiving cryptocurrency.',
            'Whale' => 'An individual or entity that holds a very large amount of cryptocurrency.',
            'Yield Farming' => 'Earning rewards by providing liquidity to DeFi protocols.',
        );

        // Group terms by first letter
        $grouped = array();
        foreach ( $terms as $term => $def ) {
            $letter = strtoupper( $term[0] );
            $grouped[ $letter ][] = array( 'term' => $term, 'def' => $def );
        }

        // Color palette per letter
        $colors = array(
            'A'=>'var(--bt-accent)','B'=>'var(--bt-accent)','C'=>'#f7931a','D'=>'#a78bfa',
            'F'=>'#10b981','G'=>'var(--bt-accent-warm)','H'=>'var(--bt-danger)','L'=>'var(--bt-accent)',
            'M'=>'var(--bt-accent)','N'=>'#a78bfa','P'=>'#f7931a','S'=>'#10b981',
            'W'=>'var(--bt-accent-warm)','Y'=>'#ff6b81',
        );

        ob_start();
        echo '<div class="fxlm-glossary-v2">';
        foreach ( $grouped as $letter => $items ) {
            $color = $colors[ $letter ] ?? 'var(--bt-accent)';
            echo '<div class="fxlm-glossary-section" id="glossary-' . $letter . '">';
            echo '<div class="fxlm-glossary-letter-header" style="--letter-color:' . $color . '">';
            echo '<span class="fxlm-glossary-big-letter" style="color:' . $color . '">' . $letter . '</span>';
            echo '</div>';
            echo '<div class="fxlm-glossary-cards">';
            foreach ( $items as $item ) {
                echo '<div class="fxlm-glossary-card" style="--card-accent:' . $color . '">';
                echo '<div class="fxlm-glossary-card-letter" style="color:' . $color . ';background:' . $color . '18">' . $letter . '</div>';
                echo '<h3 class="fxlm-glossary-card-term">' . esc_html( $item['term'] ) . '</h3>';
                echo '<p class="fxlm-glossary-card-def">' . esc_html( $item['def'] ) . '</p>';
                echo '</div>';
            }
            echo '</div></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Search results page — intercepts /?s= and renders a rich BlockTicker
     * results page instead of the bare theme default.
     * ------------------------------------------------------------------ */
    public static function render_search_page() {
        if ( ! is_search() ) return;

        $query   = get_search_query();
        $q_lower = strtolower( $query );

        // Run the WP post search
        $search_posts = new WP_Query( array(
            's'              => $query,
            'posts_per_page' => 18,
            'post_status'    => 'publish',
            'post_type'      => array( 'post', 'page' ),
        ) );

        // Live data: match coins
        $crypto      = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $all_coins   = $crypto['coins'] ?? array();
        $matched_coins = array();
        foreach ( $all_coins as $coin ) {
            $name_match   = stripos( $coin['name']   ?? '', $query ) !== false;
            $symbol_match = stripos( $coin['symbol'] ?? '', $query ) !== false;
            if ( $name_match || $symbol_match ) {
                $matched_coins[] = $coin;
                if ( count( $matched_coins ) >= 4 ) break;
            }
        }

        // Live data: match forex pairs
        $forex_data     = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $forex_pairs    = $forex_data['rates'] ?? array();
        $matched_forex  = array();
        foreach ( $forex_pairs as $pair => $data ) {
            if ( stripos( $pair, $query ) !== false ) {
                $matched_forex[ $pair ] = $data;
            }
        }

        // News items match
        $all_news     = BT_Widgets::get_json_option( 'fxlm_news_items' );
        $matched_news = array();
        foreach ( $all_news as $item ) {
            if ( stripos( $item['title'] ?? '', $query ) !== false
              || stripos( $item['description'] ?? '', $query ) !== false ) {
                $matched_news[] = $item;
                if ( count( $matched_news ) >= 6 ) break;
            }
        }

        $total_results = $search_posts->found_posts + count( $matched_coins ) + count( $matched_forex ) + count( $matched_news );

        // Render using the theme shell
        get_header();
        ?>
<div class="bt-search-page">

  <!-- ── HEADER ── -->
  <div class="bt-search-header">
    <div class="bt-search-eyebrow">Search Results</div>
    <h1 class="bt-search-title">
      <?php if ( $query ) : ?>
        Results for <span class="bt-search-query">"<?php echo esc_html( $query ); ?>"</span>
      <?php else : ?>
        Search BlockTicker
      <?php endif; ?>
    </h1>
    <?php if ( $total_results > 0 ) : ?>
    <p class="bt-search-count"><?php echo esc_html( $total_results ); ?> result<?php echo $total_results !== 1 ? 's' : ''; ?> across live data and articles</p>
    <?php endif; ?>

    <!-- Search bar -->
    <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="bt-search-form">
      <input type="search" name="s" value="<?php echo esc_attr( $query ); ?>" placeholder="Search coins, pairs, news…" class="bt-search-input" autofocus>
      <button type="submit" class="bt-search-submit">Search</button>
    </form>
    <div class="bt-search-quick">
      <span>Try:</span>
      <a href="<?php echo esc_url( home_url( '/?s=bitcoin' ) ); ?>">Bitcoin</a>
      <a href="<?php echo esc_url( home_url( '/?s=ethereum' ) ); ?>">Ethereum</a>
      <a href="<?php echo esc_url( home_url( '/?s=EUR/USD' ) ); ?>">EUR/USD</a>
      <a href="<?php echo esc_url( home_url( '/?s=solana' ) ); ?>">Solana</a>
      <a href="<?php echo esc_url( home_url( '/?s=DeFi' ) ); ?>">DeFi</a>
    </div>
  </div>

  <?php if ( $total_results === 0 ) : ?>
  <div class="bt-search-empty">
    <div class="bt-search-empty-icon">🔍</div>
    <h2>No results found for "<?php echo esc_html( $query ); ?>"</h2>
    <p>Try a coin name (Bitcoin, Ethereum), currency pair (EUR/USD), or topic (DeFi, signals).</p>
  </div>
  <?php endif; ?>

  <!-- ── COIN MATCHES ── -->
  <?php if ( ! empty( $matched_coins ) ) : ?>
  <section class="bt-search-section">
    <div class="bt-search-section-head">
      <span class="bt-search-section-icon">₿</span>
      <span>Live Crypto Prices</span>
      <a href="<?php echo esc_url( home_url( '/crypto-markets/' ) ); ?>" class="bt-search-section-more">View all →</a>
    </div>
    <div class="bt-search-coins">
      <?php foreach ( $matched_coins as $coin ) :
        $chg     = floatval( $coin['price_change_percentage_24h'] ?? 0 );
        $pos     = $chg >= 0;
        $slug_map = array( 'bitcoin'=>'bitcoin','ethereum'=>'ethereum','solana'=>'solana','ripple'=>'xrp','binancecoin'=>'binance-coin','dogecoin'=>'dogecoin','cardano'=>'cardano' );
        $slug    = $slug_map[ $coin['id'] ] ?? sanitize_title( $coin['name'] );
        $url     = home_url( '/crypto/' . $slug . '/' );
      ?>
      <a href="<?php echo esc_url( $url ); ?>" class="bt-search-coin-card">
        <img src="<?php echo esc_url( $coin['image'] ); ?>" alt="" class="bt-search-coin-img" loading="lazy">
        <div class="bt-search-coin-info">
          <div class="bt-search-coin-name"><?php echo esc_html( $coin['name'] ); ?></div>
          <div class="bt-search-coin-sym"><?php echo esc_html( strtoupper( $coin['symbol'] ) ); ?></div>
        </div>
        <div class="bt-search-coin-right">
          <div class="bt-search-coin-price">$<?php echo number_format( $coin['current_price'], $coin['current_price'] < 1 ? 4 : 2 ); ?></div>
          <div class="bt-search-coin-chg <?php echo $pos ? 'pos' : 'neg'; ?>">
            <?php echo $pos ? '▲' : '▼'; ?> <?php echo number_format( abs( $chg ), 2 ); ?>%
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── FOREX PAIR MATCHES ── -->
  <?php if ( ! empty( $matched_forex ) ) : ?>
  <section class="bt-search-section">
    <div class="bt-search-section-head">
      <span class="bt-search-section-icon">💱</span>
      <span>Forex Pairs</span>
      <a href="<?php echo esc_url( home_url( '/forex-charts/' ) ); ?>" class="bt-search-section-more">View charts →</a>
    </div>
    <div class="bt-search-forex">
      <?php foreach ( $matched_forex as $pair => $data ) :
        $rate = floatval( $data['rate'] ?? 0 );
        $chg  = floatval( $data['change'] ?? 0 );
        $slug = strtolower( str_replace( '/', '-', $pair ) );
        $url  = home_url( '/forex/' . $slug . '/' );
      ?>
      <a href="<?php echo esc_url( $url ); ?>" class="bt-search-forex-card">
        <div class="bt-search-forex-pair"><?php echo esc_html( $pair ); ?></div>
        <div class="bt-search-forex-rate"><?php echo number_format( $rate, 4 ); ?></div>
        <div class="bt-search-forex-chg <?php echo $chg >= 0 ? 'pos' : 'neg'; ?>">
          <?php echo $chg >= 0 ? '▲' : '▼'; ?> <?php echo number_format( abs( $chg ), 3 ); ?>%
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── NEWS MATCHES ── -->
  <?php if ( ! empty( $matched_news ) ) : ?>
  <section class="bt-search-section">
    <div class="bt-search-section-head">
      <span class="bt-search-section-icon">📰</span>
      <span>News Articles</span>
      <a href="<?php echo esc_url( home_url( '/financial-news/' ) ); ?>" class="bt-search-section-more">All news →</a>
    </div>
    <div class="bt-search-news">
      <?php
      $src_colors = array( 'coindesk'=>'#f7931a','cointelegraph'=>'#2952e3','decrypt'=>'var(--bt-accent)','marketwatch'=>'#1c6dbf','forexlive'=>'#009688','cnbc'=>'#0066b2','the-block'=>'#a78bfa','blockworks'=>'var(--bt-accent)','fxstreet'=>'var(--bt-accent)','beincrypto'=>'var(--bt-accent)','dailyfx'=>'var(--bt-accent-warm)' );
      foreach ( $matched_news as $item ) :
        $src_slug = sanitize_title( $item['source'] ?? '' );
        $accent   = 'var(--bt-text-4)';
        foreach ( $src_colors as $k => $v ) { if ( strpos( $src_slug, $k ) !== false ) { $accent = $v; break; } }
      ?>
      <a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener" class="bt-search-news-card">
        <div class="bt-search-news-src" style="color:<?php echo esc_attr($accent); ?>"><?php echo esc_html( $item['source'] ?? '' ); ?></div>
        <div class="bt-search-news-title"><?php echo esc_html( $item['title'] ); ?></div>
        <div class="bt-search-news-time"><?php echo human_time_diff( $item['timestamp'] ); ?> ago</div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── ARTICLE / PAGE MATCHES ── -->
  <?php if ( $search_posts->have_posts() ) : ?>
  <section class="bt-search-section">
    <div class="bt-search-section-head">
      <span class="bt-search-section-icon">📄</span>
      <span>Analysis &amp; Articles</span>
      <span class="bt-search-section-count"><?php echo esc_html( $search_posts->found_posts ); ?> found</span>
    </div>
    <div class="bt-search-posts">
      <?php while ( $search_posts->have_posts() ) : $search_posts->the_post();
        $excerpt = get_the_excerpt();
        if ( ! $excerpt ) $excerpt = wp_trim_words( strip_tags( get_the_content() ), 20, '…' );
        $cats = get_the_category();
        $cat  = ! empty( $cats ) ? $cats[0] : null;
        $cat_colors = array( 'market-analysis'=>'var(--bt-accent)','crypto-news'=>'#f7931a','forex-news'=>'var(--bt-accent)','defi-web3'=>'#a78bfa','education'=>'#10b981' );
        $cat_col = $cat ? ( $cat_colors[ $cat->slug ] ?? 'var(--bt-text-3)' ) : 'var(--bt-text-3)';
      ?>
      <article class="bt-search-post-card">
        <a href="<?php the_permalink(); ?>" class="bt-search-post-link">
          <?php if ( $cat ) : ?>
          <span class="bt-search-post-cat" style="color:<?php echo esc_attr($cat_col); ?>"><?php echo esc_html( $cat->name ); ?></span>
          <?php endif; ?>
          <h3 class="bt-search-post-title"><?php the_title(); ?></h3>
          <p class="bt-search-post-excerpt"><?php echo esc_html( $excerpt ); ?></p>
          <div class="bt-search-post-meta">
            <span class="bt-search-post-date"><?php echo get_the_date( 'M j, Y' ); ?></span>
            <span class="bt-search-post-read">Read →</span>
          </div>
        </a>
      </article>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </section>
  <?php endif; ?>

</div><!-- .bt-search-page -->

<style>
.bt-search-page{max-width:920px;margin:0 auto;padding:32px 20px;color:var(--bt-text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.bt-search-header{margin-bottom:32px}
.bt-search-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--bt-text-3);margin-bottom:8px;font-weight:700}
.bt-search-title{font-size:clamp(1.6rem,3vw,2.4rem);font-weight:800;color:var(--bt-text);margin:0 0 8px;line-height:1.2}
.bt-search-query{color:var(--bt-accent)}
.bt-search-count{color:var(--bt-text-3);font-size:14px;margin:0 0 20px}
.bt-search-form{display:flex;gap:8px;max-width:600px;margin-bottom:12px}
.bt-search-input{flex:1;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px 16px;color:var(--bt-text);font-size:15px;outline:none}
.bt-search-input:focus{border-color:var(--bt-accent)}
.bt-search-submit{background:var(--bt-accent);color:#0b0f1a;border:none;border-radius:0;padding:12px 20px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap}
.bt-search-quick{font-size:13px;color:var(--bt-text-3);display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.bt-search-quick a{color:var(--bt-text-3);text-decoration:none;padding:3px 10px;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:20px;font-size:12px;transition:all .2s}
.bt-search-quick a:hover{color:var(--bt-accent);border-color:var(--bt-accent)}
.bt-search-empty{text-align:center;padding:60px 24px;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;margin:20px 0}
.bt-search-empty-icon{font-size:36px;margin-bottom:12px}
.bt-search-empty h2{color:var(--bt-text);font-size:20px;margin:0 0 8px}
.bt-search-empty p{color:var(--bt-text-3);font-size:14px;margin:0}
.bt-search-section{margin-bottom:32px}
.bt-search-section-head{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #1e2535;font-size:14px;font-weight:700;color:var(--bt-text)}
.bt-search-section-icon{font-size:16px}
.bt-search-section-more{margin-left:auto;font-size:12px;color:var(--bt-accent);text-decoration:none;font-weight:600}
.bt-search-section-more:hover{text-decoration:underline}
.bt-search-section-count{margin-left:auto;font-size:12px;color:var(--bt-text-3);font-weight:400}
/* Coin cards */
.bt-search-coins{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.bt-search-coin-card{display:flex;align-items:center;gap:10px;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px 14px;text-decoration:none;color:var(--bt-text);transition:border-color .2s,background .2s}
.bt-search-coin-card:hover{border-color:var(--bt-accent);background:#131d2e}
.bt-search-coin-img{width:36px;height:36px;border-radius:50%;flex-shrink:0}
.bt-search-coin-name{font-weight:700;font-size:14px;color:var(--bt-text)}
.bt-search-coin-sym{font-size:11px;color:var(--bt-text-3);text-transform:uppercase}
.bt-search-coin-right{margin-left:auto;text-align:right}
.bt-search-coin-price{font-size:15px;font-weight:700;color:var(--bt-text);font-family:monospace}
.bt-search-coin-chg{font-size:12px;font-weight:600}
.bt-search-coin-chg.pos{color:#22c55e}
.bt-search-coin-chg.neg{color:#ef4444}
/* Forex cards */
.bt-search-forex{display:flex;flex-wrap:wrap;gap:10px}
.bt-search-forex-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:14px 18px;text-decoration:none;min-width:160px;transition:border-color .2s}
.bt-search-forex-card:hover{border-color:var(--bt-accent)}
.bt-search-forex-pair{font-size:16px;font-weight:800;color:var(--bt-text);font-family:monospace;margin-bottom:4px}
.bt-search-forex-rate{font-size:20px;font-weight:700;color:var(--bt-text);font-family:monospace;margin-bottom:2px}
.bt-search-forex-chg{font-size:12px;font-weight:600}
.bt-search-forex-chg.pos{color:#22c55e}
.bt-search-forex-chg.neg{color:#ef4444}
/* News cards */
.bt-search-news{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.bt-search-news-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:14px 16px;text-decoration:none;display:flex;flex-direction:column;gap:6px;transition:border-color .2s}
.bt-search-news-card:hover{border-color:var(--bt-text-4)}
.bt-search-news-src{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.bt-search-news-title{font-size:13px;color:var(--bt-text);line-height:1.4;font-weight:500}
.bt-search-news-time{font-size:11px;color:var(--bt-text-3);margin-top:auto}
/* Article cards */
.bt-search-posts{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.bt-search-post-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;transition:border-color .2s,background .2s}
.bt-search-post-card:hover{border-color:var(--bt-text-4);background:#131d2e}
.bt-search-post-link{display:block;padding:16px 18px;text-decoration:none}
.bt-search-post-cat{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:6px}
.bt-search-post-title{font-size:15px;font-weight:700;color:var(--bt-text);margin:0 0 8px;line-height:1.3}
.bt-search-post-excerpt{font-size:12px;color:var(--bt-text-3);line-height:1.55;margin:0 0 10px}
.bt-search-post-meta{display:flex;justify-content:space-between;align-items:center;font-size:11px}
.bt-search-post-date{color:var(--bt-text-3)}
.bt-search-post-read{color:var(--bt-accent);font-weight:600}
</style>
        <?php
        get_footer();
        exit;
    }
}

