<?php
/**
 * Plugin Name: AI FAQs Builder
 * Description: Automatically generates FAQ sections with schema markup using AI. Supports OpenAI and OpenRouter.
 * Version: 1.0.1
 * Author: Collins Kulei
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

// ====== SETTINGS PAGE ======
add_action('admin_menu', function() {
    add_options_page(
        'AI FAQs Builder',
        'AI FAQs Builder',
        'manage_options',
        'ai-faqs-builder',
        'aifaqs_settings_page'
    );
});

function aifaqs_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI FAQs Builder Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('aifaqs_settings');
            do_settings_sections('aifaqs_settings');
            $api_key = get_option('aifaqs_api_key', '');
            $provider = get_option('aifaqs_provider', 'openai');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">AI Provider</th>
                    <td>
                        <select name="aifaqs_provider">
                            <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
                            <option value="openrouter" <?php selected($provider, 'openrouter'); ?>>OpenRouter</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="aifaqs_api_key" value="<?php echo esc_attr($api_key); ?>" style="width: 300px;">
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function() {
    register_setting('aifaqs_settings', 'aifaqs_api_key');
    register_setting('aifaqs_settings', 'aifaqs_provider');
});

// ====== META BOX IN EDITOR ======
add_action('add_meta_boxes', function() {
    add_meta_box('aifaqs_box', 'AI FAQs Builder', 'aifaqs_box_callback', 'post', 'side', 'high');
});

function aifaqs_box_callback($post) {
    ?>
    <p>Click the button below to generate FAQs for this post based on its content.</p>
    <button type="button" class="button button-primary" id="generate-faqs-btn">Generate FAQs</button>
    <div id="aifaqs-status" style="margin-top:10px;"></div>

    <script>
    document.getElementById('generate-faqs-btn').addEventListener('click', async function() {
        const status = document.getElementById('aifaqs-status');
        status.innerHTML = ' Generating FAQs...';
        const response = await fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aifaqs_generate',
                post_id: <?php echo $post->ID; ?>,
                _wpnonce: '<?php echo wp_create_nonce("aifaqs_nonce"); ?>'
            })
        });
        const data = await response.json();
        status.innerHTML = data.message;
    });
    </script>
    <?php
}

// ====== AJAX HANDLER ======
add_action('wp_ajax_aifaqs_generate', function() {
    if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');
    if (!wp_verify_nonce($_POST['_wpnonce'], 'aifaqs_nonce')) wp_send_json_error('Invalid nonce.');

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);
    $content = wp_strip_all_tags($post->post_content);
    $api_key = get_option('aifaqs_api_key');
    $provider = get_option('aifaqs_provider', 'openai');

    if (!$api_key) wp_send_json_error(['message' => 'API key not set in settings.']);

    $prompt = "Generate 5 frequently asked questions and answers based on the following article. 
    Format as structured JSON array with question and answer keys. Content:\n\n" . $content;

    $url = ($provider === 'openrouter')
        ? 'https://openrouter.ai/api/v1/chat/completions'
        : 'https://api.openai.com/v1/chat/completions';

    $body = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 500
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode($body),
        'timeout' => 60
    ]);

    if (is_wp_error($response)) wp_send_json_error(['message' => 'Request failed.']);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $output = $data['choices'][0]['message']['content'] ?? '';

    if (!$output) wp_send_json_error(['message' => 'No response from AI.']);

    // Try to decode JSON output from AI
    $faqs = json_decode($output, true);
    if (!is_array($faqs)) {
        $output = wp_kses_post($output);
        $faq_html = "<h2>Frequently Asked Questions</h2><div>$output</div>";
    } else {
        $faq_html = "<h2>Frequently Asked Questions</h2><div itemscope itemtype='https://schema.org/FAQPage'>";
        foreach ($faqs as $faq) {
            $q = esc_html($faq['question'] ?? '');
            $a = esc_html($faq['answer'] ?? '');
            $faq_html .= "<div itemscope itemprop='mainEntity' itemtype='https://schema.org/Question'>
                            <h3 itemprop='name'>$q</h3>
                            <div itemscope itemprop='acceptedAnswer' itemtype='https://schema.org/Answer'>
                                <p itemprop='text'>$a</p>
                            </div>
                          </div>";
        }
        $faq_html .= "</div>";
    }

    // Append to post content
    $new_content = $post->post_content . "\n\n" . $faq_html;
    wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);

    wp_send_json_success(['message' => ' FAQs generated and added to post!']);
});
