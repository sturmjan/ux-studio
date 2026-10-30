<?php
/**
 * Plain-language help of the AI Markdown settings, read by Core\SettingHelp (settings screen).
 * Format: docs/help-texts.md. Keys are the `key` fields of Module::settings_schema().
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'auto_regenerate'          => array(
		'does'   => __( 'Rebuilds the stored markdown copy of a page or post every time you save it.', 'ux-studio' ),
		'effect' => __( 'AI bots always read the current text, not an old copy. Saving a post takes a moment longer.', 'ux-studio' ),
		'off'    => __( 'Turn it off only if you prefer to regenerate the copies on a schedule below.', 'ux-studio' ),
	),
	'excluded_post_types'      => array(
		'does'   => __( 'Content types that never get a markdown copy and never appear in llms.txt or the AI sitemap.', 'ux-studio' ),
		'effect' => __( 'AI bots cannot read these pages through the markdown address. The pages stay on the normal site as before.', 'ux-studio' ),
		'risk'   => __( 'Hiding a type here does not hide it from search engines or from the normal page. Use it to keep technical or private types out of the AI overview.', 'ux-studio' ),
	),
	'taxonomies'               => array(
		'does'   => __( 'Taxonomies are the ways you group content, such as categories and tags. The terms of the groups you tick are listed in [[llms.txt]] and in the AI sitemap.', 'ux-studio' ),
		'effect' => __( 'AI assistants get a table of contents of your site: which topics exist and where to find them.', 'ux-studio' ),
		'off'    => __( 'Untick groups that are only for internal use or have hundreds of terms nobody needs to see.', 'ux-studio' ),
	),
	'site_name'                => array(
		'does'   => __( 'The heading at the top of your [[llms.txt]] file, which is the first thing an AI assistant reads about your site.', 'ux-studio' ),
		'effect' => __( 'AI answers use this name when they talk about you. Empty means the site title of WordPress.', 'ux-studio' ),
		'off'    => __( 'Fill it in when the site title is long or is not the name people know you by.', 'ux-studio' ),
	),
	'site_description'         => array(
		'does'   => __( 'A short description of the site, shown under the heading of [[llms.txt]].', 'ux-studio' ),
		'effect' => __( 'Helps AI assistants understand in one or two sentences what you do and who you serve. Empty means the tagline of WordPress.', 'ux-studio' ),
		'off'    => __( 'Write it in plain words and keep it factual. Advertising slogans tell the AI very little.', 'ux-studio' ),
	),
	'enable_llms_txt'          => array(
		'does'   => __( 'Publishes the file /llms.txt in the root of your site. [[llms.txt]] is a plain text overview that tells AI assistants what the site is about and which pages matter.', 'ux-studio' ),
		'effect' => __( 'AI crawlers and assistants can find your key content faster and describe it better. The file is generated for you, you do not edit it by hand.', 'ux-studio' ),
		'risk'   => __( 'The file is public. Everything listed in it can be read by anybody, so keep private content in the excluded types.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not want to guide AI assistants, or if another plugin already serves /llms.txt.', 'ux-studio' ),
	),
	'enable_ai_sitemap'        => array(
		'does'   => __( 'Publishes /ai-sitemap.md, a list of all published items with their date and a link to their markdown version.', 'ux-studio' ),
		'effect' => __( 'AI bots can find and revisit all your content without crawling the whole site page by page.', 'ux-studio' ),
		'risk'   => __( 'On a very large site the list is long. It is cached, so it does not slow the site down.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you do not want a full list of your content to be easy to fetch.', 'ux-studio' ),
	),
	'enable_markdown_content'  => array(
		'does'   => __( 'Adds ?format=markdown to the address of any post or page to return it as clean markdown, without menus, scripts and layout.', 'ux-studio' ),
		'effect' => __( 'AI bots read your content with fewer tokens and fewer mistakes than from the full page. The normal page does not change.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not want AI bots to be able to fetch the text of your pages in this form.', 'ux-studio' ),
	),
	'include_meta_header'      => array(
		'does'   => __( 'Starts every markdown copy with a short header block of facts (title, address, type, date, author) written in [[YAML]], a simple text format for structured data.', 'ux-studio' ),
		'effect' => __( 'AI tools can read the facts about the page without guessing them from the text.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the consumer of the markdown expects pure text with no header.', 'ux-studio' ),
	),
	'cache_ttl'                => array(
		'does'   => __( 'How long a generated markdown copy is kept before it is built again on the next visit of a bot.', 'ux-studio' ),
		'effect' => __( 'A longer time saves server work. A shorter time keeps copies fresh when content changes outside normal saves, for example through prices or imports.', 'ux-studio' ),
		'off'    => __( 'Unlimited is fine for most sites, because saving a post always rebuilds its copy.', 'ux-studio' ),
	),
	'auto_regenerate_interval' => array(
		'does'   => __( 'Rebuilds the markdown copies of the whole site on a schedule, using [[WP-Cron]], the WordPress scheduler.', 'ux-studio' ),
		'effect' => __( 'Catches changes that did not go through a normal save, such as bulk imports. The rebuild uses some server time while it runs.', 'ux-studio' ),
		'risk'   => __( 'WP-Cron runs only when somebody opens a page, so on a site with very little traffic the run can come late.', 'ux-studio' ),
		'off'    => __( 'Leave it off when you rely on saving posts.', 'ux-studio' ),
	),
	'exclude_ids'              => array(
		'does'   => __( 'The numbers (IDs) of single posts or pages that never get a markdown copy. Separate them with commas, for example 12, 45, 78.', 'ux-studio' ),
		'effect' => __( 'Hides individual pages from the AI overview and the markdown address. You find the ID in the address bar when you edit the page.', 'ux-studio' ),
	),
	'enable_logging'           => array(
		'does'   => __( 'Records each visit of a known AI bot (GPTBot, ClaudeBot, PerplexityBot and others) on the markdown addresses. Normal visitors are not logged.', 'ux-studio' ),
		'effect' => __( 'You see which AI services read your site and which pages they ask for. The log grows with the traffic of the bots.', 'ux-studio' ),
		'risk'   => __( 'The log stores the bot name and the address visited. It is cleaned automatically by the retention below.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not need the statistics.', 'ux-studio' ),
	),
	'log_retention_days'       => array(
		'does'   => __( 'How many days the entries of the bot access log are kept. Older entries are deleted once a day.', 'ux-studio' ),
		'effect' => __( 'Keeps the log small. With 0 nothing is deleted and the table grows without end.', 'ux-studio' ),
		'off'    => __( 'Use 0 only when you export the log yourself.', 'ux-studio' ),
	),
	'enable_woocommerce'       => array(
		'does'   => __( 'Uses a richer markdown template for products, with price, stock and attributes.', 'ux-studio' ),
		'effect' => __( 'AI assistants can answer questions such as "is it in stock" or "what does it cost" from the markdown copy of a product.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not want prices and stock to be readable by AI bots.', 'ux-studio' ),
	),
	'show_price'               => array(
		'does'   => __( 'Adds the price of the product to the [[YAML]] header of its markdown copy.', 'ux-studio' ),
		'effect' => __( 'AI assistants can quote the current price. A copy that is not rebuilt for a long time can show an old price.', 'ux-studio' ),
		'off'    => __( 'Turn it off when prices differ by customer or should not be public.', 'ux-studio' ),
	),
	'show_currency'            => array(
		'does'   => __( 'Adds the currency code (CZK, EUR and so on) next to the price.', 'ux-studio' ),
		'effect' => __( 'Stops AI assistants from guessing the currency, which matters when you sell in more than one.', 'ux-studio' ),
	),
	'show_stock_status'        => array(
		'does'   => __( 'Adds whether the product is in stock to the header.', 'ux-studio' ),
		'effect' => __( 'AI assistants do not send people to a product you cannot deliver, as long as the copy is fresh.', 'ux-studio' ),
	),
	'show_product_categories'  => array(
		'does'   => __( 'Adds the categories of the product to the header.', 'ux-studio' ),
		'effect' => __( 'Helps AI assistants understand what kind of product it is and group similar ones.', 'ux-studio' ),
	),
	'show_product_attributes'  => array(
		'does'   => __( 'Adds the attributes of the product (size, color and so on) to the header.', 'ux-studio' ),
		'effect' => __( 'AI assistants can answer questions about the parameters without reading the whole description.', 'ux-studio' ),
	),
);
