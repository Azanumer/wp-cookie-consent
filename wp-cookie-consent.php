<?php
/**
 * Plugin Name: WP Cookie Consent
 * Description: Lightweight, dependency-free GDPR-style cookie consent banner with category toggles (necessary / analytics / marketing) and deferred script blocking until consent.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCC_OPTION', 'wcc_settings' );
define( 'WCC_COOKIE', 'wcc_consent' );

function wcc_defaults() {
	return array(
		'enabled'       => 1,
		'banner_title'  => 'We value your privacy',
		'banner_text'   => 'We use cookies to improve your experience, analyze traffic, and show relevant content. Choose what you allow below.',
		'position'      => 'bottom',   // bottom | top
		'accept_label'  => 'Accept all',
		'reject_label'  => 'Reject all',
		'save_label'    => 'Save choices',
		'policy_url'    => '',
		'ttl_days'      => 180,
	);
}

function wcc_get_settings() {
	return wp_parse_args( (array) get_option( WCC_OPTION, array() ), wcc_defaults() );
}

/**
 * Consent categories. Necessary is always on and not toggleable.
 */
function wcc_categories() {
	return array(
		'necessary' => array( 'label' => 'Necessary', 'desc' => 'Required for the site to work. Always on.', 'locked' => true ),
		'analytics' => array( 'label' => 'Analytics', 'desc' => 'Anonymous usage statistics (e.g. GA4, Matomo).', 'locked' => false ),
		'marketing' => array( 'label' => 'Marketing', 'desc' => 'Ads, retargeting pixels, social embeds.', 'locked' => false ),
	);
}

/**
 * Current visitor consent as an array category => 0|1, or null if undecided.
 */
function wcc_current_consent() {
	if ( empty( $_COOKIE[ WCC_COOKIE ] ) ) {
		return null;
	}
	$data = json_decode( stripslashes( $_COOKIE[ WCC_COOKIE ] ), true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$out = array( 'necessary' => 1 );
	foreach ( array( 'analytics', 'marketing' ) as $cat ) {
		$out[ $cat ] = empty( $data[ $cat ] ) ? 0 : 1;
	}
	return $out;
}

/**
 * Helper for templates: has the visitor consented to a category?
 * Usage: if ( function_exists( 'wcc_consented' ) && wcc_consented( 'marketing' ) ) { ... }
 */
function wcc_consented( $category ) {
	$c = wcc_current_consent();
	if ( ! $c ) {
		return false;
	}
	return ! empty( $c[ $category ] );
}

/**
 * Script blocking: enqueue/marketing scripts registered with a
 * 'wcc_category' extra are only printed when that category is consented.
 * Example: wp_script_add_data( 'my-pixel', 'wcc_category', 'marketing' );
 */
add_filter( 'script_loader_tag', 'wcc_filter_script_tags', 10, 3 );
function wcc_filter_script_tags( $tag, $handle, $src ) {
	$cat = wp_scripts()->get_data( $handle, 'wcc_category' );
	if ( ! $cat ) {
		return $tag;
	}
	if ( wcc_consented( $cat ) ) {
		return $tag;
	}
	// Swap for a placeholder the banner JS revives on consent.
	return '<script type="text/plain" data-wcc-blocked data-wcc-category="' . esc_attr( $cat ) . '" data-src="' . esc_url( $src ) . '"></script>'
		. "\n<!-- wcc: blocked $handle until $cat consent -->\n";
}

/**
 * Render the banner + inline JS in the footer.
 */
add_action( 'wp_footer', 'wcc_render_banner', 100 );
function wcc_render_banner() {
	$set = wcc_get_settings();
	if ( empty( $set['enabled'] ) || is_admin() ) {
		return;
	}
	if ( wcc_current_consent() !== null ) {
		return; // already decided — no banner
	}

	$cats     = wcc_categories();
	$position = ( $set['position'] === 'top' ) ? 'top' : 'bottom';
	?>
	<div id="wcc-banner" style="position:fixed;<?php echo $position === 'top' ? 'top' : 'bottom'; ?>:0;left:0;right:0;z-index:99999;background:#111;color:#fff;padding:16px 20px;font-size:14px;line-height:1.5;box-shadow:0 -2px 12px rgba(0,0,0,.3);">
		<div style="max-width:960px;margin:0 auto;">
			<strong><?php echo esc_html( $set['banner_title'] ); ?></strong>
			<p style="margin:6px 0 10px;"><?php echo esc_html( $set['banner_text'] ); ?>
			<?php if ( ! empty( $set['policy_url'] ) ) : ?>
				<a href="<?php echo esc_url( $set['policy_url'] ); ?>" style="color:#7db4ff;">Cookie policy</a>
			<?php endif; ?>
			</p>
			<div style="margin:0 0 10px;display:flex;gap:18px;flex-wrap:wrap;">
				<?php foreach ( $cats as $key => $cat ) : ?>
					<label style="display:flex;align-items:center;gap:6px;cursor:<?php echo $cat['locked'] ? 'default' : 'pointer'; ?>;">
						<input type="checkbox" data-wcc-cat="<?php echo esc_attr( $key ); ?>"
							<?php checked( $cat['locked'] || $key === 'analytics', true ); ?>
							<?php disabled( $cat['locked'], true ); ?>>
						<?php echo esc_html( $cat['label'] ); ?>
						<span style="opacity:.7;font-size:12px;" title="<?php echo esc_attr( $cat['desc'] ); ?>">(?)</span>
					</label>
				<?php endforeach; ?>
			</div>
			<div style="display:flex;gap:10px;flex-wrap:wrap;">
				<button type="button" id="wcc-accept" style="background:#2e7d32;color:#fff;border:0;padding:8px 18px;cursor:pointer;"><?php echo esc_html( $set['accept_label'] ); ?></button>
				<button type="button" id="wcc-save" style="background:#444;color:#fff;border:0;padding:8px 18px;cursor:pointer;"><?php echo esc_html( $set['save_label'] ); ?></button>
				<button type="button" id="wcc-reject" style="background:transparent;color:#ccc;border:1px solid #555;padding:8px 18px;cursor:pointer;"><?php echo esc_html( $set['reject_label'] ); ?></button>
			</div>
		</div>
	</div>
	<script>
	(function () {
		var TTL = <?php echo (int) $set['ttl_days']; ?>;
		function setConsent(consent) {
			consent.necessary = 1;
			var exp = new Date();
			exp.setDate(exp.getDate() + TTL);
			document.cookie = '<?php echo esc_js( WCC_COOKIE ); ?>=' + encodeURIComponent(JSON.stringify(consent)) +
				';expires=' + exp.toUTCString() + ';path=/;SameSite=Lax';
			document.getElementById('wcc-banner').remove();
			revive(consent);
		}
		function chosen() {
			var c = { necessary: 1 };
			document.querySelectorAll('[data-wcc-cat]').forEach(function (box) {
				if (!box.disabled) c[box.getAttribute('data-wcc-cat')] = box.checked ? 1 : 0;
			});
			return c;
		}
		// Activate scripts that were blocked until their category got consent.
		function revive(consent) {
			document.querySelectorAll('script[data-wcc-blocked]').forEach(function (ph) {
				var cat = ph.getAttribute('data-wcc-category');
				if (!consent[cat]) return;
				var s = document.createElement('script');
				if (ph.getAttribute('data-src')) s.src = ph.getAttribute('data-src');
				s.textContent = ph.textContent;
				ph.parentNode.replaceChild(s, ph);
			});
		}
		document.getElementById('wcc-accept').addEventListener('click', function () {
			setConsent({ necessary: 1, analytics: 1, marketing: 1 });
		});
		document.getElementById('wcc-reject').addEventListener('click', function () {
			setConsent({ necessary: 1, analytics: 0, marketing: 0 });
		});
		document.getElementById('wcc-save').addEventListener('click', function () {
			setConsent(chosen());
		});
	})();
	</script>
	<?php
}

/**
 * Shortcode to let visitors re-open the consent choice: [wcc_manage label="Cookie settings"].
 * Clears the cookie and reloads so the banner reappears.
 */
add_shortcode( 'wcc_manage', function ( $atts ) {
	$atts = shortcode_atts( array( 'label' => 'Cookie settings' ), $atts, 'wcc_manage' );
	return '<a href="#" onclick="document.cookie=\'' . esc_js( WCC_COOKIE ) . '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/\';location.reload();return false;">'
		. esc_html( $atts['label'] ) . '</a>';
} );

/* ---------------- Settings page ---------------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Cookie Consent', 'Cookie Consent', 'manage_options', 'wcc-settings', 'wcc_render_settings' );
} );

add_action( 'admin_init', function () {
	register_setting( 'wcc_settings_group', WCC_OPTION, 'wcc_sanitize' );
} );

function wcc_sanitize( $in ) {
	$out = wcc_defaults();
	foreach ( $out as $key => $def ) {
		if ( ! isset( $in[ $key ] ) ) {
			continue;
		}
		$val = $in[ $key ];
		if ( $key === 'enabled' ) {
			$out[ $key ] = empty( $val ) ? 0 : 1;
		} elseif ( $key === 'position' ) {
			$out[ $key ] = ( $val === 'top' ) ? 'top' : 'bottom';
		} elseif ( $key === 'ttl_days' ) {
			$out[ $key ] = max( 1, (int) $val );
		} else {
			$out[ $key ] = sanitize_text_field( $val );
		}
	}
	return $out;
}

function wcc_render_settings() {
	$set = wcc_get_settings();
	$fields = array(
		'banner_title' => 'Banner title',
		'banner_text'  => 'Banner text',
		'accept_label' => '"Accept all" label',
		'reject_label' => '"Reject all" label',
		'save_label'   => '"Save choices" label',
		'policy_url'   => 'Cookie policy URL (empty = hide link)',
	);
	?>
	<div class="wrap">
		<h1>Cookie Consent</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wcc_settings_group' ); ?>
			<table class="form-table">
				<tr><th>Enabled</th><td><input type="checkbox" name="<?php echo esc_attr( WCC_OPTION ); ?>[enabled]" value="1" <?php checked( $set['enabled'], 1 ); ?>></td></tr>
				<?php foreach ( $fields as $key => $label ) : ?>
					<tr><th><?php echo esc_html( $label ); ?></th><td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( WCC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $set[ $key ] ); ?>">
					</td></tr>
				<?php endforeach; ?>
				<tr><th>Position</th><td>
					<select name="<?php echo esc_attr( WCC_OPTION ); ?>[position]">
						<option value="bottom" <?php selected( $set['position'], 'bottom' ); ?>>Bottom</option>
						<option value="top" <?php selected( $set['position'], 'top' ); ?>>Top</option>
					</select>
				</td></tr>
				<tr><th>Consent cookie TTL (days)</th><td><input type="number" min="1" name="<?php echo esc_attr( WCC_OPTION ); ?>[ttl_days]" value="<?php echo esc_attr( $set['ttl_days'] ); ?>"></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<p class="description">Block scripts until consent with
		<code>wp_script_add_data( 'my-pixel', 'wcc_category', 'marketing' );</code>
		and gate templates with <code>wcc_consented( 'analytics' )</code>.
		Add <code>[wcc_manage]</code> to your privacy page so visitors can change their choice.</p>
	</div>
	<?php
}
