<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Contact {

    public static function register_shortcodes() {
        add_shortcode( 'fxlm_contact_form', array( __CLASS__, 'sc_contact_form' ) );
        add_action( 'wp_ajax_fxlm_contact',        array( __CLASS__, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_fxlm_contact', array( __CLASS__, 'ajax_submit' ) );
    }

    public static function sc_contact_form( $atts ) {
        ob_start();
        
?>
        <div class="bt-pp-contact-form" id="bt-contact-form" style="max-width:640px">

          <div class="bt-pp-form-card">
            <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">👤</span>About you</div>
            <div class="bt-pp-form-row">
              <div class="bt-pp-field">
                <label>Name</label>
                <input type="text" id="bt-cf-name" placeholder="Your name or handle">
              </div>
              <div class="bt-pp-field">
                <label>Email <span class="req" style="color:#ef4444;margin-left:2px">*</span></label>
                <input type="email" id="bt-cf-email" placeholder="you@example.com" required>
              </div>
            </div>
          </div>

          <div class="bt-pp-form-card">
            <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">📌</span>Your enquiry</div>
            <div class="bt-pp-field">
              <label>Topic <span style="color:#ef4444;margin-left:2px">*</span></label>
              <select id="bt-cf-subject">
                <option value="General Inquiry">General question</option>
                <option value="Content correction">Content correction or tip</option>
                <option value="Advertising">Partnership or advertising</option>
                <option value="Bug Report">Data or technical issue</option>
                <option value="Press">Press &amp; media</option>
                <option value="GDPR">GDPR / Privacy request</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>

          <div class="bt-pp-form-card">
            <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">💬</span>Message</div>
            <div class="bt-pp-field">
              <label>Message <span style="color:#ef4444;margin-left:2px">*</span></label>
              <textarea id="bt-cf-message" rows="5" placeholder="Describe your enquiry in as much detail as you can&#x2026;" required></textarea>
            </div>
          </div>

          <!-- Honeypot anti-spam -->
          <div style="position:absolute;left:-9999px">
            <input type="text" id="bt-cf-hp" name="bt_hp" tabindex="-1" autocomplete="off">
          </div>

          <div class="bt-pp-form-footer">
            <div class="bt-pp-form-note">🔒 Sent securely. We never share your details.</div>
            <button class="bt-pp-submit" id="bt-cf-submit">Send Message →</button>
          </div>

          <div class="bt-cf-msg" id="bt-cf-msg" style="font-size:13px;margin-top:12px;min-height:20px;text-align:center"></div>
        </div>
        <script>
        document.getElementById('bt-cf-submit').addEventListener('click',function(e){
            e.preventDefault();
            var btn=this,msg=document.getElementById('bt-cf-msg');
            var hp=document.getElementById('bt-cf-hp').value;
            if(hp){msg.textContent='Spam detected.';msg.style.color='var(--bt-danger)';return;}
            var name=document.getElementById('bt-cf-name').value.trim();
            var email=document.getElementById('bt-cf-email').value.trim();
            var subj=document.getElementById('bt-cf-subject').value;
            var body=document.getElementById('bt-cf-message').value.trim();
            if(!name||!email||!body){msg.textContent='Please fill in all fields.';msg.style.color='var(--bt-danger)';return;}
            btn.disabled=true;btn.textContent='Sending...';msg.textContent='';
            var fd=new FormData();fd.append('action','fxlm_contact');fd.append('name',name);fd.append('email',email);fd.append('subject',subj);fd.append('message',body);
            fd.append('nonce',typeof fxlm_data!=='undefined'?fxlm_data.nonce:'');
            fetch((typeof fxlm_data!=='undefined'?fxlm_data.ajax_url:'<?php echo admin_url("admin-ajax.php"); ?>'),{method:'POST',body:fd})
            .then(function(r){return r.json()}).then(function(res){
                if(res.success){msg.textContent=res.data;msg.style.color='var(--bt-accent)';document.getElementById('bt-cf-name').value='';document.getElementById('bt-cf-email').value='';document.getElementById('bt-cf-message').value=''}
                else{msg.textContent=res.data||'Error sending message.';msg.style.color='var(--bt-danger)'}
                btn.disabled=false;btn.textContent='Send Message →';
            }).catch(function(){msg.textContent='Network error.';msg.style.color='var(--bt-danger)';btn.disabled=false;btn.textContent='Send Message →'});
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public static function ajax_submit() {
        // CSRF protection via nonce verification (OWASP recommendation)
        BT_Utils::verify_public_ajax( 'fxlm_prices' );

        // Honeypot anti-spam check (bot protection without CAPTCHA)
        if ( ! empty( $_POST['website'] ) ) {
            wp_send_json_success( 'Message sent.' ); // Silently discard spam
            return;
        }

        $name    = sanitize_text_field( $_POST['name'] ?? '' );
        $email   = sanitize_email( $_POST['email'] ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? 'General Inquiry' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( empty( $name ) || empty( $email ) || empty( $message ) ) {
            wp_send_json_error( 'Please fill in all required fields.' );
        }
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Please enter a valid email address.' );
        }
        // Message length limits
        if ( strlen( $name ) > 100 || strlen( $subject ) > 200 || strlen( $message ) > 5000 ) {
            wp_send_json_error( 'Input too long. Please shorten your message.' );
        }

        // Rate limit: max 3 submissions per IP per hour
        $ip  = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        $log = get_transient( 'bt_contact_' . md5( $ip ) );
        if ( $log && $log >= 3 ) {
            wp_send_json_error( 'Too many messages. Please try again later.' );
        }
        set_transient( 'bt_contact_' . md5( $ip ), ( $log ?: 0 ) + 1, HOUR_IN_SECONDS );

        // Send email to admin
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_option( 'bt_site_name', 'BlockTicker' );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        );

        $body  = '<h2>New Contact Message — ' . esc_html( $site_name ) . '</h2>';
        $body .= '<p><strong>From:</strong> ' . esc_html( $name ) . ' (' . esc_html( $email ) . ')</p>';
        $body .= '<p><strong>Subject:</strong> ' . esc_html( $subject ) . '</p>';
        $body .= '<p><strong>Message:</strong></p><p>' . nl2br( esc_html( $message ) ) . '</p>';
        $body .= '<hr><p style="color:#999;font-size:12px">Sent via ' . esc_html( $site_name ) . ' contact form at ' . current_time( 'mysql' ) . '</p>';

        $sent = wp_mail( $admin_email, '[' . $site_name . '] ' . $subject . ' — from ' . $name, $body, $headers );

        // Store in database
        $messages = get_option( 'bt_contact_messages', array() );
        array_unshift( $messages, array(
            'name'    => $name,
            'email'   => $email,
            'subject' => $subject,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
            'ip'      => $ip,
        ) );
        $messages = array_slice( $messages, 0, 200 );
        update_option( 'bt_contact_messages', $messages );

        if ( $sent ) {
            wp_send_json_success( 'Message sent! We\'ll get back to you soon.' );
        } else {
            wp_send_json_success( 'Message received and stored. Thank you!' );
        }
    }
}
