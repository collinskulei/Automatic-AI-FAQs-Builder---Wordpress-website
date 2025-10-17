<?php
/**
 * Plugin Name: AI FAQs Builder
 * Description: Scans post content and auto-creates FAQ blocks (HTML + FAQ schema JSON-LD). Provides manual generation and settings.
 * Version: 1.0.0
 * Author: Collins Kulei
 * Text Domain: ai-faqs-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation: set default options
 */
register_activation_hook( __FILE__, function() {
	if ( false === get_option( 'aifaqs_settings' ) ) {
		update_option( 'aifaqs_settings', array(
			'auto_insert'   => '1',   // auto append FAQ block to posts
			'position'      => 'append', // 'append' or 'prepend'
			'min_items'     => 1,    // minimum Q/A pairs required to consider it valid
			'details_ui'    => '1',  // use <details> UI
		) );
	}
});

/**
 * Admin menu / settings
 */
add_action( 'admin_menu', function() {
	add_options_page( 'AI FAQs Builder', 'AI FAQs Builder', 'manage_options', 'aifaqs-builder', 'aifaqs_builder_settings_page' );
} );

function aifaqs_builder_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$opts = get_option( 'aifaqs_settings', array() );
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'aifaqs_save_settings', 'aifaqs_nonce' ) ) {
		$opts['auto_insert'] = isset( $_POST['auto_insert'] ) ? '1' : '0';
		$opts['position']    = in_array( $_POST['position'], array( 'append', 'prepend' ) ) ? $_POST['position'] : 'append';
		$opts['min_items']   = max( 0, intval( $_POST['min_items'] ) );
		$opts['details_ui']  = isset( $_POST['details_ui'] ) ? '1' : '0';
		update_option( 'aifaqs_settings', $opts );
		echo '<div class="updated"><p>Settings saved.</p></div>';
	}

	?>
	<div class="wrap">
		<h1>AI FAQs Builder Settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'aifaqs_save_settings', 'aifaqs_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th>Auto insert FAQ block</th>
					<td><label><input type="checkbox" name="auto_insert" <?php checked( $opts['auto_insert'], '1' ); ?>> Automatically add generated FAQ block to posts on view</label></td>
				</tr>
				<tr>
					<th>Position</th>
					<td>
						<select name="position">
							<option value="append" <?php selected( $opts['position'], 'append' ); ?>>Append (bottom of post)</option>
							<option value="prepend" <?php selected( $opts['position'], 'prepend' ); ?>>Prepend (top of post)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Minimum Q/A pairs</th>
					<td><input type="number" name="min_items" value="<?php echo esc_attr( $opts['min_items'] ); ?>" min="0" /></td>
				</tr>
				<tr>
					<th>UI</th>
					<td><label><input type="checkbox" name="details_ui" <?php checked( $opts['details_ui'], '1' ); ?>> Use &lt;details&gt; / &lt;summary&gt; for each FAQ item (supported browsers)</label></td>
				</tr>
			</table>
			<p><input type="submit" class="button-primary" value="Save Settings"></p>
		</form>

		<h2>How it works</h2>
		<p>This plugin scans your post content for question/answer patterns (headings ending with a question mark, lines with "Q:" / "A:", or sentences ending with a question mark followed by the next sentence). It converts discovered Q/A pairs into:</p>
		<ul>
			<li>An HTML FAQ block (readable on the page)</li>
			<li>FAQ structured data JSON-LD (search engines)</li>
		</ul>
	</div>
	<?php
}

/**
 * Add meta box to Posts for manual generation and preview
 */
add_action( 'add_meta_boxes', function() {
	add_meta_box( 'aifaqs_meta', 'AI FAQs Builder', 'aifaqs_meta_box_cb', 'post', 'side', 'default' );
} );

function aifaqs_meta_box_cb( $post ) {
	wp_nonce_field( 'aifaqs_generate', 'aifaqs_generate_nonce' );
	echo '<p><button id="aifaqs-generate" class="button">Generate FAQs from content</button></p>';
	echo '<p><small>Click to extract FAQs from this post content and save them to post meta. You can preview and remove them from the post editor view.</small></p>';
	$html = get_post_meta( $post->ID, '_aifaqs_html', true );
	$count = 0;
	if ( $html ) {
		// count items roughly
		preg_match_all( '/<div class=\"aifaq-item\"/', $html, $m );
		$count = count( $m[0] ?? array() );
	}
	echo '<p><strong>Saved FAQ items:</strong> ' . intval( $count ) . '</p>';
	echo '<p><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '#aifaqs-preview">Open editor to preview</a></p>';

	// JS to handle button (uses fetch to admin-ajax)
	?>
	<script>
	( function(){
		const btn = document.getElementById('aifaqs-generate');
		btn.addEventListener('click', function(e){
			e.preventDefault();
			if (!confirm('Generate FAQs now from this post content?')) return;
			btn.disabled = true;
			btn.innerText = 'Generating...';
			fetch( ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams({
					action: 'aifaqs_generate_ajax',
					post_id: '<?php echo esc_js( $post->ID ); ?>',
					nonce: '<?php echo wp_create_nonce( 'aifaqs_generate_ajax' ); ?>'
				})
			} ).then(r=>r.json()).then(d=>{
				btn.disabled = false;
				btn.innerText = 'Generate FAQs from content';
				if (d.success) {
					alert('Generated ' + d.count + ' FAQ items and saved to post.');
					location.reload();
				} else {
					alert('No FAQs found or an error occurred: ' + (d.data || 'unknown'));
				}
			}).catch(err=>{
				btn.disabled=false;
				btn.innerText='Generate FAQs from content';
				alert('Request failed');
			});
		});
	})();
	</script>
	<?php
}

/**
 * AJAX handler for manual generation (admin only)
 */
add_action( 'wp_ajax_aifaqs_generate_ajax', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'permission' );
	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'aifaqs_generate_ajax' ) ) wp_send_json_error( 'nonce' );
	$post_id = intval( $_POST['post_id'] ?? 0 );
	if ( ! $post_id ) wp_send_json_error( 'post' );
	$post = get_post( $post_id );
	if ( ! $post ) wp_send_json_error( 'post' );

	$results = aifaqs_extract_from_html( $post->post_content );
	if ( empty( $results ) ) wp_send_json_error( 'no_faqs' );
	$html = aifaqs_build_html_block( $results );
	$schema = aifaqs_build_schema( $results );
	update_post_meta( $post_id, '_aifaqs_html', wp_kses_post( $html ) );
	update_post_meta( $post_id, '_aifaqs_schema', wp_json_encode( $schema ) );
	wp_send_json_success( array( 'count' => count( $results ) ) );
} );

/**
 * Filter the_content: auto-insert if enabled
 */
add_filter( 'the_content', function( $content ) {
	if ( ! is_singular() || is_admin() ) return $content;
	$post = get_post();
	if ( ! $post ) return $content;

	$opts = get_option( 'aifaqs_settings', array( 'auto_insert' => '1', 'position' => 'append', 'min_items' => 1 ) );
	if ( isset( $opts['auto_insert'] ) && $opts['auto_insert'] === '1' ) {
		// If there is saved HTML prefer that
		$saved_html = get_post_meta( $post->ID, '_aifaqs_html', true );
		$saved_schema = get_post_meta( $post->ID, '_aifaqs_schema', true );
		if ( $saved_html ) {
			$items_count = preg_match_all( '/<div class=\"aifaq-item\"/', $saved_html, $m ) ? count( $m[0] ) : 0;
			if ( $items_count >= intval( $opts['min_items'] ) ) {
				$block = $saved_html;
				$schema = $saved_schema;
			}
		} else {
			// try to extract from content on-the-fly
			$results = aifaqs_extract_from_html( $post->post_content );
			if ( ! empty( $results ) && count( $results ) >= intval( $opts['min_items'] ) ) {
				$block = aifaqs_build_html_block( $results );
				$schema = wp_json_encode( aifaqs_build_schema( $results ) );
				// optionally save for later
				update_post_meta( $post->ID, '_aifaqs_html', wp_kses_post( $block ) );
				update_post_meta( $post->ID, '_aifaqs_schema', $schema );
			}
		}

		if ( ! empty( $block ) ) {
			if ( isset( $opts['position'] ) && $opts['position'] === 'prepend' ) {
				$content = $block . $content;
			} else {
				$content = $content . $block;
			}
			// append schema script
			if ( ! empty( $schema ) ) {
				$content .= '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
			}
		}
	}

	return $content;
}, 99 );

/**
 * Utilities: extraction heuristics
 * - parse headings (h2/h3) that end with '?', use following <p> as answer
 * - parse "Q: ... A: ..." patterns
 * - fallback: sentences ending with '?' and next sentence considered answer
 */

function aifaqs_extract_from_html( $html ) {
	$html_orig = $html;
	$results = array();

	// Normalize encoding for DOMDocument
	$wrapped = '<div>' . $html . '</div>';
	libxml_use_internal_errors( true );
	$dom = new DOMDocument();
	$dom->loadHTML( mb_convert_encoding( $wrapped, 'HTML-ENTITIES', 'UTF-8' ) );
	$xpath = new DOMXPath( $dom );

	// 1) headings ending with ?
	foreach ( array( '//h2', '//h3', '//h4' ) as $q ) {
		$nodes = $xpath->query( $q );
		foreach ( $nodes as $n ) {
			$text = trim( $n->textContent );
			if ( substr( $text, -1 ) === '?' ) {
				// find next sibling that is a paragraph
				$ans = '';
				$sib = $n->nextSibling;
				while ( $sib && ( $sib->nodeType === XML_TEXT_NODE || $sib->nodeType === XML_COMMENT_NODE ) ) {
					$sib = $sib->nextSibling;
				}
				if ( $sib && in_array( $sib->nodeName, array( 'p', 'div' ), true ) ) {
					$ans = trim( $sib->textContent );
				}
				if ( $ans === '' ) {
					// maybe the heading contains both Q and A like "What is X? — Answer..."
					$parts = preg_split( '/[—–-]/u', $text, 2 );
					if ( count( $parts ) === 2 ) {
						$text = trim( $parts[0] );
						$ans = trim( $parts[1] );
					}
				}
				if ( $ans !== '' ) {
					$results[] = array( 'q' => aifaqs_sanitize_short( $text ), 'a' => aifaqs_sanitize_long( $ans ) );
				}
			}
		}
	}

	// 2) Q: / A: pattern (text nodes)
	if ( empty( $results ) ) {
		// strip tags and split into lines
		$plain = wp_strip_all_tags( $html_orig );
		$lines = preg_split( '/[\r\n]+/', $plain );
		$conc = implode( "\n", array_map( 'trim', $lines ) );
		// look for Q: ... A: ...
		preg_match_all( '/Q:\\s*(.+?)\\s*A:\\s*(.+?)(?=Q:|$)/is', $conc, $m, PREG_SET_ORDER );
		foreach ( $m as $row ) {
			$q = trim( $row[1] );
			$a = trim( $row[2] );
			if ( $q && $a ) $results[] = array( 'q' => aifaqs_sanitize_short( $q ), 'a' => aifaqs_sanitize_long( $a ) );
		}
	}

	// 3) fallback: sentence? followed by next sentence
	if ( empty( $results ) ) {
		$plain = wp_strip_all_tags( $html_orig );
		// split into sentences (naive)
		$sentences = preg_split( '/(?<=[.?!])\s+(?=[A-Z0-9])/u', $plain );
		for ( $i = 0; $i < count( $sentences ) - 1; $i++ ) {
			$s = trim( $sentences[ $i ] );
			$next = trim( $sentences[ $i + 1 ] );
			if ( substr( $s, -1 ) === '?' && strlen( $s ) > 10 && strlen( $next ) > 3 ) {
				$results[] = array( 'q' => aifaqs_sanitize_short( $s ), 'a' => aifaqs_sanitize_long( $next ) );
			}
		}
	}

	// Remove duplicates and limit
	$seen = array();
	$uniq = array();
	foreach ( $results as $r ) {
		$key = strtolower( trim( $r['q'] ) . '|' . trim( $r['a'] ) );
		if ( ! isset( $seen[ $key ] ) ) {
			$uniq[] = $r;
			$seen[ $key ] = true;
		}
	}

	return $uniq;
}

function aifaqs_sanitize_short( $text ) {
	$text = wp_strip_all_tags( $text );
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	return mb_substr( $text, 0, 400 );
}

function aifaqs_sanitize_long( $text ) {
	$text = trim( $text );
	// allow basic inline HTML: p, br, strong, em, a
	$allowed = array(
		'a' => array( 'href' => true, 'title' => true ),
		'br' => array(),
		'p' => array(),
		'strong' => array(),
		'em' => array(),
	);
	return wp_kses( wpautop( $text ), $allowed );
}

/**
 * Build HTML block for display
 */
function aifaqs_build_html_block( $items ) {
	$opts = get_option( 'aifaqs_settings', array( 'details_ui' => '1' ) );
	$use_details = isset( $opts['details_ui'] ) && $opts['details_ui'] === '1';

	$html = '<div class="aifaqs-builder" id="aifaqs-preview">';
	$html .= '<h2 class="aifaqs-title">Frequently asked questions</h2>';
	foreach ( $items as $i ) {
		$q = esc_html( $i['q'] );
		$a = $i['a']; // already sanitized
		if ( $use_details ) {
			$html .= '<div class="aifaq-item"><details><summary>' . $q . '</summary><div class="aifaq-answer">' . $a . '</div></details></div>';
		} else {
			$html .= '<div class="aifaq-item"><strong class="aifaq-question">' . $q . '</strong><div class="aifaq-answer">' . $a . '</div></div>';
		}
	}
	$html .= '</div>';
	$html .= aifaqs_inline_css();
	return $html;
}

/**
 * Build JSON-LD schema structure (FAQPage)
 */
function aifaqs_build_schema( $items ) {
	$mainEntity = array();
	foreach ( $items as $i ) {
		$mainEntity[] = array(
			'@type' => 'Question',
			'name'  => wp_strip_all_tags( $i['q'] ),
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => wp_strip_all_tags( $i['a'] ),
			),
		);
	}
	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'FAQPage',
		'mainEntity' => $mainEntity,
	);
	return $schema;
}

/**
 * Small inline CSS for FAQ block (keeps single-file self-contained)
 */
function aifaqs_inline_css() {
	return '<style>
	.aifaqs-builder { border-top: 1px solid #e1e1e1; padding-top: 18px; margin-top: 20px; }
	.aifaqs-title { font-size: 1.3em; margin-bottom: 12px; }
	.aifaq-item { margin-bottom: 10px; }
	.aifaq-question { display:block; font-weight:600; margin-bottom:6px; }
	.aifaq-answer { margin-left:6px; }
	details.aifaq-summary { cursor:pointer; }
	</style>';
}

/**
 * Shortcode to show generated FAQs for current post (manual)
 * Usage: [aifaqs]
 */
add_shortcode( 'aifaqs', function( $atts ) {
	global $post;
	if ( ! $post ) return '';
	$html = get_post_meta( $post->ID, '_aifaqs_html', true );
	$schema = get_post_meta( $post->ID, '_aifaqs_schema', true );
	$out = $html ? $html : '';
	if ( $schema ) {
		$out .= '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}
	return $out;
} );

