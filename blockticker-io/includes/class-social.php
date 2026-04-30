<?php
/**
 * BT_Social — Social Sharing 2.0.
 *
 * When a per-asset AI analysis post is created or refreshed (see
 * BT_AIBlog::upsert_asset_analysis_post, v112), this class:
 *
 *   1. Generates a compact 5-tweet X/Twitter thread specific to the asset,
 *      using the same live data context and storing it in post meta
 *      (_bt_asset_twitter_thread).
 *   2. Generates a Telegram channel message (MarkdownV2-safe) and stores it in
 *      post meta (_bt_asset_telegram_msg).
 *   3. Auto-publishes both — subject to per-platform toggles and a cooldown
 *      window (default 6h per symbol) — via the existing Twitter OAuth 1.0a
 *      helpers in BT_AIBlog and the Telegram Bot API.
 *   4. Logs all deliveries to post meta (_bt_social_log) for the admin log.
 *
 * Settings (wp_options):
 *   bt_social_x_enabled        '0'|'1'  auto-publish per-asset thread to X
 *   bt_social_tg_enabled       '0'|'1'  auto-publish per-asset message to Telegram
 *   bt_telegram_bot_token      string   Telegram bot token from @BotFather
 *   bt_telegram_chat_id        string   Channel ID (@handle or -100…) or user chat ID
 *   bt_social_cooldown_hours   int      Min hours between re-posts of the same symbol (default 6)
 *   bt_social_utm              string   UTM suffix appended to shared links (default 'utm_source=social&utm_medium=auto&utm_campaign=asset_analysis')
 *
 * Post meta written:
 *   _bt_asset_twitter_thread       array of tweet strings
 *   _bt_asset_telegram_msg         string (MarkdownV2)
 *   _bt_social_last_posted_{SYM}   unix ts (cooldown tracker, option-level)
 *   _bt_social_log                 array of {platform,status,ts,detail,id}
 *   _bt_social_twitter_ids         array of tweet IDs from last successful post
 *   _bt_social_telegram_msg_id     int message_id from last Telegram post
 *
 * @package BlockTicker
 * @since   113.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Social {

	const META_THREAD        = '_bt_asset_twitter_thread';
	const META_TG_MSG        = '_bt_asset_telegram_msg';
	const META_LOG           = '_bt_social_log';
	const META_TW_IDS        = '_bt_social_twitter_ids';
	const META_TG_ID         = '_bt_social_telegram_msg_id';
	const OPT_COOLDOWN_PREFIX = 'bt_social_cooldown_';  // + sym

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	public static function init() {
		// Fired by BT_AIBlog::upsert_asset_analysis_post at the end (v113).
		add_action( 'bt_asset_analysis_saved', array( __CLASS__, 'on_asset_analysis_saved' ), 10, 3 );

		// Admin AJAX.
		add_action( 'wp_ajax_bt_social_save_config',  array( __CLASS__, 'ajax_save_config' ) );
		add_action( 'wp_ajax_bt_social_test_x',       array( __CLASS__, 'ajax_test_x' ) );
		add_action( 'wp_ajax_bt_social_test_tg',      array( __CLASS__, 'ajax_test_tg' ) );
		add_action( 'wp_ajax_bt_social_retry_post',   array( __CLASS__, 'ajax_retry_post' ) );
		add_action( 'wp_ajax_bt_social_publish_now',  array( __CLASS__, 'ajax_publish_now' ) );

		// Inline share-buttons shortcode.
		add_shortcode( 'bt_social_share_buttons', array( __CLASS__, 'sc_share_buttons' ) );
	}

	public static function deactivate() {
		// No cron to clear — social uses the action-hook pipeline. Cooldown
		// options are left in place so a reactivation preserves recent state.
	}

	/* ------------------------------------------------------------------
	 * Event listener — fires when a new/refreshed asset analysis post is saved
	 * ------------------------------------------------------------------ */

	/**
	 * @param int    $post_id
	 * @param string $sym   Uppercase asset symbol, e.g. BTC or EUR/USD.
	 * @param string $name  Full asset name, e.g. "Bitcoin".
	 */
	public static function on_asset_analysis_saved( $post_id, $sym, $name ) {
		$post_id = (int) $post_id;
		$sym     = strtoupper( trim( (string) $sym ) );
		$name    = (string) $name;
		if ( ! $post_id || ! $sym ) return;

		// Cooldown check — same symbol cannot re-post for N hours.
		$cooldown = max( 1, (int) get_option( 'bt_social_cooldown_hours', 6 ) );
		$last     = (int) get_option( self::OPT_COOLDOWN_PREFIX . sanitize_key( $sym ), 0 );
		$cooling  = $last && ( time() - $last ) < ( $cooldown * HOUR_IN_SECONDS );

		$x_on  = get_option( 'bt_social_x_enabled',  '0' ) === '1';
		$tg_on = get_option( 'bt_social_tg_enabled', '0' ) === '1';

		if ( ! $x_on && ! $tg_on ) return;
		if ( $cooling ) {
			self::log( $post_id, 'skip', 'Cooldown active (' . $cooldown . 'h) for ' . $sym, '' );
			return;
		}

		// Build content once — assemble_asset_context is public in v113.
		if ( ! class_exists( 'BT_AIBlog' ) || ! method_exists( 'BT_AIBlog', 'assemble_asset_context' ) ) {
			self::log( $post_id, 'error', 'BT_AIBlog::assemble_asset_context unavailable', '' );
			return;
		}
		$ctx = BT_AIBlog::assemble_asset_context( $sym );
		if ( empty( $ctx ) || ! is_array( $ctx ) ) {
			self::log( $post_id, 'error', 'Empty context for ' . $sym, '' );
			return;
		}

		$post_url = get_permalink( $post_id );

		// Twitter thread.
		if ( $x_on ) {
			$thread = self::generate_asset_thread( $sym, $name, $ctx, $post_url );
			if ( ! empty( $thread ) ) {
				update_post_meta( $post_id, self::META_THREAD, $thread );
				$r = self::publish_to_twitter( $post_id, $thread );
				self::log( $post_id, $r['success'] ? 'ok' : 'error',
					'X thread: ' . ( $r['success'] ? count( $r['ids'] ) . ' tweets posted' : $r['error'] ),
					'x', $r['ids'] ?? array() );
			} else {
				self::log( $post_id, 'error', 'X thread generation returned empty', 'x' );
			}
		}

		// Telegram message.
		if ( $tg_on ) {
			$tg_msg = self::generate_telegram_message( $sym, $name, $ctx, $post_url );
			if ( ! empty( $tg_msg ) ) {
				update_post_meta( $post_id, self::META_TG_MSG, $tg_msg );
				$r = self::publish_to_telegram( $post_id, $tg_msg );
				self::log( $post_id, $r['success'] ? 'ok' : 'error',
					'Telegram: ' . ( $r['success'] ? 'msg #' . $r['message_id'] . ' sent' : $r['error'] ),
					'tg', $r['message_id'] ?? 0 );
			} else {
				self::log( $post_id, 'error', 'Telegram message generation returned empty', 'tg' );
			}
		}

		// Update cooldown tracker only if at least one platform succeeded.
		$log = get_post_meta( $post_id, self::META_LOG, true );
		if ( is_array( $log ) ) {
			foreach ( array_reverse( $log ) as $entry ) {
				if ( ( $entry['status'] ?? '' ) === 'ok' ) {
					update_option( self::OPT_COOLDOWN_PREFIX . sanitize_key( $sym ), time(), false );
					break;
				}
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Twitter thread generation (5 tweets, asset-specific)
	 * ------------------------------------------------------------------ */

	public static function generate_asset_thread( $sym, $name, $ctx, $post_url ) {
		if ( ! class_exists( 'BT_AIBlog' ) ) return array();
		if ( ! method_exists( 'BT_AIBlog', 'call_ai' ) ) return array();

		$vals      = self::extract_ctx_values( $ctx );
		$price     = $vals['price_s'];
		$chg24     = $vals['chg24_s'];
		$chg7d     = $vals['chg7d_s'];
		$hi7       = $vals['hi7_s'];
		$lo7       = $vals['lo7_s'];
		$fng_label = $vals['fng_label'];
		$fng_val   = $vals['fng_val'];
		$sent      = $vals['sent_s'];
		$headlines = array_slice( (array) ( $ctx['headlines'] ?? array() ), 0, 3 );
		$signals   = array_slice( (array) ( $ctx['signals']   ?? array() ), 0, 2 );

		$head_str = '';
		foreach ( $headlines as $h ) {
			if ( ! empty( $h['title'] ) ) $head_str .= '- ' . $h['title'] . "\n";
		}
		$sig_str = '';
		foreach ( $signals as $s ) {
			$ttl = $s['title'] ?? '';
			if ( ! $ttl ) continue;
			$dir = self::infer_direction( $ttl );
			$sig_str .= $dir ? '- [' . $dir . '] ' . $ttl . "\n" : '- ' . $ttl . "\n";
		}

		$handle     = get_option( 'bt_twitter_handle', '@blocktickerIO' );
		$site_name  = get_option( 'bt_site_name', 'BlockTicker' );
		$cashtag    = self::cashtag( $sym );

		$prompt = "You are writing a Twitter/X thread for {$handle}, a crypto & markets research account.\n"
			. "Produce a focused thread of EXACTLY 5 tweets about {$name} ({$sym}) using ONLY the live data below.\n"
			. "RULES:\n"
			. "- Each tweet MAX 250 characters (hard cap).\n"
			. "- Tweet 1 = HOOK: strongest number from the data (price move, level break, sentiment pivot).\n"
			. "- Tweet 2 = PRICE/TECHNICALS: current price, 24h change, 7d range, momentum read.\n"
			. "- Tweet 3 = SENTIMENT & FLOW: F&G ({$fng_label} {$fng_val}), news sentiment ({$sent}), 1-line interpretation.\n"
			. "- Tweet 4 = CATALYSTS: pick the most market-moving headline or signal from the data.\n"
			. "- Tweet 5 = OUTLOOK: 1 level to watch + 1 invalidation trigger. DO NOT include a link — the plugin appends one automatically.\n"
			. "- Use {$cashtag} once (tweet 1 or 2). Institutional tone. No retail slang. Max 1 emoji per tweet.\n"
			. "- OUTPUT FORMAT: 5 lines, each prefixed 'Tweet 1:', 'Tweet 2:', ... 'Tweet 5:'. No preamble.\n\n"
			. "LIVE DATA\n"
			. "Asset: {$name} ({$sym})\n"
			. "Price: {$price}   24h: {$chg24}   7d: {$chg7d}\n"
			. "7-day range: low {$lo7} / high {$hi7}\n"
			. "Fear & Greed: {$fng_label} ({$fng_val})\n"
			. "News sentiment (48h, symbol-filtered): {$sent}\n"
			. ( $head_str ? "\nRecent headlines:\n{$head_str}" : '' )
			. ( $sig_str  ? "\nActive signals:\n{$sig_str}"   : '' )
			. "\nSource: {$site_name}.\n";

		$raw    = BT_AIBlog::call_ai( $prompt );
		$thread = self::parse_thread( $raw );

		// Pad / trim to exactly 5 so the thread has a clean shape.
		$thread = array_slice( $thread, 0, 5 );

		// Append the post URL on the last tweet (with UTM) — only if it fits.
		if ( count( $thread ) >= 1 && $post_url ) {
			$utm = get_option( 'bt_social_utm', 'utm_source=social&utm_medium=auto&utm_campaign=asset_analysis' );
			$link = esc_url_raw( add_query_arg( self::utm_to_args( $utm ), $post_url ) );
			$last = $thread[ count( $thread ) - 1 ];
			// t.co wraps every link to 23 chars; plus leading space = 24.
			if ( BT_Utils::strlen_unicode( $last ) <= ( 280 - 24 ) ) {
				$thread[ count( $thread ) - 1 ] = rtrim( $last ) . ' ' . $link;
			} else {
				// Fallback: append as its own 6th tweet (still under the 12-cap).
				$thread[] = 'Full analysis: ' . $link;
			}
		}

		return $thread;
	}

	private static function parse_thread( $raw ) {
		if ( empty( $raw ) ) return array();
		$text   = wp_strip_all_tags( (string) $raw );
		$lines  = preg_split( '/\r\n|\r|\n/', $text );
		$tweets = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;
			$clean = preg_replace( '/^(tweet\s*\d+[:\.\)]|[0-9]+[\.\)\/])\s*/i', '', $line );
			$clean = trim( $clean );
			if ( BT_Utils::strlen_unicode( $clean ) < 10 ) continue;
			if ( BT_Utils::strlen_unicode( $clean ) > 275 ) {
				$clean = BT_Utils::substr_unicode( $clean, 0, 272 ) . '…';
			}
			$tweets[] = $clean;
			if ( count( $tweets ) >= 6 ) break; // 5 + optional link
		}
		return $tweets;
	}

	/* ------------------------------------------------------------------
	 * Twitter publisher — reuses existing OAuth 1.0a helpers in BT_AIBlog
	 * ------------------------------------------------------------------ */

	public static function publish_to_twitter( $post_id, $thread ) {
		if ( ! class_exists( 'BT_AIBlog' ) ) {
			return array( 'success' => false, 'error' => 'BT_AIBlog not loaded', 'ids' => array() );
		}
		if ( empty( $thread ) || ! is_array( $thread ) ) {
			return array( 'success' => false, 'error' => 'Empty thread', 'ids' => array() );
		}

		// BT_AIBlog::publish_first_tweets_to_twitter reads _bt_twitter_thread from
		// the post, so we swap the key temporarily, call the helper, and swap back.
		$prev_thread = get_post_meta( $post_id, '_bt_twitter_thread', true );
		update_post_meta( $post_id, '_bt_twitter_thread', $thread );

		$n      = count( $thread );
		$result = BT_AIBlog::publish_first_tweets_to_twitter( $post_id, $n );

		// Restore whatever the daily-report flow had stored (may be empty).
		if ( $prev_thread ) {
			update_post_meta( $post_id, '_bt_twitter_thread', $prev_thread );
		} else {
			delete_post_meta( $post_id, '_bt_twitter_thread' );
		}

		if ( ! empty( $result['success'] ) ) {
			update_post_meta( $post_id, self::META_TW_IDS, $result['ids'] );
			update_post_meta( $post_id, '_bt_social_twitter_posted_at', time() );
		}

		return $result;
	}

	/* ------------------------------------------------------------------
	 * Telegram message generation (MarkdownV2)
	 * ------------------------------------------------------------------ */

	public static function generate_telegram_message( $sym, $name, $ctx, $post_url ) {
		$vals      = self::extract_ctx_values( $ctx );
		$price     = $vals['price_s'];
		$chg24     = $vals['chg24_s'];
		$chg7d     = $vals['chg7d_s'];
		$hi7       = $vals['hi7_s'];
		$lo7       = $vals['lo7_s'];
		$fng_label = $vals['fng_label'];
		$fng_val   = $vals['fng_val'];
		$sent_f    = $vals['sent_f'];
		$sent_lbl  = self::sentiment_label( $sent_f );
		$site_name = get_option( 'bt_site_name', 'BlockTicker' );

		$emoji_trend = '';
		if ( $vals['chg24_f'] !== null ) {
			$emoji_trend = $vals['chg24_f'] >= 0 ? '📈' : '📉';
		}

		// Build raw text, then escape for MarkdownV2.
		$title   = "📊 {$name} ({$sym}) — Market Analysis";
		$line_p  = "💰 Price: {$price}  ({$chg24} / 24h)  {$emoji_trend}";
		$line_r  = "📐 7d range: {$lo7}  →  {$hi7}   ({$chg7d} / 7d)";
		$line_fg = "😨 Fear & Greed: {$fng_label} ({$fng_val})";
		$line_s  = sprintf( '🧠 News sentiment: %s (%+.2f)', $sent_lbl, $sent_f );

		$headlines = isset( $ctx['headlines'] ) && is_array( $ctx['headlines'] )
			? array_slice( $ctx['headlines'], 0, 3 )
			: array();
		$signals   = isset( $ctx['signals'] ) && is_array( $ctx['signals'] )
			? array_slice( $ctx['signals'], 0, 2 )
			: array();

		$h_block = '';
		if ( $headlines ) {
			$h_block .= "\n\n📰 *Top headlines*\n";
			foreach ( $headlines as $h ) {
				$t = trim( $h['title'] ?? '' );
				if ( $t === '' ) continue;
				// Trim long titles to keep message digestible.
				if ( BT_Utils::strlen_unicode( $t ) > 140 ) $t = BT_Utils::substr_unicode( $t, 0, 137 ) . '…';
				$h_block .= "• " . $t . "\n";
			}
		}

		$s_block = '';
		if ( $signals ) {
			$s_block .= "\n\n🎯 *Active signals*\n";
			foreach ( $signals as $s ) {
				$ttl = trim( $s['title'] ?? '' );
				if ( $ttl === '' ) continue;
				$dir   = self::infer_direction( $ttl );
				$arrow = $dir === 'LONG' ? '🟢' : ( $dir === 'SHORT' ? '🔴' : '⚪' );
				if ( BT_Utils::strlen_unicode( $ttl ) > 120 ) $ttl = BT_Utils::substr_unicode( $ttl, 0, 117 ) . '…';
				$s_block .= $arrow . ' ' . ( $dir ? '[' . $dir . '] ' : '' ) . $ttl . "\n";
			}
		}

		// Build the message with explicit MarkdownV2 in mind. Escape ONLY the
		// user-data/text portions, not the literal markdown syntax we control.
		$esc = function( $s ) { return self::tg_escape( $s ); };

		$out  = '*' . $esc( $title ) . '*' . "\n\n";
		$out .= $esc( $line_p ) . "\n";
		$out .= $esc( $line_r ) . "\n";
		$out .= $esc( $line_fg ) . "\n";
		$out .= $esc( $line_s );

		if ( $h_block ) {
			$parts = explode( "\n", $h_block );
			$re = '';
			foreach ( $parts as $p ) {
				if ( strpos( $p, '*Top headlines*' ) !== false ) {
					$re .= "\n\n📰 *Top headlines*";
				} elseif ( $p !== '' ) {
					$re .= "\n" . $esc( $p );
				}
			}
			$out .= $re;
		}
		if ( $s_block ) {
			$parts = explode( "\n", $s_block );
			$re = '';
			foreach ( $parts as $p ) {
				if ( strpos( $p, '*Active signals*' ) !== false ) {
					$re .= "\n\n🎯 *Active signals*";
				} elseif ( $p !== '' ) {
					$re .= "\n" . $esc( $p );
				}
			}
			$out .= $re;
		}

		if ( $post_url ) {
			$utm  = get_option( 'bt_social_utm', 'utm_source=social&utm_medium=auto&utm_campaign=asset_analysis' );
			$link = add_query_arg( self::utm_to_args( $utm ), $post_url );
			$out .= "\n\n[" . $esc( 'Read full analysis on ' . $site_name ) . '](' . $esc( $link ) . ')';
		}

		// Hard cap at 4000 chars (Telegram limit is 4096).
		if ( BT_Utils::strlen_unicode( $out ) > 4000 ) {
			$out = BT_Utils::substr_unicode( $out, 0, 3997 ) . '…';
		}

		return $out;
	}

	/**
	 * Escape a string for Telegram MarkdownV2.
	 * https://core.telegram.org/bots/api#markdownv2-style
	 */
	private static function tg_escape( $s ) {
		$specials = array( '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!' );
		$escaped  = '';
		$len      = BT_Utils::strlen_unicode( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = BT_Utils::substr_unicode( $s, $i, 1 );
			if ( in_array( $ch, $specials, true ) ) {
				$escaped .= '\\' . $ch;
			} else {
				$escaped .= $ch;
			}
		}
		return $escaped;
	}

	/* ------------------------------------------------------------------
	 * Telegram publisher — sendMessage via Bot API
	 * ------------------------------------------------------------------ */

	public static function publish_to_telegram( $post_id, $markdown ) {
		$token   = trim( (string) get_option( 'bt_telegram_bot_token', '' ) );
		$chat_id = trim( (string) get_option( 'bt_telegram_chat_id',   '' ) );
		if ( ! $token || ! $chat_id ) {
			return array( 'success' => false, 'error' => 'Telegram bot token or chat ID missing', 'message_id' => 0 );
		}

		$url  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage';
		$body = array(
			'chat_id'                  => $chat_id,
			'text'                     => $markdown,
			'parse_mode'               => 'MarkdownV2',
			'disable_web_page_preview' => false,
		);

		$r = wp_remote_post( $url, array(
			'timeout' => 15,
			'body'    => $body,
		) );

		if ( is_wp_error( $r ) ) {
			return array( 'success' => false, 'error' => 'HTTP error: ' . $r->get_error_message(), 'message_id' => 0 );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$json = json_decode( wp_remote_retrieve_body( $r ), true );

		if ( $code !== 200 || empty( $json['ok'] ) ) {
			$desc = $json['description'] ?? ( 'HTTP ' . $code );
			return array( 'success' => false, 'error' => 'Telegram: ' . $desc, 'message_id' => 0 );
		}

		$mid = (int) ( $json['result']['message_id'] ?? 0 );
		if ( $post_id ) {
			update_post_meta( $post_id, self::META_TG_ID, $mid );
			update_post_meta( $post_id, '_bt_social_telegram_posted_at', time() );
		}
		return array( 'success' => true, 'error' => null, 'message_id' => $mid );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	public static function fmt_price( $v ) {
		$v = (float) $v;
		if ( $v >= 1000 )   return '$' . number_format( $v, 0 );
		if ( $v >= 1 )      return '$' . number_format( $v, 2 );
		if ( $v >= 0.01 )   return '$' . number_format( $v, 4 );
		if ( $v > 0 )       return '$' . rtrim( rtrim( sprintf( '%.8f', $v ), '0' ), '.' );
		return '$0';
	}

	/**
	 * Normalise the context returned by BT_AIBlog::assemble_asset_context into a
	 * single flat value bag for message builders. Keys are pre-formatted strings
	 * (suffixed _s) plus raw floats for conditional logic (_f).
	 */
	private static function extract_ctx_values( $ctx ) {
		$price_f = isset( $ctx['price_usd'] ) && $ctx['price_usd'] !== null ? (float) $ctx['price_usd'] : null;
		$chg24_f = isset( $ctx['chg_24h'] )  && $ctx['chg_24h']  !== null ? (float) $ctx['chg_24h']  : null;
		$chg7d_f = isset( $ctx['chg_7d'] )   && $ctx['chg_7d']   !== null ? (float) $ctx['chg_7d']   : null;

		$hist = isset( $ctx['hist_summary'] ) && is_array( $ctx['hist_summary'] ) ? $ctx['hist_summary'] : array();
		$lo_f = isset( $hist['low_7d'] )  && $hist['low_7d']  !== null ? (float) $hist['low_7d']  : null;
		$hi_f = isset( $hist['high_7d'] ) && $hist['high_7d'] !== null ? (float) $hist['high_7d'] : null;

		$fng     = isset( $ctx['fng'] ) && is_array( $ctx['fng'] ) ? $ctx['fng'] : array();
		$fng_val = isset( $fng['value'] ) ? (int) $fng['value'] : 0;
		$fng_lbl = isset( $fng['value_classification'] ) ? (string) $fng['value_classification'] : 'n/a';

		$mood   = isset( $ctx['mood'] ) && is_array( $ctx['mood'] ) ? $ctx['mood'] : array();
		$sent_f = isset( $mood['score'] ) ? (float) $mood['score'] : 0.0;

		return array(
			'price_f'   => $price_f,
			'price_s'   => $price_f !== null ? self::fmt_price( $price_f ) : 'n/a',
			'chg24_f'   => $chg24_f,
			'chg24_s'   => $chg24_f !== null ? sprintf( '%+.2f%%', $chg24_f ) : 'n/a',
			'chg7d_f'   => $chg7d_f,
			'chg7d_s'   => $chg7d_f !== null ? sprintf( '%+.2f%%', $chg7d_f ) : 'n/a',
			'lo_f'      => $lo_f,
			'lo7_s'     => $lo_f !== null ? self::fmt_price( $lo_f ) : 'n/a',
			'hi_f'      => $hi_f,
			'hi7_s'     => $hi_f !== null ? self::fmt_price( $hi_f ) : 'n/a',
			'fng_val'   => $fng_val,
			'fng_label' => $fng_lbl,
			'sent_f'    => $sent_f,
			'sent_s'    => sprintf( '%+.2f', $sent_f ),
		);
	}

	/** Crude direction inference from a signal title when the feed doesn't carry one. */
	private static function infer_direction( $title ) {
		$t = strtolower( (string) $title );
		$long  = array( 'long', 'buy', 'bullish', 'breakout up', 'accumulat' );
		$short = array( 'short', 'sell', 'bearish', 'breakdown', 'distribut' );
		foreach ( $long as $w )  if ( strpos( $t, $w ) !== false ) return 'LONG';
		foreach ( $short as $w ) if ( strpos( $t, $w ) !== false ) return 'SHORT';
		return '';
	}

	public static function cashtag( $sym ) {
		$sym = strtoupper( trim( (string) $sym ) );
		// Forex pairs (EUR/USD) become $EUR.
		if ( strpos( $sym, '/' ) !== false ) {
			$parts = explode( '/', $sym );
			return '$' . $parts[0];
		}
		// Alphanumeric only, max 6 chars for cashtag cleanliness.
		$sym = preg_replace( '/[^A-Z0-9]/', '', $sym );
		if ( $sym === '' ) return '';
		return '$' . substr( $sym, 0, 6 );
	}

	public static function sentiment_label( $s ) {
		$s = (float) $s;
		if ( $s >=  0.60 ) return 'Extreme Greed';
		if ( $s >=  0.25 ) return 'Greed';
		if ( $s >= -0.24 ) return 'Neutral';
		if ( $s >= -0.59 ) return 'Fear';
		return 'Extreme Fear';
	}

	private static function utm_to_args( $utm ) {
		$args = array();
		$utm  = trim( (string) $utm );
		if ( $utm === '' ) return $args;
		parse_str( ltrim( $utm, '?&' ), $args );
		return is_array( $args ) ? $args : array();
	}

	private static function log( $post_id, $status, $detail, $platform = '', $ids = null ) {
		$log   = get_post_meta( $post_id, self::META_LOG, true );
		if ( ! is_array( $log ) ) $log = array();
		$log[] = array(
			'ts'       => time(),
			'status'   => $status,   // 'ok' | 'error' | 'skip'
			'platform' => $platform, // 'x' | 'tg' | ''
			'detail'   => $detail,
			'ids'      => $ids,
		);
		// Keep last 40 entries per post.
		if ( count( $log ) > 40 ) $log = array_slice( $log, -40 );
		update_post_meta( $post_id, self::META_LOG, $log );
	}

	/* ------------------------------------------------------------------
	 * Shortcode: [bt_social_share_buttons symbol="BTC"]
	 * ------------------------------------------------------------------ */

	public static function sc_share_buttons( $atts ) {
		$a = shortcode_atts( array(
			'symbol' => '',
			'title'  => '',
			'url'    => '',
		), $atts );
		$sym   = strtoupper( sanitize_text_field( $a['symbol'] ) );
		$url   = $a['url'] ? esc_url( $a['url'] ) : esc_url( self::current_permalink() );
		$title = $a['title'] ? $a['title'] : ( $sym ? "$sym market analysis" : get_the_title() );

		// X / Twitter intent URL
		$tw_text = rawurlencode( $title . ( $sym ? '  ' . self::cashtag( $sym ) : '' ) );
		$tw_url  = 'https://twitter.com/intent/tweet?text=' . $tw_text . '&url=' . rawurlencode( $url );

		// Telegram share URL
		$tg_url = 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title );

		// LinkedIn share URL
		$li_url = 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url );

		ob_start(); ?>
		<div class="bt-social-share" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0;">
			<span style="font-size:13px;color:var(--bt-text-2);">Share:</span>
			<a href="<?php echo esc_url( $tw_url ); ?>" target="_blank" rel="noopener" aria-label="Share on X"
				style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:0;background:var(--bt-bg-elev);color:var(--bt-text);font-size:13px;text-decoration:none;border:1px solid #1e2535;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2H21l-6.49 7.41L22 22h-6.828l-4.754-6.22L4.8 22H2.04l6.95-7.93L2 2h6.914l4.34 5.72L18.244 2Zm-1.196 18h1.73L7.04 4H5.2l11.848 16Z"/></svg>
				<?php echo $sym ? esc_html( 'Post ' . self::cashtag( $sym ) ) : 'Post'; ?>
			</a>
			<a href="<?php echo esc_url( $tg_url ); ?>" target="_blank" rel="noopener" aria-label="Share on Telegram"
				style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:0;background:#229ED9;color:#fff;font-size:13px;text-decoration:none;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.997 15.196 9.83 18.58c.24 0 .344-.104.469-.228l1.127-1.076 2.336 1.71c.428.236.732.112.848-.397l1.536-7.19h.001c.138-.634-.229-.882-.647-.727L6.44 13.51c-.616.24-.606.586-.105.742l2.334.727L14.75 10.6c.256-.17.49-.075.298.095"/></svg>
				Telegram
			</a>
			<a href="<?php echo esc_url( $li_url ); ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn"
				style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:0;background:#0A66C2;color:#fff;font-size:13px;text-decoration:none;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5ZM3 9h4v12H3V9Zm7 0h3.8v1.7h.1c.5-1 1.9-2 3.9-2 4.2 0 5 2.8 5 6.4V21h-4v-5.4c0-1.3 0-3-1.8-3-1.9 0-2.2 1.4-2.2 2.9V21h-4V9Z"/></svg>
				LinkedIn
			</a>
			<button type="button" class="bt-social-copy" data-url="<?php echo esc_attr( $url ); ?>"
				style="padding:6px 12px;border-radius:0;background:#1e2535;color:var(--bt-text);font-size:13px;border:1px solid var(--bt-text-4);cursor:pointer;">
				📋 Copy link
			</button>
		</div>
		<script>
		document.querySelectorAll('.bt-social-copy').forEach(function(btn){
			if (btn.dataset.btBound) return; btn.dataset.btBound = '1';
			btn.addEventListener('click', function(){
				var u = this.dataset.url;
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(u).then(function(){ btn.innerText = '✓ Copied'; setTimeout(function(){ btn.innerText = '📋 Copy link'; }, 2000); });
				} else {
					var ta = document.createElement('textarea'); ta.value = u; document.body.appendChild(ta); ta.select();
					try { document.execCommand('copy'); btn.innerText = '✓ Copied'; setTimeout(function(){ btn.innerText = '📋 Copy link'; }, 2000); } catch(e){}
					document.body.removeChild(ta);
				}
			});
		});
		</script>
		<?php
		return ob_get_clean();
	}

	private static function current_permalink() {
		if ( is_singular() ) return get_permalink();
		return home_url( add_query_arg( null, null ) );
	}

	/* ------------------------------------------------------------------
	 * Admin AJAX handlers
	 * ------------------------------------------------------------------ */

	public static function ajax_save_config() {
		check_ajax_referer( 'bt_db_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$x_on   = ! empty( $_POST['x_enabled'] )  ? '1' : '0';
		$tg_on  = ! empty( $_POST['tg_enabled'] ) ? '1' : '0';
		$token  = sanitize_text_field( wp_unslash( $_POST['bot_token'] ?? '' ) );
		$chat   = sanitize_text_field( wp_unslash( $_POST['chat_id']   ?? '' ) );
		$cool   = max( 1, min( 168, (int) ( $_POST['cooldown'] ?? 6 ) ) );

		update_option( 'bt_social_x_enabled',      $x_on );
		update_option( 'bt_social_tg_enabled',     $tg_on );
		if ( $token !== '' ) update_option( 'bt_telegram_bot_token', $token );
		if ( $chat  !== '' ) update_option( 'bt_telegram_chat_id',   $chat );
		update_option( 'bt_social_cooldown_hours', $cool );

		wp_send_json_success( array( 'saved' => true ) );
	}

	public static function ajax_test_x() {
		check_ajax_referer( 'bt_db_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$txt    = '🧪 BlockTicker X integration test — ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
		$thread = array( $txt );

		// Create a throwaway draft to carry the thread meta (Twitter API needs a post id for meta).
		$post_id = wp_insert_post( array(
			'post_title'   => 'BT Social Test — ' . gmdate( 'Y-m-d H:i:s' ),
			'post_content' => $txt,
			'post_status'  => 'draft',
			'post_type'    => 'post',
		), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) wp_send_json_error( 'Could not create test draft' );

		$r = self::publish_to_twitter( $post_id, $thread );

		// Clean up draft unless it succeeded (keep for reference).
		if ( empty( $r['success'] ) ) wp_delete_post( $post_id, true );

		if ( ! empty( $r['success'] ) ) {
			wp_send_json_success( array( 'message' => 'Posted 1 test tweet', 'ids' => $r['ids'] ) );
		}
		wp_send_json_error( $r['error'] ?? 'Unknown error' );
	}

	public static function ajax_test_tg() {
		check_ajax_referer( 'bt_db_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$site = get_option( 'bt_site_name', 'BlockTicker' );
		$txt  = '*' . self::tg_escape( $site . ' — Telegram integration test' ) . '*' . "\n"
			. self::tg_escape( 'If you can read this, the bot token and chat ID are valid.' ) . "\n"
			. self::tg_escape( gmdate( 'Y-m-d H:i' ) . ' UTC' );

		$r = self::publish_to_telegram( 0, $txt );
		if ( ! empty( $r['success'] ) ) {
			wp_send_json_success( array( 'message' => 'Message #' . $r['message_id'] . ' sent' ) );
		}
		wp_send_json_error( $r['error'] ?? 'Unknown error' );
	}

	public static function ajax_retry_post() {
		check_ajax_referer( 'bt_db_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'post_id required' );

		$post = get_post( $post_id );
		if ( ! $post ) wp_send_json_error( 'Post not found' );

		// Derive the symbol from the slug: bt-analysis-{sym}
		$slug = $post->post_name;
		$sym  = '';
		if ( strpos( $slug, 'bt-analysis-' ) === 0 ) {
			$sym = strtoupper( substr( $slug, strlen( 'bt-analysis-' ) ) );
			$sym = str_replace( '-', '/', $sym ); // forex pairs
		}
		if ( ! $sym ) wp_send_json_error( 'Could not determine symbol for post' );

		// Bypass cooldown for manual retry: clear tracker for this symbol.
		delete_option( self::OPT_COOLDOWN_PREFIX . sanitize_key( $sym ) );

		// Extract name from post title ("Bitcoin (BTC) — AI Market Analysis — …")
		$name = trim( preg_replace( '/\s*\([^)]*\).*/', '', $post->post_title ) );
		if ( $name === '' ) $name = $sym;

		self::on_asset_analysis_saved( $post_id, $sym, $name );

		wp_send_json_success( array( 'message' => 'Retried ' . $sym ) );
	}

	public static function ajax_publish_now() {
		check_ajax_referer( 'bt_db_admin', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$sym = strtoupper( sanitize_text_field( wp_unslash( $_POST['symbol'] ?? 'BTC' ) ) );
		if ( ! class_exists( 'BT_AIBlog' ) || ! method_exists( 'BT_AIBlog', 'generate_asset_analysis' ) ) {
			wp_send_json_error( 'BT_AIBlog::generate_asset_analysis unavailable' );
		}
		$res = BT_AIBlog::generate_asset_analysis( $sym, true );
		if ( ! empty( $res['error'] ) ) wp_send_json_error( $res['error'] );
		// Analysis save fires bt_asset_analysis_saved which triggers us. Return the log.
		$log = get_post_meta( $res['post_id'], self::META_LOG, true );
		wp_send_json_success( array( 'message' => 'Analysis regenerated; share pipeline fired', 'log' => $log ?: array() ) );
	}

	/* ------------------------------------------------------------------
	 * Admin panel HTML
	 * ------------------------------------------------------------------ */

	public static function admin_panel_html() {
		$x_on      = get_option( 'bt_social_x_enabled',  '0' ) === '1';
		$tg_on     = get_option( 'bt_social_tg_enabled', '0' ) === '1';
		$token_set = (bool) get_option( 'bt_telegram_bot_token', '' );
		$chat_id   = get_option( 'bt_telegram_chat_id', '' );
		$cooldown  = (int) get_option( 'bt_social_cooldown_hours', 6 );

		$tw_creds_set = get_option( 'bt_twitter_api_key', '' )
			&& get_option( 'bt_twitter_api_secret', '' )
			&& get_option( 'bt_twitter_access_token', '' )
			&& get_option( 'bt_twitter_access_token_secret', '' );

		// Recent asset-analysis posts with social log.
		$recent = get_posts( array(
			'post_type'      => 'post',
			'posts_per_page' => 10,
			'name__like'     => 'bt-analysis-',
			'meta_query'     => array(
				array( 'key' => self::META_LOG, 'compare' => 'EXISTS' ),
			),
			'orderby' => 'modified',
			'order'   => 'DESC',
		) );
		// WP doesn't support name__like natively; filter in PHP:
		$all = get_posts( array(
			'post_type'      => 'post',
			'posts_per_page' => 30,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'post_status'    => array( 'publish', 'draft', 'pending' ),
		) );
		$recent = array();
		foreach ( $all as $p ) {
			if ( strpos( $p->post_name, 'bt-analysis-' ) === 0 ) {
				$log = get_post_meta( $p->ID, self::META_LOG, true );
				if ( is_array( $log ) && $log ) {
					$p->_log = $log;
					$recent[] = $p;
					if ( count( $recent ) >= 10 ) break;
				}
			}
		}

		ob_start(); ?>
		<div class="postbox" id="bt-social-panel">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:12px 15px;font-size:14px;">
					🔀 Social Auto-Share
					<span style="font-size:11px;font-weight:400;color:#888;margin-left:8px;">v113 — X + Telegram</span>
				</h2>
			</div>
			<div class="inside" style="padding:12px 15px;">
				<p style="margin:0 0 10px;color:var(--bt-text-3);font-size:12px;">
					When a per-asset AI analysis is saved (auto-generated or via <code>[bt_ai_analysis_v2]</code>),
					a 5-tweet X thread and a Telegram message are generated and published — respecting a per-symbol cooldown.
				</p>

				<table class="form-table" style="margin:0;">
					<tbody>
						<tr>
							<th style="padding:6px 0;width:140px;">X / Twitter</th>
							<td style="padding:6px 0;">
								<label><input type="checkbox" id="bt-sc-x" <?php checked( $x_on ); ?>> Auto-publish thread</label>
								<div style="font-size:11px;color:<?php echo $tw_creds_set ? '#22c55e' : '#ef4444'; ?>;">
									<?php echo $tw_creds_set
										? '✓ API credentials configured'
										: '✗ Twitter API credentials missing — set them in BlockTicker → AI Blog Generator → AI Analysis tab.'; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 0;">Telegram</th>
							<td style="padding:6px 0;">
								<label><input type="checkbox" id="bt-sc-tg" <?php checked( $tg_on ); ?>> Auto-publish message</label>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 0;">Bot token</th>
							<td style="padding:6px 0;">
								<input type="password" id="bt-sc-token" class="regular-text"
									placeholder="<?php echo $token_set ? '•••••••• (set — leave blank to keep)' : '123456:ABC-DEF1234...'; ?>"
									style="width:100%;max-width:360px;">
								<div style="font-size:11px;color:var(--bt-text-3);margin-top:2px;">Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, then copy the token here.</div>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 0;">Chat ID</th>
							<td style="padding:6px 0;">
								<input type="text" id="bt-sc-chat" class="regular-text"
									value="<?php echo esc_attr( $chat_id ); ?>"
									placeholder="@your_channel  or  -1001234567890"
									style="width:100%;max-width:360px;">
								<div style="font-size:11px;color:var(--bt-text-3);margin-top:2px;">Public channel: use <code>@handle</code>. Private channel: use the numeric ID starting <code>-100</code>.</div>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 0;">Cooldown</th>
							<td style="padding:6px 0;">
								<input type="number" id="bt-sc-cool" value="<?php echo esc_attr( $cooldown ); ?>" min="1" max="168" style="width:70px;"> hours per symbol
							</td>
						</tr>
					</tbody>
				</table>

				<p style="margin:10px 0 0;">
					<button type="button" class="button button-primary" id="bt-sc-save">Save</button>
					<button type="button" class="button" id="bt-sc-test-x" style="margin-left:6px;">🧪 Test X</button>
					<button type="button" class="button" id="bt-sc-test-tg" style="margin-left:6px;">🧪 Test Telegram</button>
					<span id="bt-sc-msg" style="margin-left:10px;font-size:12px;"></span>
				</p>

				<details style="margin-top:12px;">
					<summary style="cursor:pointer;font-weight:600;">📜 Recent deliveries (<?php echo count( $recent ); ?>)</summary>
					<div style="margin-top:8px;max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:0;padding:8px;background:#f9fafb;">
					<?php if ( ! $recent ) : ?>
						<p style="margin:0;color:var(--bt-text-3);font-size:12px;">No deliveries yet. When an asset analysis is generated with auto-publish on, it'll appear here.</p>
					<?php else : foreach ( $recent as $p ) :
						$sym = '';
						if ( strpos( $p->post_name, 'bt-analysis-' ) === 0 ) $sym = strtoupper( substr( $p->post_name, strlen( 'bt-analysis-' ) ) );
						$log_rows = array_reverse( (array) $p->_log );
						$latest   = $log_rows[0] ?? null;
						$status_dot = '⏳';
						if ( $latest ) {
							if ( $latest['status'] === 'ok' )    $status_dot = '✅';
							if ( $latest['status'] === 'error' ) $status_dot = '❌';
							if ( $latest['status'] === 'skip' )  $status_dot = '⏸';
						}
					?>
						<div style="border-bottom:1px solid #e5e7eb;padding:6px 0;font-size:12px;">
							<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
								<div>
									<strong><?php echo esc_html( $sym ); ?></strong>
									<span style="color:var(--bt-text-3);"><?php echo $status_dot; ?> <?php echo esc_html( $latest ? $latest['detail'] : '—' ); ?></span>
								</div>
								<div style="display:flex;gap:4px;">
									<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" target="_blank" class="button-link" style="font-size:11px;">view</a>
									<button type="button" class="button button-small bt-sc-retry" data-pid="<?php echo (int) $p->ID; ?>" style="font-size:11px;line-height:1;">↻ retry</button>
								</div>
							</div>
							<div style="color:var(--bt-text-2);font-size:11px;margin-top:2px;">
								<?php echo esc_html( human_time_diff( $latest['ts'] ?? time() ) . ' ago' ); ?>
								<?php if ( count( $log_rows ) > 1 ) : ?>
									 · <?php echo count( $log_rows ); ?> attempts total
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
					</div>
				</details>
			</div>
		</div>
		<script>
		(function($){
			if ( window._btSocialBound ) return; window._btSocialBound = true;
			var n = jQuery( '#_nonce' ).val() || '<?php echo esc_js( wp_create_nonce( 'bt_db_admin' ) ); ?>';
			function msg(t, ok){ $('#bt-sc-msg').css('color', ok ? '#22c55e' : '#ef4444').text(t); setTimeout(function(){ $('#bt-sc-msg').text(''); }, 5000); }
			$('#bt-sc-save').on('click', function(){
				$.post(ajaxurl, {
					action:'bt_social_save_config', _nonce:n,
					x_enabled:  $('#bt-sc-x').is(':checked')  ? 1 : 0,
					tg_enabled: $('#bt-sc-tg').is(':checked') ? 1 : 0,
					bot_token:  $('#bt-sc-token').val(),
					chat_id:    $('#bt-sc-chat').val(),
					cooldown:   $('#bt-sc-cool').val(),
				}, function(r){ msg(r.success ? '✓ Saved' : ('✗ ' + (r.data || 'error')), r.success); });
			});
			$('#bt-sc-test-x').on('click', function(){
				msg('Sending test tweet…', true);
				$.post(ajaxurl, { action:'bt_social_test_x', _nonce:n }, function(r){
					msg(r.success ? '✓ ' + (r.data && r.data.message ? r.data.message : 'sent') : ('✗ ' + (r.data || 'error')), r.success);
				});
			});
			$('#bt-sc-test-tg').on('click', function(){
				msg('Sending test message…', true);
				$.post(ajaxurl, { action:'bt_social_test_tg', _nonce:n }, function(r){
					msg(r.success ? '✓ ' + (r.data && r.data.message ? r.data.message : 'sent') : ('✗ ' + (r.data || 'error')), r.success);
				});
			});
			$(document).on('click', '.bt-sc-retry', function(){
				var b = $(this); var pid = b.data('pid');
				b.prop('disabled', true).text('…');
				$.post(ajaxurl, { action:'bt_social_retry_post', _nonce:n, post_id: pid }, function(r){
					msg(r.success ? '✓ ' + r.data.message : ('✗ ' + (r.data || 'error')), r.success);
					b.prop('disabled', false).text('↻ retry');
				});
			});
		})(jQuery);
		</script>
		<?php
		return ob_get_clean();
	}
}
