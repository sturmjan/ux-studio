<?php
/**
 * Plain-language help of the Content Sync settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'node_allowed_ips'                => array(
		'does'   => __( 'When you list addresses here, this site answers only requests that come from them, on top of the signature check.', 'ux-studio' ),
		'effect' => __( 'Even someone who steals the key cannot control the site from another computer.', 'ux-studio' ),
		'risk'   => __( 'If the address of the central app server changes and you do not update the list, synchronization and remote sign-in stop working.', 'ux-studio' ),
		'off'    => __( 'Leave it empty only when you cannot learn the address of the central app server. Then the signature is the only protection.', 'ux-studio' ),
	),
	'node_allow_packages'             => array(
		'does'   => __( 'Lets the central app install and update plugins and themes on this site and, when needed, add one line (FS_METHOD) to wp-config.php, the file with the site configuration. Before it touches wp-config.php it saves a backup copy outside the public folders.', 'ux-studio' ),
		'effect' => __( 'You can roll out and update plugins on many sites from one place, without logging in to each of them.', 'ux-studio' ),
		'risk'   => __( 'Installing a plugin means running new code on your site. Whoever controls the central app, or steals the key of this site, can install anything, so a leaked key then means full control of the site. Every package has to match its checksum, but that only proves the file was not damaged on the way, not that it is safe. Turn it on only together with the list of allowed addresses.', 'ux-studio' ),
		'off'    => __( 'Keep it off if you manage plugins on this site yourself. Content synchronization works without it.', 'ux-studio' ),
	),
	'node_default_author'             => array(
		'does'   => __( 'Posts that arrive from the central app without an author get this user as the author.', 'ux-studio' ),
		'effect' => __( 'Synchronized posts do not end up without an author. With 0 WordPress picks the author itself.', 'ux-studio' ),
		'off'    => __( 'Leave 0 if the synchronized posts always come with their own author.', 'ux-studio' ),
	),
	'sso_allow_admin'                 => array(
		'does'   => __( 'Lets operators of the central app sign in to this site without a password as a user with administrator rights. Editors and lower roles can sign in with [[SSO]] either way.', 'ux-studio' ),
		'effect' => __( 'The operator opens the administration with one click from the central app and does not need your password.', 'ux-studio' ),
		'risk'   => __( 'An administrator can install plugins, which means running any code, and manage all users. With this on, a leaked key of this site or a hacked central app gives full control of the site. A sign-in link works once and for a short time only, but the rights it grants are complete. Turn it on only together with the list of allowed addresses.', 'ux-studio' ),
		'off'    => __( 'Keep it off unless you want the operators of the central app to administer this site.', 'ux-studio' ),
	),
	'sso_mapping_strategy'            => array(
		'does'   => __( 'Decides how an operator from the central app is matched with a user of this site. Automatic matches the e-mail address and creates a user when there is none. Strict signs in only users that already exist. Disabled turns the sign-in from the central app off.', 'ux-studio' ),
		'effect' => __( 'With the automatic setting a new operator gets an account on the first visit. With the strict one nobody new gets in without you creating the account first.', 'ux-studio' ),
		'risk'   => __( 'The automatic setting creates accounts on its own (never an administrator). A person who owns the e-mail address of an existing user is signed in as that user.', 'ux-studio' ),
		'off'    => __( 'Use strict when you want to approve every person yourself, and disabled when the site should not be reachable through [[SSO]] at all.', 'ux-studio' ),
	),
	'sso_allow_provision'             => array(
		'does'   => __( 'When an operator from the central app has no account here, a new user is created for them. An administrator is never created this way.', 'ux-studio' ),
		'effect' => __( 'The operator can sign in on the first try. New accounts appear in your list of users with the role chosen below.', 'ux-studio' ),
		'risk'   => __( 'You will find accounts in the user list that you did not create yourself.', 'ux-studio' ),
		'off'    => __( 'Turn it off to let in only people who already have an account.', 'ux-studio' ),
	),
	'sso_provision_wp_role'           => array(
		'does'   => __( 'The role given to users that are created automatically at the first sign-in from the central app. Administrator cannot be chosen on purpose.', 'ux-studio' ),
		'effect' => __( 'It sets what such a user may do. An editor can edit all content, an author only their own posts, a contributor can only write drafts.', 'ux-studio' ),
		'risk'   => __( 'Editor can change any content on the site. Choose the lowest role that is enough.', 'ux-studio' ),
	),
	'sso_token_ttl_seconds'           => array(
		'does'   => __( 'How many seconds a one-time sign-in link from the central app stays valid. The value is kept between 15 and 300 seconds.', 'ux-studio' ),
		'effect' => __( 'A short time makes a stolen link useless almost at once. The link can be used only once anyway.', 'ux-studio' ),
		'risk'   => __( 'Too short a time can make the sign-in fail on a slow connection or a slow site.', 'ux-studio' ),
		'off'    => __( 'The default of 60 seconds fits nearly everyone.', 'ux-studio' ),
	),
	'sso_require_ua_match'            => array(
		'does'   => __( 'The sign-in link works only in the same browser that asked for it. This is checked through the [[User-Agent]] of the browser.', 'ux-studio' ),
		'effect' => __( 'A link that leaks, for example in a chat or a log, is useless in another browser.', 'ux-studio' ),
		'risk'   => __( 'A browser that changes its identification between the click and the sign-in (some privacy extensions do) is refused.', 'ux-studio' ),
		'off'    => __( 'Turn it off only if operators fail to sign in because of a browser extension.', 'ux-studio' ),
	),
	'sso_require_ip_match'            => array(
		'does'   => __( 'The sign-in link works only from the same IP address from which it was requested.', 'ux-studio' ),
		'effect' => __( 'A leaked link is useless to someone on another network.', 'ux-studio' ),
		'risk'   => __( 'People whose address changes during the sign-in (a phone switching between Wi-Fi and mobile data, some VPNs, IPv4 against IPv6) are refused. Behind a proxy all visitors share one address and the check says nothing.', 'ux-studio' ),
		'off'    => __( 'Keep it off when operators work from changing networks. It is off by default.', 'ux-studio' ),
	),
	'sso_max_failed_redemptions'      => array(
		'does'   => __( 'How many failed sign-in attempts with a link one IP address may make in an hour before it is blocked. With 0 there is no limit.', 'ux-studio' ),
		'effect' => __( 'It stops guessing of sign-in links.', 'ux-studio' ),
		'risk'   => __( 'Too low a number can block honest operators who open an expired link several times.', 'ux-studio' ),
		'off'    => __( 'The default of 5 is a sensible balance.', 'ux-studio' ),
	),
	'sso_max_issue_per_operator_hour' => array(
		'does'   => __( 'How many sign-in links the central app may create for one operator in an hour.', 'ux-studio' ),
		'effect' => __( 'It limits the damage when an operator account of the central app is misused.', 'ux-studio' ),
		'risk'   => __( 'Too low a number blocks an operator who opens many sites in a short time.', 'ux-studio' ),
		'off'    => __( 'The default of 30 is enough for normal work.', 'ux-studio' ),
	),
);
