# AI FAQs Builder

**Version:** 1.0.0  
**Author:** Collins Kulei

AI FAQs Builder scans your post content and auto-creates FAQ blocks (HTML + JSON-LD schema). It supports automatic insertion or manual generation and saving via a meta box.

## Features

- Extracts Q/A pairs using heuristics:
  - Headings ending with `?` followed by a paragraph
  - `Q: ... A: ...` style blocks
  - Fallback: sentence ending with `?` followed by next sentence
- Builds a readable FAQ block (with optional `<details>` UI)
- Generates FAQ schema (FAQPage JSON-LD) for SEO
- Settings page to control auto-insert, position, minimum items, and UI
- Meta box in the post editor for manual generation and saving
- Shortcode `[aifaqs]` to display saved FAQs in content or template

## Installation

1. Create a new file `ai-faqs-builder.php`.
2. Copy the entire plugin PHP code into that file.
3. Upload the file to your WordPress site under `/wp-content/plugins/ai-faqs-builder.php` (or place inside a folder like `ai-faqs-builder/ai-faqs-builder.php`).
4. Go to **Plugins** in WordPress admin and activate **AI FAQs Builder**.

## Usage

### Automatic insertion
- Go to **Settings → AI FAQs Builder**.
- Enable **Auto insert FAQ block** to automatically append (or prepend) a generated FAQ block to singular posts.
- Configure minimum number of FAQ items and whether to use `<details>` UI.

### Manual generation (recommended for control)
1. Edit any post in the admin.
2. In the right sidebar, locate the **AI FAQs Builder** meta box.
3. Click **Generate FAQs from content**. The plugin will attempt to extract Q/A pairs and save them to post meta.
4. Saved FAQ HTML is automatically used when auto-insert is enabled, or you can place the shortcode `[aifaqs]` where you want the block to appear.

### Shortcode
- Use `[aifaqs]` inside the post content (or in theme templates via `echo do_shortcode('[aifaqs]');`) to display the saved FAQ block and its JSON-LD.

## Best practices & tips

- Write question headings (e.g., `### What is our refund policy?`) followed by a paragraph answer — this produces high-quality results.
- Review generated FAQs before publishing. Manual generation + edit is recommended for best output.
- The plugin currently runs locally (no external AI); heuristics may need small edits in source structure for best results.

## Security & Privacy

- No external requests are made by the plugin (all processing happens on-site).
- Schema content is built from your post content only.
- The plugin stores generated HTML and schema in post meta (`_aifaqs_html`, `_aifaqs_schema`).

## Extending

- Add remote AI generation later (e.g., call an LLM) to improve question/answer extraction or rephrase answers.
- Provide an editor UI for editing generated Q/A pairs before saving.
- Add support for custom post types, Gutenberg block integration, or bulk generation via WP-CLI.

## License

MIT — feel free to reuse and modify.
