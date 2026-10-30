<?php
/**
 * Plain-language help of the Third-Party Login settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'central_app_url'     => array(
		'does'   => __( 'The address of the central app that talks to Google, Facebook, Apple or Seznam for this site. The site never sees your provider keys, only the answer of the central app.', 'ux-studio' ),
		'effect' => __( 'Without a working address the login buttons cannot start. Use the address of your own central app, including the https:// part.', 'ux-studio' ),
		'risk'   => __( 'The central app tells the site who is logging in, so enter only an address you own and trust.', 'ux-studio' ),
	),
	'hmac_secret'         => array(
		'does'   => __( 'A shared password between this site and the central app. Both sides use it to sign every login message, so a forged message is recognized and rejected. The central app shows it in the detail of the site under "Third-party login".', 'ux-studio' ),
		'effect' => __( 'The secret is stored encrypted and never shown again. Leave the field empty to keep the current one; fill it only to replace it.', 'ux-studio' ),
		'risk'   => __( 'A wrong or old secret makes every third-party login fail. Anyone who learns the secret could forge a login, so never paste it anywhere else.', 'ux-studio' ),
	),
	'enabled_providers'   => array(
		'does'   => __( 'Chooses which login buttons appear on the login page. A provider also has to be set up in the central app and enabled there for this site.', 'ux-studio' ),
		'effect' => __( 'Visitors with an account at the chosen provider can sign in with one click instead of a password.', 'ux-studio' ),
		'off'    => __( 'Leave a provider unticked when you do not want people to use it. A button for a provider that is not set up centrally only leads to an error.', 'ux-studio' ),
	),
	'allowed_roles'       => array(
		'does'   => __( 'Only users whose role is ticked here may log in through a provider, and only accounts with these roles are created automatically.', 'ux-studio' ),
		'effect' => __( 'Typically you tick only the roles of customers or members. Everybody else keeps using the normal login.', 'ux-studio' ),
		'risk'   => __( 'With nothing ticked nobody can use third-party login at all. Ticking Administrator means a hacked provider account can open your admin, so do it only when you really need it.', 'ux-studio' ),
	),
	'auto_create_enabled' => array(
		'does'   => __( 'When somebody signs in with a provider and this site does not know them yet, a new WordPress account is created on the spot. The account gets the e-mail address from the provider, a username made from it, a long random password and the role chosen below. An existing account is never taken over: when the e-mail address already belongs to a user, the login is refused and that user must link the provider from the profile after a normal login.', 'ux-studio' ),
		'effect' => __( 'Visitors register in one click, without a form. Your user list grows with every new person.', 'ux-studio' ),
		'risk'   => __( 'Anyone who has an account at an enabled provider (for example any Google account) can get an account on your site, with the role chosen below. Pick the lowest possible role, and expect spam registrations on a public site. Accounts of roles that are not ticked in Allowed roles are still created, but cannot log in.', 'ux-studio' ),
		'off'    => __( 'Leave it off when only people you know should have an account. They then link the provider from their profile.', 'ux-studio' ),
	),
	'auto_create_role'    => array(
		'does'   => __( 'The role a new account gets when it is created automatically. Administrator cannot be chosen here.', 'ux-studio' ),
		'effect' => __( 'The role decides what the new person may do on the site. Subscriber can only manage their own profile.', 'ux-studio' ),
		'risk'   => __( 'Every person who signs in this way gets this role, so a powerful role such as Editor gives strangers access to your content. The role must also be ticked in Allowed roles, otherwise the new account cannot log in.', 'ux-studio' ),
	),
	'notice_enabled'      => array(
		'does'   => __( 'Shows a notice in the admin to allowed users who have not linked any provider yet. They can close it.', 'ux-studio' ),
		'effect' => __( 'More people link their Google or Facebook account, so they can log in with one click next time.', 'ux-studio' ),
		'off'    => __( 'When the notice is not wanted, for example on a site where users should not manage their own login.', 'ux-studio' ),
	),
	'notice_text'         => array(
		'does'   => __( 'The text of the "link your account" notice. Leave it empty to use the default sentence.', 'ux-studio' ),
		'effect' => __( 'Write a short invitation in the voice of your site. It is shown only to users who have not linked a provider yet.', 'ux-studio' ),
	),
);
