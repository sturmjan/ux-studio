<?php
/**
 * Plain-language help of the AI Assistant settings, read by Core\SettingHelp (settings screen).
 * Format: docs/help-texts.md. Keys are the `key` fields of Module::settings_schema().
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'default_provider'               => array(
		'does'   => __( 'Chooses the AI company whose model answers the visitor chat and writes the generated content. Claude, OpenAI and DeepSeek are supported.', 'ux-studio' ),
		'effect' => __( 'Every question goes to that company over the internet, using the API key you saved for it. A provider without a key cannot answer.', 'ux-studio' ),
		'risk'   => __( 'The texts of your visitors and your content leave the site and are billed to your account at that company. Switching the provider also changes the style of the answers.', 'ux-studio' ),
		'off'    => __( 'Keep one provider unless you want to compare answers or the provider has an outage.', 'ux-studio' ),
	),
	'claude_api_key'                 => array(
		'does'   => __( 'The secret key of your own account at Anthropic (Claude). The plugin sends it with every request so the provider knows whom to bill.', 'ux-studio' ),
		'effect' => __( 'You pay Anthropic directly for what the AI uses (bring your own key). The plugin does not sell AI credits.', 'ux-studio' ),
		'risk'   => __( 'Anybody who gets the key can spend money on your account. It is stored encrypted and never shown again, so set a spending limit in the provider account.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not use Claude.', 'ux-studio' ),
	),
	'openai_api_key'                 => array(
		'does'   => __( 'The secret key of your own account at OpenAI. The plugin sends it with every request so the provider knows whom to bill.', 'ux-studio' ),
		'effect' => __( 'You pay OpenAI directly for what the AI uses (bring your own key). The plugin does not sell AI credits.', 'ux-studio' ),
		'risk'   => __( 'Anybody who gets the key can spend money on your account. It is stored encrypted and never shown again, so set a spending limit in the provider account.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not use OpenAI.', 'ux-studio' ),
	),
	'deepseek_api_key'               => array(
		'does'   => __( 'The secret key of your own account at DeepSeek. The plugin sends it with every request so the provider knows whom to bill.', 'ux-studio' ),
		'effect' => __( 'You pay DeepSeek directly for what the AI uses (bring your own key). The plugin does not sell AI credits.', 'ux-studio' ),
		'risk'   => __( 'Anybody who gets the key can spend money on your account. It is stored encrypted and never shown again, so set a spending limit in the provider account.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not use DeepSeek.', 'ux-studio' ),
	),
	'claude_model'                   => array(
		'does'   => __( 'The model is the exact version of the AI that is called. Larger models answer better and cost more per [[tokens]], smaller ones are faster and cheaper.', 'ux-studio' ),
		'effect' => __( 'Changes the quality, the speed and the price of every Claude answer.', 'ux-studio' ),
		'risk'   => __( 'When Anthropic retires a model, requests to it fail until you pick another one.', 'ux-studio' ),
		'off'    => __( 'Keep the preselected model unless you have a reason. Try a smaller one when the chat is busy and the bill grows.', 'ux-studio' ),
	),
	'openai_model'                   => array(
		'does'   => __( 'The model is the exact version of the AI that is called. Larger models answer better and cost more per [[tokens]], smaller ones are faster and cheaper.', 'ux-studio' ),
		'effect' => __( 'Changes the quality, the speed and the price of every OpenAI answer.', 'ux-studio' ),
		'risk'   => __( 'When OpenAI retires a model, requests to it fail until you pick another one.', 'ux-studio' ),
		'off'    => __( 'Keep the preselected model unless you have a reason. Try a smaller one when the chat is busy and the bill grows.', 'ux-studio' ),
	),
	'deepseek_model'                 => array(
		'does'   => __( 'The model is the exact version of the AI that is called. Larger models answer better and cost more per [[tokens]], smaller ones are faster and cheaper.', 'ux-studio' ),
		'effect' => __( 'Changes the quality, the speed and the price of every DeepSeek answer.', 'ux-studio' ),
		'risk'   => __( 'When DeepSeek retires a model, requests to it fail until you pick another one.', 'ux-studio' ),
		'off'    => __( 'Keep the preselected model unless you have a reason. Try a smaller one when the chat is busy and the bill grows.', 'ux-studio' ),
	),
	'monthly_token_limit'            => array(
		'does'   => __( 'A hard cap on the [[tokens]] all AI features of the site may use in a calendar month. A token is a small piece of text, roughly three quarters of a word, that the AI reads or writes.', 'ux-studio' ),
		'effect' => __( 'When the cap is reached, the AI stops answering until the 1st of the next month. Visitors see the chat as unavailable.', 'ux-studio' ),
		'risk'   => __( 'Set too low, the chat falls silent in the middle of a month. Set to 0, an attack on the public chat can run up a large bill.', 'ux-studio' ),
		'off'    => __( 'Use 0 only when you watch the spending in the provider account yourself.', 'ux-studio' ),
	),
	'daily_request_limit'            => array(
		'does'   => __( 'The most AI requests the whole site may send in one day, the public chat included.', 'ux-studio' ),
		'effect' => __( 'Above the limit new questions are refused until the next day. This keeps a flood of messages from becoming a flood of costs.', 'ux-studio' ),
		'risk'   => __( 'A busy shop can reach a low limit by the afternoon, and later visitors get no answer.', 'ux-studio' ),
		'off'    => __( 'Raise it for a site with a lot of traffic, use 0 only when the monthly token limit is enough for you.', 'ux-studio' ),
	),
	'user_daily_request_limit'       => array(
		'does'   => __( 'The most AI requests one logged-in user may send in one day. It applies to editors and customers with an account.', 'ux-studio' ),
		'effect' => __( 'One person cannot use up the whole site limit. Anonymous chat visitors are not counted here, they are held by the site-wide limits.', 'ux-studio' ),
		'off'    => __( 'Use 0 when only a few trusted editors use the AI tools.', 'ux-studio' ),
	),
	'mcp_enabled'                    => array(
		'does'   => __( 'Lets the chat call tools of your site while it answers, through [[MCP]]: it can look up products, content, FAQs and the knowledge base by itself instead of receiving everything in one big prompt.', 'ux-studio' ),
		'effect' => __( 'Answers are usually more accurate and up to date, because the AI fetches only what it needs. A question can take a little longer and use more [[tokens]], since the AI makes several calls.', 'ux-studio' ),
		'risk'   => __( 'Each tool call is another paid request to the provider. A provider or model without tool support makes the chat fail.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the answers are already good enough or when you want the lowest cost and the fastest reply.', 'ux-studio' ),
	),
	'language'                       => array(
		'does'   => __( 'The language of the visitor chat: the texts of the chat window, the default greeting and the language the AI answers in.', 'ux-studio' ),
		'effect' => __( 'The chat window speaks this language whatever the language of the page or the visitor is. Texts you typed yourself (title, greeting) are shown as written.', 'ux-studio' ),
		'off'    => __( 'Change it when most of your visitors speak the other language. For a different language of generated articles use Content language below.', 'ux-studio' ),
	),
	'operator_notifications_enabled' => array(
		'does'   => __( 'Sends you an e-mail the moment a visitor presses the button to talk to a person.', 'ux-studio' ),
		'effect' => __( 'You can join the conversation while the visitor is still waiting. Without the e-mail you notice the request only when you open the admin.', 'ux-studio' ),
		'risk'   => __( 'E-mails are limited per hour (see Notification e-mails per hour). Requests above the limit are saved but not e-mailed.', 'ux-studio' ),
		'off'    => __( 'Turn it off when nobody on your team handles chat requests live.', 'ux-studio' ),
	),
	'custom_system_prompt'           => array(
		'does'   => __( 'Your own instructions for the AI, added to the built-in [[system prompt]]. The system prompt is the standing briefing the AI gets before every conversation: who it is, what it may talk about and how it should sound.', 'ux-studio' ),
		'effect' => __( 'Shapes every answer of the public chat and the content generator, for example "address visitors formally" or "never promise delivery dates".', 'ux-studio' ),
		'risk'   => __( 'A badly worded instruction can make answers odd or off topic for every visitor. A long text also costs [[tokens]] with each message.', 'ux-studio' ),
		'off'    => __( 'Leave it empty until you see a concrete problem with the answers. Then add one short rule at a time and test the chat.', 'ux-studio' ),
	),
	'widget_enabled'                 => array(
		'does'   => __( 'Shows the chat bubble in the corner of every page of your public site.', 'ux-studio' ),
		'effect' => __( 'Visitors can ask questions and call a person. When it is off, the chat disappears from the site, but the settings, the history and the training stay.', 'ux-studio' ),
		'off'    => __( 'Turn it off while you are still setting up or training the chat.', 'ux-studio' ),
	),
	'widget_type'                    => array(
		'does'   => __( 'Chooses whether the chat opens as a small window above the bubble or as a tall panel along the edge of the screen.', 'ux-studio' ),
		'effect' => __( 'The bubble style takes little room. The sidebar style gives long answers and product cards more space but covers part of the page.', 'ux-studio' ),
	),
	'widget_position'                => array(
		'does'   => __( 'Puts the chat bubble in the bottom right or the bottom left corner.', 'ux-studio' ),
		'effect' => __( 'Choose the corner that does not collide with other floating buttons such as a cookie notice, a scroll to top button or a phone button.', 'ux-studio' ),
	),
	'widget_color'                   => array(
		'does'   => __( 'The main color of the bubble, the header and the buttons of the chat.', 'ux-studio' ),
		'effect' => __( 'Pick your brand color so the chat looks like a part of the site. Keep enough contrast with the text color so the labels stay readable.', 'ux-studio' ),
	),
	'widget_text_color'              => array(
		'does'   => __( 'The color of the text and icons placed on the main color of the chat.', 'ux-studio' ),
		'effect' => __( 'Use white on a dark main color and a dark color on a light one. A weak contrast makes the buttons hard to read.', 'ux-studio' ),
	),
	'widget_auto_open'               => array(
		'does'   => __( 'Opens the chat window by itself a few seconds after the page has loaded.', 'ux-studio' ),
		'effect' => __( 'More visitors start a conversation, but some find a window that pops up on its own annoying. After the visitor closes it, it stays shut for the number of days set below.', 'ux-studio' ),
		'risk'   => __( 'On mobile the open window can cover the content and push visitors away.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the site is mostly read on phones or when visitors should not be interrupted.', 'ux-studio' ),
	),
	'widget_auto_open_delay'         => array(
		'does'   => __( 'How many seconds after the page has loaded the chat opens by itself. Allowed values are 0 to 120.', 'ux-studio' ),
		'effect' => __( 'A short delay catches the visitor before they have read anything. A longer one opens the chat only for people who stay on the page.', 'ux-studio' ),
		'off'    => __( 'Has no effect while automatic opening is off.', 'ux-studio' ),
	),
	'widget_auto_open_sound'         => array(
		'does'   => __( 'Plays a short sound when the chat opens by itself.', 'ux-studio' ),
		'effect' => __( 'Draws attention to the window, but browsers play the sound only after the visitor has interacted with the page, and some visitors dislike it.', 'ux-studio' ),
		'off'    => __( 'Turn it off for a quiet site, for example one that is used in offices or libraries.', 'ux-studio' ),
	),
	'chatbot_provider'               => array(
		'does'   => __( 'Lets the internal chat in the admin use a different AI company than the one set as the default for the public chat.', 'ux-studio' ),
		'effect' => __( 'You can, for example, keep a cheap model on the public chat and a stronger one for your own team. Empty means the default provider.', 'ux-studio' ),
		'off'    => __( 'Leave it empty unless you need two different providers.', 'ux-studio' ),
	),
	'chatbot_system_prompt'          => array(
		'does'   => __( 'Your own instructions for the internal chat, added to its built-in [[system prompt]] (the standing briefing the AI gets before each conversation).', 'ux-studio' ),
		'effect' => __( 'Shapes how the internal assistant talks to your team, for example the tone or the topics it should prefer. The public chat is not affected.', 'ux-studio' ),
		'risk'   => __( 'A long text costs [[tokens]] with every message of the internal chat.', 'ux-studio' ),
		'off'    => __( 'Leave it empty unless the default behavior of the internal chat does not suit you.', 'ux-studio' ),
	),
	'internal_rag_enabled'           => array(
		'does'   => __( 'The internal chat first looks up the matching parts of the material you trained it with and answers from them ([[RAG]]), instead of guessing from general knowledge.', 'ux-studio' ),
		'effect' => __( 'Answers about your own company, products and procedures are more accurate. Only material you marked for the internal chat is used.', 'ux-studio' ),
		'risk'   => __( 'Without any trained material it changes nothing. Wrong or outdated material produces wrong answers.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the internal chat should answer only from the general knowledge of the AI.', 'ux-studio' ),
	),
	'default_tone'                   => array(
		'does'   => __( 'The tone of voice the content generator uses unless you pick another one for a single text.', 'ux-studio' ),
		'effect' => __( 'Sets whether generated texts sound neutral, formal, friendly or persuasive. It saves you from choosing the tone again for every text.', 'ux-studio' ),
		'off'    => __( 'Change it to match the voice of your brand. You can still override it per text.', 'ux-studio' ),
	),
	'default_length'                 => array(
		'does'   => __( 'The length of the texts the content generator writes unless you pick another one for a single text.', 'ux-studio' ),
		'effect' => __( 'Longer texts take more time and use more [[tokens]], so they cost more.', 'ux-studio' ),
	),
);
