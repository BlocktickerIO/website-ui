<?php
/**
 * BT_APIKeys — Public API Key management.
 *
 * Lets end-users mint, revoke and monitor their own API keys for the public
 * BlockTicker REST API, and gives site admins a master console to oversee
 * all keys across the install. Augments the existing `bt_api_keys` option
 * (used by BT_API::resolve_auth since v65.1) with metering + an auth
 * surface that's actually usable by humans.
 *
 * Capabilities:
 *   - User-facing shortcode [bt_api_keys] for self-service creation/revocation
 *   - Admin screen under BlockTicker → 🔑 API Keys with a master table
 *   - 4 REST routes:
 *       POST   /user/api-keys        — mint a new key for the logged-in user
 *       GET    /user/api-keys        — list the logged-in user's keys
 *       DELETE /user/api-keys/{id}   — revoke (soft-delete) a key
 *       POST   /user/api-keys/{id}/rotate — revoke + issue a new secret for
 *                                            the same label + tier (no break)
 *   - Per-key tiers with rate limits: public (60/m), standard (600/m), premium (3000/m)
 *   - Per-key usage metering: usage_today + usage_total, last_used timestamp,
 *     automatic daily rollover via the same 15-min cron that fires price alerts.
 *
 * Key format:
 *   Plain-text key = "bt_" + 8-char prefix (visible) + 32-char secret (hex)
 *   Example:       bt_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0
 *   Stored:        the full key (hash_equals compare in BT_API::resolve_auth).
 *   This prioritises UX — we can show users their active keys later so they
 *   don't have to record them on first mint. For a hashed-at-rest model, see
 *   the future "v116 Zero-Trust Keys" roadmap note in the changelog.
 *
 * Data layout (bt_api_keys_v2 option, array-of-records):
 *   {
 *     id:           string  "bt_<8-char prefix>"
 *     key:          string  the full plaintext key (matches BT_API read path)
 *     user_id:      int
 *     label:        string
 *     tier:         'public' | 'standard' | 'premium'
 *     rate_per_min: int
 *     rate_per_day: int
 *     created:      int
 *     last_used:    int|null
 *     usage_today:  int
 *     usage_total:  int
 *     usage_day:    string  'YYYY-MM-DD' of the day usage_today refers to
 *     revoked:      bool
 *     revoked_at:   int|null
 *   }
 *
 * @package BlockTicker
 * @since   115.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_APIKeys {

	const OPT_KEYS      = 'bt_api_keys_v2';
	const OPT_LEGACY    = 'bt_api_keys'; // read-through for pre-v115 records
	const CRON_ROLLOVER = 'bt_apikeys_daily_rollover';

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	public static function init() {
		add_action( 'admin_menu',    array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_shortcode( 'bt_api_keys', array( __CLASS__, 'sc_user_api_keys' ) );

		// AJAX bridges for admin panel.
		add_action( 'wp_ajax_bt_apikeys_admin_list',    array( __CLASS__, 'ajax_admin_list' ) );
		add_action( 'wp_ajax_bt_apikeys_admin_revoke',  array( __CLASS__, 'ajax_admin_revoke' ) );
		add_action( 'wp_ajax_bt_apikeys_admin_restore', array( __CLASS__, 'ajax_admin_restore' ) );
		add_action( 'wp_ajax_bt_apikeys_admin_delete',  array( __CLASS__, 'ajax_admin_delete' ) );

		// Daily rollover cron — resets usage_today counters at 00:00 UTC.
		if ( ! wp_next_scheduled( self::CRON_ROLLOVER ) ) {
			wp_schedule_event( self::next_midnight_utc(), 'daily', self::CRON_ROLLOVER );
		}
		add_action( self::CRON_ROLLOVER, array( __CLASS__, 'run_daily_rollover' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_ROLLOVER );
	}

	private static function next_midnight_utc() {
		$now = time();
		return strtotime( 'tomorrow 00:00:00 UTC', $now ) ?: $now + DAY_IN_SECONDS;
	}

	/* ------------------------------------------------------------------
	 * Storage helpers
	 * ------------------------------------------------------------------ */

	public static function load_all() {
		$rows = get_option( self::OPT_KEYS, array() );
		if ( ! is_array( $rows ) ) $rows = array();
		return $rows;
	}

	public static function save_all( $rows ) {
		update_option( self::OPT_KEYS, array_values( $rows ), false );
	}

	public static function find_by_key( $plaintext ) {
		foreach ( self::load_all() as $r ) {
			if ( ! empty( $r['key'] ) && hash_equals( $r['key'], $plaintext ) ) return $r;
		}
		return null;
	}

	public static function find_by_id( $id ) {
		foreach ( self::load_all() as $r ) {
			if ( ( $r['id'] ?? '' ) === $id ) return $r;
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * Tier → rate limit
	 * ------------------------------------------------------------------ */

	public static function tier_limits( $tier ) {
		switch ( $tier ) {
			case 'premium':  return array( 'per_min' => 3000, 'per_day' => 1000000 );
			case 'standard': return array( 'per_min' =>  600, 'per_day' =>  100000 );
			case 'public':
			default:         return array( 'per_min' =>   60, 'per_day' =>   10000 );
		}
	}

	/* ------------------------------------------------------------------
	 * Mint / revoke / rotate
	 * ------------------------------------------------------------------ */

	public static function mint( $user_id, $label = '', $tier = 'standard' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) return new WP_Error( 'no_user', 'User required' );
		if ( ! in_array( $tier, array( 'public', 'standard', 'premium' ), true ) ) $tier = 'standard';
		$label = sanitize_text_field( (string) $label );
		if ( $label === '' ) $label = 'Key ' . gmdate( 'Y-m-d H:i' );
		if ( BT_Utils::strlen_unicode( $label ) > 80 ) $label = BT_Utils::substr_unicode( $label, 0, 80 );

		// Enforce per-user soft cap (defensive — no abuse vector yet but obvious ceiling).
		$user_keys = 0;
		foreach ( self::load_all() as $r ) {
			if ( (int) ( $r['user_id'] ?? 0 ) === $user_id && empty( $r['revoked'] ) ) $user_keys++;
		}
		if ( $user_keys >= 10 ) {
			return new WP_Error( 'too_many', 'Maximum 10 active keys per user. Revoke an existing key first.', array( 'status' => 429 ) );
		}

		$prefix = bin2hex( random_bytes( 4 ) );            // 8 chars
		$secret = bin2hex( random_bytes( 16 ) );           // 32 chars
		$plain  = 'bt_' . $prefix . '_' . $secret;
		$id     = 'bt_' . $prefix;

		$limits = self::tier_limits( $tier );

		$rec = array(
			'id'           => $id,
			'key'          => $plain,
			'user_id'      => $user_id,
			'label'        => $label,
			'tier'         => $tier,
			'rate_per_min' => $limits['per_min'],
			'rate_per_day' => $limits['per_day'],
			'created'      => time(),
			'last_used'    => null,
			'usage_today'  => 0,
			'usage_total'  => 0,
			'usage_day'    => gmdate( 'Y-m-d' ),
			'revoked'      => false,
			'revoked_at'   => null,
		);

		$rows = self::load_all();
		$rows[] = $rec;
		self::save_all( $rows );

		return $rec; // plaintext present in `key` — ONLY returned at mint.
	}

	public static function revoke( $id ) {
		$rows = self::load_all();
		foreach ( $rows as &$r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				$r['revoked']    = true;
				$r['revoked_at'] = time();
				self::save_all( $rows );
				return true;
			}
		}
		return false;
	}

	public static function restore( $id ) {
		$rows = self::load_all();
		foreach ( $rows as &$r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				$r['revoked']    = false;
				$r['revoked_at'] = null;
				self::save_all( $rows );
				return true;
			}
		}
		return false;
	}

	public static function hard_delete( $id ) {
		$rows = self::load_all();
		$before = count( $rows );
		$rows = array_values( array_filter( $rows, function( $r ) use ( $id ) { return ( $r['id'] ?? '' ) !== $id; } ) );
		if ( count( $rows ) === $before ) return false;
		self::save_all( $rows );
		return true;
	}

	public static function rotate( $id ) {
		$rec = self::find_by_id( $id );
		if ( ! $rec ) return new WP_Error( 'not_found', 'Key not found' );
		// Revoke the old, mint a new one with same label + tier.
		self::revoke( $id );
		return self::mint( (int) $rec['user_id'], $rec['label'] . ' (rotated)', $rec['tier'] );
	}

	/* ------------------------------------------------------------------
	 * Usage tracking — called by BT_API::resolve_auth on every keyed hit.
	 * ------------------------------------------------------------------ */

	public static function bump_usage( $id ) {
		$rows  = self::load_all();
		$today = gmdate( 'Y-m-d' );
		foreach ( $rows as &$r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				// Roll over the per-day counter if the date has changed.
				if ( ( $r['usage_day'] ?? '' ) !== $today ) {
					$r['usage_today'] = 0;
					$r['usage_day']   = $today;
				}
				$r['usage_today']++;
				$r['usage_total']++;
				$r['last_used']  = time();
				self::save_all( $rows );
				return array(
					'usage_today'  => $r['usage_today'],
					'usage_total'  => $r['usage_total'],
					'rate_per_day' => (int) ( $r['rate_per_day'] ?? self::tier_limits( $r['tier'] ?? 'public' )['per_day'] ),
					'rate_per_min' => (int) ( $r['rate_per_min'] ?? self::tier_limits( $r['tier'] ?? 'public' )['per_min'] ),
				);
			}
		}
		return null;
	}

	public static function run_daily_rollover() {
		$rows  = self::load_all();
		$today = gmdate( 'Y-m-d' );
		$dirty = false;
		foreach ( $rows as &$r ) {
			if ( ( $r['usage_day'] ?? '' ) !== $today ) {
				$r['usage_today'] = 0;
				$r['usage_day']   = $today;
				$dirty = true;
			}
		}
		if ( $dirty ) self::save_all( $rows );
	}

	/* ------------------------------------------------------------------
	 * REST routes (user-facing)
	 * ------------------------------------------------------------------ */

	public static function register_rest_routes() {
		$ns = 'blockticker/v1';

		register_rest_route( $ns, '/user/api-keys', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_create' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'label' => array( 'type' => 'string', 'required' => false ),
					'tier'  => array( 'type' => 'string', 'enum' => array( 'public', 'standard', 'premium' ), 'default' => 'standard' ),
				),
			),
		) );

		register_rest_route( $ns, '/user/api-keys/(?P<id>bt_[a-f0-9]{8})', array(
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'rest_delete' ),
				'permission_callback' => 'is_user_logged_in',
			),
		) );

		register_rest_route( $ns, '/user/api-keys/(?P<id>bt_[a-f0-9]{8})/rotate', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_rotate' ),
				'permission_callback' => 'is_user_logged_in',
			),
		) );
	}

	/** Convert a full record to a public-safe view (never returns the full key). */
	private static function public_view( $rec ) {
		$lim = self::tier_limits( $rec['tier'] ?? 'public' );
		return array(
			'id'           => $rec['id']           ?? '',
			'label'        => $rec['label']        ?? '',
			'tier'         => $rec['tier']         ?? 'public',
			'key_preview'  => ( $rec['id'] ?? '' ) . '_' . str_repeat( '•', 32 ),
			'rate_per_min' => (int) ( $rec['rate_per_min'] ?? $lim['per_min'] ),
			'rate_per_day' => (int) ( $rec['rate_per_day'] ?? $lim['per_day'] ),
			'created'      => (int) ( $rec['created']     ?? 0 ),
			'last_used'    => (int) ( $rec['last_used']   ?? 0 ),
			'usage_today'  => (int) ( $rec['usage_today'] ?? 0 ),
			'usage_total'  => (int) ( $rec['usage_total'] ?? 0 ),
			'revoked'      => ! empty( $rec['revoked'] ),
			'revoked_at'   => (int) ( $rec['revoked_at'] ?? 0 ),
		);
	}

	public static function rest_list( $req ) {
		$uid = get_current_user_id();
		$out = array();
		foreach ( self::load_all() as $r ) {
			if ( (int) ( $r['user_id'] ?? 0 ) !== $uid ) continue;
			$out[] = self::public_view( $r );
		}
		// Newest first.
		usort( $out, function( $a, $b ) { return $b['created'] <=> $a['created']; } );
		return rest_ensure_response( array( 'keys' => $out ) );
	}

	public static function rest_create( $req ) {
		$uid   = get_current_user_id();
		$label = sanitize_text_field( (string) $req->get_param( 'label' ) );
		$tier  = (string) $req->get_param( 'tier' );
		$rec   = self::mint( $uid, $label, $tier );
		if ( is_wp_error( $rec ) ) return $rec;

		// IMPORTANT: this is the ONLY time the full key is exposed via REST.
		$view         = self::public_view( $rec );
		$view['key']  = $rec['key'];
		$view['warn'] = 'Save this key now — it will not be shown again in plaintext.';
		return rest_ensure_response( $view );
	}

	public static function rest_delete( $req ) {
		$uid = get_current_user_id();
		$id  = (string) $req['id'];
		$rec = self::find_by_id( $id );
		if ( ! $rec || (int) ( $rec['user_id'] ?? 0 ) !== $uid ) {
			return new WP_Error( 'forbidden', 'Key does not exist or does not belong to you', array( 'status' => 404 ) );
		}
		self::revoke( $id );
		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	public static function rest_rotate( $req ) {
		$uid = get_current_user_id();
		$id  = (string) $req['id'];
		$rec = self::find_by_id( $id );
		if ( ! $rec || (int) ( $rec['user_id'] ?? 0 ) !== $uid ) {
			return new WP_Error( 'forbidden', 'Key does not exist or does not belong to you', array( 'status' => 404 ) );
		}
		$new = self::rotate( $id );
		if ( is_wp_error( $new ) ) return $new;
		$view         = self::public_view( $new );
		$view['key']  = $new['key'];
		$view['warn'] = 'Your previous key has been revoked. Save this new key now.';
		return rest_ensure_response( $view );
	}

	/* ------------------------------------------------------------------
	 * Admin menu + panel
	 * ------------------------------------------------------------------ */

	public static function register_admin_menu() {
		add_submenu_page(
			'fxlm-wizard',
			'API Keys',
			'🔑 API Keys',
			'manage_options',
			'bt-api-keys',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		$nonce = wp_create_nonce( 'bt_apikeys_admin' );

		$rows = self::load_all();
		$stats = array( 'total' => count( $rows ), 'active' => 0, 'revoked' => 0, 'usage_today' => 0, 'usage_total' => 0 );
		foreach ( $rows as $r ) {
			if ( ! empty( $r['revoked'] ) ) $stats['revoked']++; else $stats['active']++;
			$stats['usage_today'] += (int) ( $r['usage_today'] ?? 0 );
			$stats['usage_total'] += (int) ( $r['usage_total'] ?? 0 );
		}
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				🔑 BlockTicker — API Keys
				<span style="font-size:13px;font-weight:400;color:#888;background:#f0f0f0;padding:2px 8px;border-radius:0;">
					v<?php echo esc_html( BT_VERSION ); ?>
				</span>
			</h1>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:16px 0;">
				<div class="postbox" style="padding:12px;margin:0;"><div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;">Total keys</div><div style="font-size:22px;font-weight:700;"><?php echo (int) $stats['total']; ?></div></div>
				<div class="postbox" style="padding:12px;margin:0;"><div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;">Active</div><div style="font-size:22px;font-weight:700;color:#22c55e;"><?php echo (int) $stats['active']; ?></div></div>
				<div class="postbox" style="padding:12px;margin:0;"><div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;">Revoked</div><div style="font-size:22px;font-weight:700;color:#ef4444;"><?php echo (int) $stats['revoked']; ?></div></div>
				<div class="postbox" style="padding:12px;margin:0;"><div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;">Calls today</div><div style="font-size:22px;font-weight:700;"><?php echo number_format( $stats['usage_today'] ); ?></div></div>
				<div class="postbox" style="padding:12px;margin:0;"><div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;">Calls all-time</div><div style="font-size:22px;font-weight:700;"><?php echo number_format( $stats['usage_total'] ); ?></div></div>
			</div>

			<div class="postbox" style="margin-top:12px;">
				<div class="postbox-header"><h2 class="hndle" style="padding:12px 15px;">All API Keys</h2></div>
				<div class="inside" style="padding:0;">
					<div style="padding:10px 15px;border-bottom:1px solid #eee;display:flex;gap:8px;align-items:center;">
						<label><input type="checkbox" id="bt-ak-show-revoked"> Show revoked</label>
						<span style="color:#888;font-size:12px;margin-left:auto;">Sorted by last-used (newest first)</span>
					</div>
					<table class="wp-list-table widefat striped" id="bt-ak-admin-table">
						<thead>
							<tr>
								<th>Key ID</th><th>User</th><th>Label</th><th>Tier</th>
								<th>Today / Limit</th><th>Total</th><th>Last used</th><th>Status</th><th></th>
							</tr>
						</thead>
						<tbody>
							<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--bt-text-3);">Loading…</td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<script>
		(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var showRevoked = false;
			function fmtAge(ts){
				if(!ts)return '—';
				var s=Math.floor(Date.now()/1000)-ts;
				if(s<60)return s+'s ago';
				if(s<3600)return Math.floor(s/60)+'m ago';
				if(s<86400)return Math.floor(s/3600)+'h ago';
				return Math.floor(s/86400)+'d ago';
			}
			function userLink(id, name){ return id ? '<a href="user-edit.php?user_id='+id+'">'+name+'</a>' : '—'; }
			function tierBadge(t){
				var colors={public:'var(--bt-text-3)',standard:'#3b82f6',premium:'#a855f7'};
				return '<span style="background:'+(colors[t]||'var(--bt-text-3)')+';color:#fff;padding:2px 8px;border-radius:0;font-size:11px;">'+t+'</span>';
			}
			function load(){
				$.post(ajaxurl, { action:'bt_apikeys_admin_list', _nonce:nonce }, function(r){
					if(!r.success){ $('#bt-ak-admin-table tbody').html('<tr><td colspan=9 style="color:#ef4444;padding:20px;">'+(r.data||'error')+'</td></tr>'); return; }
					var rows = r.data.rows;
					var html = '';
					rows.filter(function(x){return showRevoked||!x.revoked;}).forEach(function(k){
						var usageBar = Math.min(100, Math.round((k.usage_today/k.rate_per_day)*100));
						var barColor = usageBar>80?'#ef4444':usageBar>50?'var(--bt-accent-warm)':'#22c55e';
						html += '<tr data-id="'+k.id+'" '+(k.revoked?'style="opacity:.55"':'')+'>'
							+ '<td><code>'+k.id+'</code></td>'
							+ '<td>'+userLink(k.user_id, k.user_name||'#'+k.user_id)+'</td>'
							+ '<td>'+$('<div/>').text(k.label||'—').html()+'</td>'
							+ '<td>'+tierBadge(k.tier)+'</td>'
							+ '<td><div style="display:flex;flex-direction:column;gap:2px;"><span style="font-size:12px;">'+k.usage_today.toLocaleString()+' / '+k.rate_per_day.toLocaleString()+'</span><div style="width:100px;height:4px;background:#e5e7eb;border-radius:2px;"><div style="width:'+usageBar+'%;height:100%;background:'+barColor+';border-radius:2px;"></div></div></div></td>'
							+ '<td>'+k.usage_total.toLocaleString()+'</td>'
							+ '<td>'+fmtAge(k.last_used)+'</td>'
							+ '<td>'+(k.revoked?'<span style="color:#ef4444;">Revoked</span>':'<span style="color:#22c55e;">Active</span>')+'</td>'
							+ '<td>'+(k.revoked
								?'<button class="button button-small bt-ak-restore">Restore</button> <button class="button button-small bt-ak-delete" style="color:#ef4444;">Delete</button>'
								:'<button class="button button-small bt-ak-revoke">Revoke</button>'
							)+'</td>'
							+ '</tr>';
					});
					if(!html) html = '<tr><td colspan=9 style="text-align:center;padding:20px;color:var(--bt-text-3);">No '+(showRevoked?'':'active ')+'keys yet.</td></tr>';
					$('#bt-ak-admin-table tbody').html(html);
				});
			}
			$('#bt-ak-show-revoked').on('change', function(){ showRevoked = this.checked; load(); });
			$(document).on('click', '.bt-ak-revoke', function(){
				var id = $(this).closest('tr').data('id');
				if(!confirm('Revoke '+id+'?')) return;
				$.post(ajaxurl, { action:'bt_apikeys_admin_revoke', _nonce:nonce, id:id }, function(){ load(); });
			});
			$(document).on('click', '.bt-ak-restore', function(){
				var id = $(this).closest('tr').data('id');
				$.post(ajaxurl, { action:'bt_apikeys_admin_restore', _nonce:nonce, id:id }, function(){ load(); });
			});
			$(document).on('click', '.bt-ak-delete', function(){
				var id = $(this).closest('tr').data('id');
				if(!confirm('PERMANENTLY delete '+id+'? This wipes usage history too.')) return;
				$.post(ajaxurl, { action:'bt_apikeys_admin_delete', _nonce:nonce, id:id }, function(){ load(); });
			});
			load();
		})(jQuery);
		</script>
		<?php
	}

	public static function ajax_admin_list() {
		check_ajax_referer( 'bt_apikeys_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$rows = self::load_all();
		$out  = array();
		foreach ( $rows as $r ) {
			$view = self::public_view( $r );
			$view['user_id']   = (int) ( $r['user_id'] ?? 0 );
			$u                 = $view['user_id'] ? get_userdata( $view['user_id'] ) : false;
			$view['user_name'] = $u ? $u->user_login : '';
			$out[] = $view;
		}
		usort( $out, function( $a, $b ) { return ( $b['last_used'] ?: $b['created'] ) <=> ( $a['last_used'] ?: $a['created'] ); } );
		wp_send_json_success( array( 'rows' => $out ) );
	}

	public static function ajax_admin_revoke() {
		check_ajax_referer( 'bt_apikeys_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
		self::revoke( $id );
		wp_send_json_success();
	}

	public static function ajax_admin_restore() {
		check_ajax_referer( 'bt_apikeys_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
		self::restore( $id );
		wp_send_json_success();
	}

	public static function ajax_admin_delete() {
		check_ajax_referer( 'bt_apikeys_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
		self::hard_delete( $id );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------
	 * User-facing shortcode: [bt_api_keys]
	 * ------------------------------------------------------------------ */

	public static function sc_user_api_keys( $atts ) {
		$a = shortcode_atts( array(
			'default_tier' => 'standard',
			'tiers'        => 'public,standard', // what tiers users can self-select
		), $atts );

		if ( ! is_user_logged_in() ) {
			return '<div class="bt-ak-guest">🔑 <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a> to create API keys.</div>';
		}

		$uid      = get_current_user_id();
		$rest_url = esc_url_raw( rest_url( 'blockticker/v1/user/api-keys' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );
		$tiers    = array_filter( array_map( 'trim', explode( ',', $a['tiers'] ) ) );
		if ( ! in_array( $a['default_tier'], $tiers, true ) ) $a['default_tier'] = $tiers[0] ?? 'public';

		$my_keys = array();
		foreach ( self::load_all() as $r ) {
			if ( (int) ( $r['user_id'] ?? 0 ) !== $uid ) continue;
			$my_keys[] = self::public_view( $r );
		}
		usort( $my_keys, function( $a, $b ) { return $b['created'] <=> $a['created']; } );

		ob_start(); ?>
		<div class="bt-ak-wrap" data-rest="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="bt-ak-header">
				<h2>🔑 Your API Keys</h2>
				<p>Use these keys to authenticate against the BlockTicker REST API. Send them as an <code>X-BT-API-Key</code> header on every request.</p>
			</div>

			<div class="bt-ak-create">
				<h3>Create a new key</h3>
				<div class="bt-ak-create-row">
					<input type="text" class="bt-ak-label" placeholder="Label (e.g. 'My dashboard', 'Python bot')" maxlength="80">
					<select class="bt-ak-tier">
						<?php foreach ( $tiers as $t ) : $lim = self::tier_limits( $t ); ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $a['default_tier'] ); ?>>
								<?php echo esc_html( ucfirst( $t ) . ' — ' . number_format( $lim['per_min'] ) . '/min · ' . number_format( $lim['per_day'] ) . '/day' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="bt-ak-create-btn">Create Key</button>
				</div>
				<div class="bt-ak-new-result"></div>
			</div>

			<div class="bt-ak-list">
				<h3>Existing keys (<span class="bt-ak-count"><?php echo count( $my_keys ); ?></span>)</h3>
				<?php if ( ! $my_keys ) : ?>
					<p class="bt-ak-empty">You haven't created any keys yet.</p>
				<?php else : ?>
					<table class="bt-ak-table">
						<thead>
							<tr><th>Label</th><th>Key</th><th>Tier</th><th>Usage today</th><th>Last used</th><th></th></tr>
						</thead>
						<tbody>
						<?php foreach ( $my_keys as $k ) :
							$usage_pct = $k['rate_per_day'] > 0 ? min( 100, round( ( $k['usage_today'] / $k['rate_per_day'] ) * 100 ) ) : 0;
						?>
							<tr data-id="<?php echo esc_attr( $k['id'] ); ?>" class="<?php echo $k['revoked'] ? 'bt-ak-revoked' : ''; ?>">
								<td><strong><?php echo esc_html( $k['label'] ); ?></strong></td>
								<td><code><?php echo esc_html( $k['key_preview'] ); ?></code></td>
								<td><span class="bt-ak-tier-<?php echo esc_attr( $k['tier'] ); ?>"><?php echo esc_html( ucfirst( $k['tier'] ) ); ?></span></td>
								<td>
									<?php echo number_format( $k['usage_today'] ); ?> / <?php echo number_format( $k['rate_per_day'] ); ?>
									<div class="bt-ak-bar"><div class="bt-ak-bar-fill" style="width:<?php echo (int) $usage_pct; ?>%;"></div></div>
								</td>
								<td><?php echo $k['last_used'] ? esc_html( human_time_diff( $k['last_used'] ) . ' ago' ) : '<em>never</em>'; ?></td>
								<td>
									<?php if ( $k['revoked'] ) : ?>
										<span class="bt-ak-status-revoked">Revoked</span>
									<?php else : ?>
										<button class="bt-ak-rotate" title="Issue a new secret for this label">↻ Rotate</button>
										<button class="bt-ak-revoke" title="Revoke this key">Revoke</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="bt-ak-docs-link">
				→ See the full API reference at <a href="<?php echo esc_url( home_url( '/api-docs/' ) ); ?>">interactive Swagger UI</a>.
			</div>
		</div>

		<?php echo self::inline_css(); ?>
		<?php echo self::inline_js(); ?>
		<?php
		return ob_get_clean();
	}

	private static function inline_css() {
		static $done = false;
		if ( $done ) return '';
		$done = true;
		return '<style>
.bt-ak-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--bt-text);background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:18px;margin:16px 0}
.bt-ak-guest{padding:24px;background:var(--bt-bg-elev);border:1px solid var(--bt-text-4);border-radius:0;color:var(--bt-text-2);text-align:center}
.bt-ak-guest a{color:var(--bt-accent);text-decoration:none;font-weight:600}
.bt-ak-header h2{margin:0 0 4px;color:var(--bt-text);font-size:20px}
.bt-ak-header p{margin:0 0 16px;color:var(--bt-text-2);font-size:13px}
.bt-ak-header code{background:#1e2535;color:var(--bt-accent);padding:1px 6px;border-radius:0;font-size:12px}
.bt-ak-create{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:14px;margin-bottom:16px}
.bt-ak-create h3,.bt-ak-list h3{margin:0 0 10px;font-size:14px;color:var(--bt-text)}
.bt-ak-create-row{display:grid;grid-template-columns:2fr 1.5fr auto;gap:8px}
.bt-ak-label,.bt-ak-tier{background:#0b0f1a;color:var(--bt-text);border:1px solid var(--bt-text-4);border-radius:0;padding:8px 10px;font-size:13px}
.bt-ak-create-btn{background:var(--bt-accent);color:#0b0f1a;border:0;border-radius:0;padding:0 14px;font-weight:700;cursor:pointer;font-size:13px}
.bt-ak-create-btn:hover{background:var(--bt-accent)}
.bt-ak-new-result{margin-top:12px;font-size:13px}
.bt-ak-new-key{background:#064e3b;border:1px solid #22c55e;border-radius:0;padding:14px;margin-top:12px}
.bt-ak-new-key h4{margin:0 0 6px;color:#a7f3d0;font-size:13px}
.bt-ak-new-key code{display:block;background:#0b0f1a;padding:10px;border-radius:0;font-family:monospace;color:#22c55e;font-size:13px;word-break:break-all;margin:8px 0;border:1px dashed #22c55e}
.bt-ak-new-key button{background:#22c55e;color:#0b0f1a;border:0;border-radius:0;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer}
.bt-ak-new-key .bt-ak-warn{color:#fbbf24;font-size:12px}
.bt-ak-table{width:100%;border-collapse:collapse;font-size:13px}
.bt-ak-table th,.bt-ak-table td{text-align:left;padding:10px;border-bottom:1px solid #1e2535}
.bt-ak-table th{background:var(--bt-bg-elev);color:var(--bt-text-2);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.bt-ak-table code{background:#1e2535;padding:2px 6px;border-radius:0;font-size:12px;color:var(--bt-text-2)}
.bt-ak-tier-public{background:var(--bt-text-3);color:#fff;padding:2px 8px;border-radius:0;font-size:11px}
.bt-ak-tier-standard{background:#3b82f6;color:#fff;padding:2px 8px;border-radius:0;font-size:11px}
.bt-ak-tier-premium{background:#a855f7;color:#fff;padding:2px 8px;border-radius:0;font-size:11px}
.bt-ak-bar{width:140px;height:4px;background:#1e2535;border-radius:2px;margin-top:3px}
.bt-ak-bar-fill{height:100%;background:#22c55e;border-radius:2px;transition:width .3s}
.bt-ak-revoked{opacity:.5}
.bt-ak-status-revoked{color:#ef4444;font-size:12px}
.bt-ak-rotate,.bt-ak-revoke{background:transparent;border:1px solid var(--bt-text-4);color:var(--bt-text-2);border-radius:0;padding:4px 10px;font-size:12px;cursor:pointer}
.bt-ak-rotate:hover{border-color:#3b82f6;color:#3b82f6}
.bt-ak-revoke:hover{border-color:#ef4444;color:#ef4444}
.bt-ak-empty{color:var(--bt-text-3);text-align:center;padding:24px;background:var(--bt-bg-elev);border-radius:0}
.bt-ak-docs-link{margin-top:14px;padding:10px 14px;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;font-size:13px;color:var(--bt-text-2)}
.bt-ak-docs-link a{color:var(--bt-accent);text-decoration:none}
@media(max-width:720px){.bt-ak-create-row{grid-template-columns:1fr}}
</style>';
	}

	private static function inline_js() {
		return "<script>(function(){
function onReady(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn):fn();}
onReady(function(){
	document.querySelectorAll('.bt-ak-wrap').forEach(function(root){
		if(root.dataset.btBound)return; root.dataset.btBound='1';
		var rest=root.dataset.rest, nonce=root.dataset.nonce;

		function post(path,body){ return fetch(rest+path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(body||{})}).then(function(r){return r.json();}); }
		function del(path){ return fetch(rest+path,{method:'DELETE',credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.json();}); }

		var out=root.querySelector('.bt-ak-new-result');
		function renderNewKey(k){
			out.innerHTML = '<div class=bt-ak-new-key>'
				+ '<h4>✓ New key created</h4>'
				+ '<div class=bt-ak-warn>⚠ ' + (k.warn||'Save this key now — it will not be shown again.') + '</div>'
				+ '<code class=bt-ak-plaintext>' + k.key + '</code>'
				+ '<button class=bt-ak-copy>📋 Copy</button>'
				+ '</div>';
			out.querySelector('.bt-ak-copy').addEventListener('click',function(){
				var ta=document.createElement('textarea');ta.value=k.key;document.body.appendChild(ta);ta.select();
				try{ document.execCommand('copy'); this.innerText='✓ Copied'; }catch(e){}
				document.body.removeChild(ta);
			});
		}

		root.querySelector('.bt-ak-create-btn').addEventListener('click', function(){
			var label=root.querySelector('.bt-ak-label').value.trim();
			var tier=root.querySelector('.bt-ak-tier').value;
			post('', {label:label, tier:tier}).then(function(r){
				if(r.code){ out.innerHTML='<div style=\"color:#ef4444\">✗ '+(r.message||'error')+'</div>'; return; }
				renderNewKey(r);
				setTimeout(function(){ location.reload(); }, 1500);
			});
		});

		root.addEventListener('click', function(e){
			var tr=e.target.closest('tr[data-id]'); if(!tr) return;
			var id=tr.dataset.id;
			if(e.target.classList.contains('bt-ak-revoke')){
				if(!confirm('Revoke this key? Active integrations will stop working immediately.')) return;
				del('/'+id).then(function(r){ if(r.success) location.reload(); else alert('Revoke failed'); });
			}
			if(e.target.classList.contains('bt-ak-rotate')){
				if(!confirm('Rotate this key? The old secret will be revoked and a new one issued.')) return;
				post('/'+id+'/rotate').then(function(r){ if(r.key){ renderNewKey(r); setTimeout(function(){ location.reload(); }, 1500); } else alert('Rotate failed'); });
			}
		});
	});
});
})();</script>";
	}
}
