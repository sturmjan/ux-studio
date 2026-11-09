<?php
/**
 * Purposes of processing offered by the legal pages wizard, with the data, legal bases and a default retention.
 * Written for this plugin (cs and en). Read by LegalProfile and LegalBuilder.
 *
 * Shape: key => [
 *   'basis' => list of: contract | legal | consent | interest   (GDPR art. 6(1)(b), (c), (a), (f))
 *   'cs' / 'en' => [ 'title', 'data', 'why', 'interest' (what the legitimate interest is, optional), 'retention' ]
 * ]
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'orders'     => array(
		'basis' => array( 'contract', 'legal', 'interest' ),
		'cs'    => array(
			'title'     => 'Objednávky a plnění smlouvy',
			'data'      => 'jméno a příjmení nebo název firmy, fakturační a dodací adresa, e-mail, telefon, obsah objednávky, způsob platby a dopravy, číslo bankovního účtu při platbě převodem',
			'why'       => 'Abychom mohli přijmout vaši objednávku, doručit zboží nebo službu, přijmout platbu, vystavit doklad a vyřídit reklamaci nebo odstoupení od smlouvy. Zákon nám také ukládá vést účetnictví a odvádět daně.',
			'interest'  => 'ochrana našich právních nároků, například při sporu o zaplacení nebo o vadu zboží',
			'retention' => 'po dobu plnění smlouvy, dále doklady v rozsahu, který vyžadují předpisy o účetnictví a DPH (nejdéle 10 let), a ostatní údaje do konce promlčecí lhůty (obvykle 3 roky)',
		),
		'en'    => array(
			'title'     => 'Orders and performance of the contract',
			'data'      => 'name or company name, billing and delivery address, e-mail, telephone, contents of the order, chosen payment and delivery method, bank account number when you pay by transfer',
			'why'       => 'So that we can accept your order, deliver the goods or the service, receive the payment, issue an invoice and deal with a complaint or a withdrawal. The law also requires us to keep accounts and pay taxes.',
			'interest'  => 'protecting our legal claims, for example in a dispute about a payment or a defect',
			'retention' => 'while the contract is performed, then the documents the accounting and VAT rules require (up to 10 years), and other data until the limitation period ends (usually 3 years)',
		),
	),
	'accounts'   => array(
		'basis' => array( 'contract' ),
		'cs'    => array(
			'title'     => 'Zákaznický účet',
			'data'      => 'přihlašovací e-mail nebo jméno, heslo (ukládá se jen v zašifrované podobě), adresy, historie objednávek, uložené preference',
			'why'       => 'Abyste se mohli přihlásit, nakupovat rychleji a vidět své objednávky.',
			'interest'  => '',
			'retention' => 'po dobu existence účtu; po jeho zrušení údaje smažeme nebo anonymizujeme, mimo doklady, které musíme uchovat podle zákona',
		),
		'en'    => array(
			'title'     => 'Customer account',
			'data'      => 'login e-mail or user name, password (stored only in encrypted form), addresses, order history, saved preferences',
			'why'       => 'So that you can sign in, check out faster and see your orders.',
			'interest'  => '',
			'retention' => 'while the account exists; after it is closed we delete or anonymise the data, except documents we must keep by law',
		),
	),
	'contact'    => array(
		'basis' => array( 'interest', 'contract' ),
		'cs'    => array(
			'title'     => 'Dotazy a komunikace s vámi',
			'data'      => 'jméno, e-mail, telefon a obsah zprávy, kterou nám pošlete formulářem, e-mailem nebo telefonem',
			'why'       => 'Abychom vám odpověděli, případně připravili nabídku nebo smlouvu, o kterou jste projevili zájem.',
			'interest'  => 'odpovědět na vaše dotazy a vést o nich záznam pro případ navazující komunikace',
			'retention' => 'do vyřízení dotazu a poté obvykle 12 měsíců po poslední komunikaci; vznikla-li smlouva, řídí se dobou uvedenou u objednávek',
		),
		'en'    => array(
			'title'     => 'Enquiries and communication with you',
			'data'      => 'name, e-mail, telephone and the content of the message you send us through a form, by e-mail or by telephone',
			'why'       => 'So that we can answer you and, if you asked for it, prepare an offer or a contract.',
			'interest'  => 'answering your questions and keeping a record of them in case the conversation continues',
			'retention' => 'until the enquiry is dealt with and then usually 12 months after the last contact; if a contract follows, the period given for orders applies',
		),
	),
	'newsletter' => array(
		'basis' => array( 'consent' ),
		'cs'    => array(
			'title'     => 'Newsletter a obchodní sdělení',
			'data'      => 'e-mailová adresa, případně jméno, a záznam o tom, kdy a jak jste souhlas udělili',
			'why'       => 'Abychom vám posílali novinky, nabídky a slevy, o které jste požádali.',
			'interest'  => '',
			'retention' => 'do odvolání souhlasu; záznam o souhlasu uchováváme po dobu, kdy souhlas platil, a ještě krátce poté pro případ sporu',
		),
		'en'    => array(
			'title'     => 'Newsletter and commercial messages',
			'data'      => 'e-mail address, possibly your name, and a record of when and how you gave your consent',
			'why'       => 'So that we can send you the news, offers and discounts you asked for.',
			'interest'  => '',
			'retention' => 'until you withdraw your consent; the record of the consent is kept while it was valid and shortly afterwards in case of a dispute',
		),
	),
	'reviews'    => array(
		'basis' => array( 'interest' ),
		'cs'    => array(
			'title'     => 'Recenze a hodnocení',
			'data'      => 'zobrazované jméno, text a hodnocení, datum, e-mail (ten se nezveřejňuje) a informace, zda šlo o ověřený nákup',
			'why'       => 'Abychom zveřejnili vaši recenzi a ostatním zákazníkům usnadnili rozhodování.',
			'interest'  => 'zveřejňovat zkušenosti zákazníků a chránit se před falešnými hodnoceními',
			'retention' => 'po dobu zveřejnění recenze, nejdéle 5 let od jejího vložení; smazání můžete kdykoli požadovat',
		),
		'en'    => array(
			'title'     => 'Reviews and ratings',
			'data'      => 'display name, text and rating, date, e-mail (which is not published) and whether the review follows a verified purchase',
			'why'       => 'So that we can publish your review and help other customers decide.',
			'interest'  => 'publishing the experience of customers and protecting against fake reviews',
			'retention' => 'while the review is published, at most 5 years from the day it was posted; you can ask us to delete it at any time',
		),
	),
	'analytics'  => array(
		'basis' => array( 'consent' ),
		'cs'    => array(
			'title'     => 'Statistika návštěvnosti',
			'data'      => 'online identifikátory z cookies, informace o zařízení a prohlížeči, navštívené stránky, přibližná poloha odvozená z IP adresy',
			'why'       => 'Abychom věděli, které stránky lidé čtou a jak web používají, a mohli ho zlepšovat. Výsledky používáme souhrnně.',
			'interest'  => '',
			'retention' => 'podle doby platnosti jednotlivých cookies uvedené v zásadách cookies; statistiky u poskytovatele podle jeho nastavení',
		),
		'en'    => array(
			'title'     => 'Visit statistics',
			'data'      => 'online identifiers from cookies, device and browser information, pages visited, approximate location derived from the IP address',
			'why'       => 'So that we know which pages people read and how the site is used, and can improve it. We use the results in aggregate.',
			'interest'  => '',
			'retention' => 'as long as the individual cookies last, which the cookie policy shows; statistics at the provider according to its settings',
		),
	),
	'marketing'  => array(
		'basis' => array( 'consent' ),
		'cs'    => array(
			'title'     => 'Reklama a měření reklam',
			'data'      => 'online identifikátory z cookies, informace o zařízení, navštívené stránky a akce na webu (například vložení zboží do košíku)',
			'why'       => 'Abychom měřili účinnost reklamy a zobrazovali nabídky, které by vás mohly zajímat, i na jiných webech a v sociálních sítích.',
			'interest'  => '',
			'retention' => 'podle doby platnosti jednotlivých cookies uvedené v zásadách cookies, nejdéle do odvolání souhlasu',
		),
		'en'    => array(
			'title'     => 'Advertising and ad measurement',
			'data'      => 'online identifiers from cookies, device information, pages visited and actions on the site (for example adding goods to the basket)',
			'why'       => 'So that we can measure how well our advertising works and show offers that may interest you, also on other sites and social networks.',
			'interest'  => '',
			'retention' => 'as long as the individual cookies last, which the cookie policy shows, and no longer than until you withdraw your consent',
		),
	),
	'security'   => array(
		'basis' => array( 'interest', 'legal' ),
		'cs'    => array(
			'title'     => 'Provoz a zabezpečení webu',
			'data'      => 'IP adresa, datum a čas přístupu, požadovaná adresa, údaje o prohlížeči, záznamy o pokusech o přihlášení',
			'why'       => 'Abychom web udrželi v chodu, odhalili zneužití a útoky a mohli vyšetřit bezpečnostní incident.',
			'interest'  => 'chránit web, jeho návštěvníky a naše systémy před zneužitím a útoky',
			'retention' => 'záznamy uchováváme jen po dobu nezbytnou k zabezpečení, obvykle několik měsíců',
		),
		'en'    => array(
			'title'     => 'Running and securing the website',
			'data'      => 'IP address, date and time of access, requested address, browser details, records of sign-in attempts',
			'why'       => 'So that we can keep the site running, detect abuse and attacks and investigate a security incident.',
			'interest'  => 'protecting the site, its visitors and our systems against abuse and attacks',
			'retention' => 'logs are kept only as long as security requires, usually a few months',
		),
	),
);
