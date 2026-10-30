<?php
/**
 * Plain-language help of the SMTP Email settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'mailer'                  => array(
		'does'   => __( 'Chooses the service that delivers every e-mail the site sends: orders, password resets, form messages. Your own mail server ([[smtp|SMTP]]) or one of the e-mail services with an API.', 'ux-studio' ),
		'effect' => __( 'Mail sent through a proper service is far less likely to land in spam than the plain mail of the web server.', 'ux-studio' ),
		'risk'   => __( 'If the chosen service is not set up or its login is wrong, sending fails and password resets and order e-mails do not arrive. Send a test e-mail after every change, and set a fallback connection.', 'ux-studio' ),
	),
	'fallback_mailer'         => array(
		'does'   => __( 'A second service that takes over when sending with the first one fails or is not set up. Both attempts are written to the log.', 'ux-studio' ),
		'effect' => __( 'An outage of one service no longer means lost e-mails.', 'ux-studio' ),
		'risk'   => __( 'The fallback must also have its sender verified. A failed message may be delivered by the second service, so the recipient sees a different sending server.', 'ux-studio' ),
		'off'    => __( 'Leave it on "None" if you have only one service.', 'ux-studio' ),
	),
	'from_email'              => array(
		'does'   => __( 'The sender address recipients see on every e-mail. Empty keeps what WordPress or the plugin chooses, usually wordpress@yourdomain.', 'ux-studio' ),
		'effect' => __( 'Use an address on your own domain, such as info@yourdomain.com, so recipients recognize you.', 'ux-studio' ),
		'risk'   => __( 'Most services refuse or mark as spam an address they have not verified, and an address on a domain without proper [[spf|SPF]] and [[dkim|DKIM]] records is often filtered. Verify the address or the domain with your service first.', 'ux-studio' ),
	),
	'force_from_email'        => array(
		'does'   => __( 'Replaces the sender address of every message with the one above, even when a plugin sets its own. Without it the address is replaced only when the plugin leaves the WordPress default.', 'ux-studio' ),
		'effect' => __( 'All mail comes from one address your service accepts.', 'ux-studio' ),
		'risk'   => __( 'Contact forms that put the visitor\'s address in the sender field lose it, so "Reply" no longer goes to the visitor unless the form sets a Reply-To header.', 'ux-studio' ),
	),
	'from_name'               => array(
		'does'   => __( 'The sender name recipients see next to the address, for example the name of your company. Empty keeps what WordPress or the plugin chooses.', 'ux-studio' ),
		'effect' => __( 'A familiar name is opened more often and looks less like spam.', 'ux-studio' ),
	),
	'force_from_name'         => array(
		'does'   => __( 'Replaces the sender name of every message with the one above, even when a plugin sets its own.', 'ux-studio' ),
		'effect' => __( 'All mail has the same sender name.', 'ux-studio' ),
		'risk'   => __( 'A form plugin that puts the visitor\'s name there loses it.', 'ux-studio' ),
	),
	'host'                    => array(
		'does'   => __( 'The address of your mail server, which your mail provider gives you, for example smtp.example.com. It is used by [[smtp|SMTP]].', 'ux-studio' ),
		'effect' => __( 'The site hands every e-mail to this server for delivery.', 'ux-studio' ),
		'risk'   => __( 'A typo makes sending fail. Check it with the test e-mail below.', 'ux-studio' ),
	),
	'port'                    => array(
		'does'   => __( 'The door of the mail server the site connects to. It must match the encryption: 587 with TLS is the usual choice, 465 with SSL is the older one, 25 is often blocked by hosts.', 'ux-studio' ),
		'effect' => __( 'The right port and encryption together keep the login and the messages private on their way.', 'ux-studio' ),
		'risk'   => __( 'A port that does not fit the encryption makes the connection hang or fail. Many hosts block port 25.', 'ux-studio' ),
	),
	'encryption'              => array(
		'does'   => __( 'How the connection to the mail server is protected. TLS (usually port 587) and SSL (usually port 465) encrypt it; None sends everything in plain text.', 'ux-studio' ),
		'effect' => __( 'With encryption, nobody on the way can read your login or your messages.', 'ux-studio' ),
		'risk'   => __( 'With None, the password travels in plain text. Use None only for a server on the same machine.', 'ux-studio' ),
	),
	'username'                => array(
		'does'   => __( 'The login name for the mail server. Often it is the full e-mail address, but with some providers it is a special name.', 'ux-studio' ),
		'effect' => __( 'The server uses it with the password to accept your messages.', 'ux-studio' ),
	),
	'password'                => array(
		'does'   => __( 'The password for the mail server. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'Fill it only when you set it up or change it.', 'ux-studio' ),
		'risk'   => __( 'Use a dedicated or application-specific password if your provider offers one. A changed account password makes sending fail until you update it here.', 'ux-studio' ),
	),
	'brevo_api_key'           => array(
		'does'   => __( 'The key that lets this site send through your Brevo account. Create it in Brevo under SMTP & API. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'Use "Verify API key" to check that it works.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the key can send mail in your name. Never paste it in a chat or an e-mail, and create a new one if it leaks.', 'ux-studio' ),
	),
	'gmail_client_id'         => array(
		'does'   => __( 'The ID of an [[oauth|OAuth]] client that you create in Google Cloud. It lets the site ask Google for permission to send mail from your Gmail account, without storing your Gmail password.', 'ux-studio' ),
		'effect' => __( 'After you save it together with the secret, the "Connect Gmail account" button opens the Google consent screen.', 'ux-studio' ),
		'risk'   => __( 'The redirect address shown below must be added to that OAuth client, otherwise Google refuses the connection. Gmail limits the number of messages per day.', 'ux-studio' ),
	),
	'gmail_client_secret'     => array(
		'does'   => __( 'The secret that belongs to the OAuth client above, from Google Cloud. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'Together with the ID it identifies this site to Google.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the secret can pose as this site toward Google. Replace it in Google Cloud if it leaks.', 'ux-studio' ),
	),
	'sendgrid_api_key'        => array(
		'does'   => __( 'The key that lets this site send through your SendGrid account. Create it in SendGrid with only the "Mail Send" permission. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'The sender address must be a verified sender in SendGrid, otherwise SendGrid refuses the message.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the key can send mail in your name. Give it as few permissions as possible.', 'ux-studio' ),
	),
	'sendgrid_region'         => array(
		'does'   => __( 'Which data center of SendGrid handles your mail: global or the European one. It must match the region of your SendGrid account.', 'ux-studio' ),
		'effect' => __( 'Choose EU if your account keeps its data in the European Union; this matters for GDPR.', 'ux-studio' ),
		'risk'   => __( 'The wrong region makes SendGrid reject the key.', 'ux-studio' ),
	),
	'mailgun_api_key'         => array(
		'does'   => __( 'The key that lets this site send through your Mailgun account. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'Together with the sending domain it identifies where the mail is sent from.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the key can send mail in your name. Never paste it in a chat or an e-mail.', 'ux-studio' ),
	),
	'mailgun_domain'          => array(
		'does'   => __( 'The domain you added in Mailgun for sending, for example mg.example.com. Mailgun checks the sender address against it.', 'ux-studio' ),
		'effect' => __( 'The sender address should end with this domain, otherwise Mailgun may refuse the message.', 'ux-studio' ),
		'risk'   => __( 'A domain that is not verified in Mailgun makes sending fail.', 'ux-studio' ),
	),
	'mailgun_region'          => array(
		'does'   => __( 'Which Mailgun data center your account lives in: the US or the European one. It must match the region you chose when you created the account.', 'ux-studio' ),
		'effect' => __( 'Choose EU if your account keeps its data in the European Union; this matters for GDPR.', 'ux-studio' ),
		'risk'   => __( 'The wrong region makes Mailgun reject the key.', 'ux-studio' ),
	),
	'postmark_server_token'   => array(
		'does'   => __( 'The token that lets this site send through one server of your Postmark account. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'The sender address must be a confirmed sender signature or belong to a verified domain, otherwise Postmark refuses the message.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the token can send mail in your name. Create a new one in Postmark if it leaks.', 'ux-studio' ),
	),
	'postmark_message_stream' => array(
		'does'   => __( 'Postmark keeps different kinds of mail in separate streams. Empty uses the default one for transactional mail (outbound), which is right for orders, password resets and forms.', 'ux-studio' ),
		'effect' => __( 'Enter a name only if you created your own stream in Postmark and want this site to use it.', 'ux-studio' ),
		'risk'   => __( 'A stream that does not exist makes Postmark refuse every message. Do not send marketing mail through the transactional stream.', 'ux-studio' ),
	),
	'ses_access_key'          => array(
		'does'   => __( 'The ID of the access key of an Amazon user that is allowed to send mail. Create a dedicated user with only the ses:SendEmail permission. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'Together with the secret access key it lets this site send through your Amazon SES account.', 'ux-studio' ),
		'risk'   => __( 'A key with wider permissions than needed puts your whole Amazon account at risk if it leaks. Give it only the right to send mail.', 'ux-studio' ),
	),
	'ses_secret_key'          => array(
		'does'   => __( 'The secret that belongs to the access key above. It is stored encrypted and never shown again. Leave the field empty to keep the current one.', 'ux-studio' ),
		'effect' => __( 'The sender address or its domain must be a verified identity in the chosen region.', 'ux-studio' ),
		'risk'   => __( 'Anyone with the secret can use the permissions of that Amazon user. Create a new key if it leaks.', 'ux-studio' ),
	),
	'ses_region'              => array(
		'does'   => __( 'The Amazon data center region in which your SES account and your verified sender are set up, for example eu-west-1 for Ireland.', 'ux-studio' ),
		'effect' => __( 'Choose the region where you verified your sender. For European customers a European region is the usual choice.', 'ux-studio' ),
		'risk'   => __( 'A sender verified in another region is not accepted, and sending fails.', 'ux-studio' ),
	),
	'enable_email_logs'       => array(
		'does'   => __( 'Records every e-mail sent from the site in the delivery log: when, to whom, the subject and whether it went through.', 'ux-studio' ),
		'effect' => __( 'You can see at a glance whether a message left the site and why it failed.', 'ux-studio' ),
		'risk'   => __( 'The log holds recipient addresses and subjects, which are personal data. Keep the retention short and mention it in your privacy policy.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not want any record of messages on the site.', 'ux-studio' ),
	),
	'retention_days'          => array(
		'does'   => __( 'How many days entries of the delivery log are kept. Older entries are deleted once a day.', 'ux-studio' ),
		'effect' => __( 'A short period keeps the database small and limits stored personal data.', 'ux-studio' ),
		'risk'   => __( 'With 0 nothing is ever deleted, so the log grows and keeps personal data forever. Deleted entries cannot be restored.', 'ux-studio' ),
	),
);
