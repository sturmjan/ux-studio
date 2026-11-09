<?php
/**
 * English cookie policy template (syntax: LegalTemplate). Written for UX Studio, not a copy of any other generator.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>What this document is about</h2>
<p>When you visit {{site}}, the site stores a small amount of data on your device and reads it back later. We call these cookies, although items in the browser storage (localStorage) work in much the same way. This page lists exactly what we store and why, how long it lasts and who is behind it. It also explains how to change your choice.</p>

<h2>Who is responsible for the site</h2>
<p>The site is run, and the data collected through cookies is controlled, by <strong>{{controller}}</strong>{% if has_ico %}, company number {{ico}}{% endif %}{% if has_dic %}, VAT number {{dic}}{% endif %}, with its seat at {{address}}. You can reach us at {{email}}{% if has_phone %} or by telephone on {{phone}}{% endif %}.{% if has_privacy_url %} How we handle personal data in general is described in our <a href="{{privacy_url}}">privacy policy</a>.{% endif %}</p>

<h2>When we ask for consent and when we do not</h2>
<p>Under section 89(3) of the Czech Electronic Communications Act (No. 127/2005 Coll.) we may store data on your device only if you agree. The exception is storage that is strictly necessary to provide a service you explicitly asked for, or to carry a message over a network. So:</p>
<ul>
<li>we use necessary cookies without consent, because the site would not work without them;</li>
<li>we use cookies for preferences, statistics and marketing only after you allow them in the banner shown on your first visit. Until you decide, they stay off;</li>
<li>consent is not a condition for looking at the site. The button to refuse is as easy to see as the button to accept.</li>
</ul>
<p>Where cookies also process personal data, the GDPR applies too. The privacy policy explains your rights and how we protect the data.</p>

<h2>How to change or withdraw your choice</h2>
<p>Withdrawing consent is as easy as giving it. You can open the cookie settings at any time and choose differently:</p>
<p>{{{cookie_button}}}</p>
<p>After you withdraw consent we reload the page so that the services you switched off stop running. Cookies already in your browser can also be deleted in its settings (usually under Privacy or History). Deleting them in the browser does not stop them from being stored again on your next visit if you allowed them in the banner.</p>

<h2>How long your choice is remembered</h2>
<p>We remember your decision for at most {{consent_days}} days, in a cookie called uxs_consent. After that, or when the services on the site change substantially, we ask you again.</p>
{% if log_on %}<p>So that we can show, in case of doubt, that you really gave consent, we keep a record of each decision: a random identification number, the time, the version of the settings, the categories allowed, the kind of answer and the address of the page without parameters. We do not store your IP address or browser details. Records are deleted after {{log_years}}. You can find the number of your decision in the cookie settings; if you tell it to us, we can look the records up.</p>{% else %}<p>We do not keep records of individual decisions on the server.</p>{% endif %}

<h2>Cookies and services this site uses</h2>
<p>The list below is built automatically from what we found and configured on the site. The descriptions of cookies come from the providers and from a public cookie database, and say what a service usually uses them for. We do not control the settings of third-party cookies, so each entry names the provider and links to its policy.</p>
{{{cookie_tables}}}

{% if has_transfers %}<h2>Transfers of data outside Europe</h2>
<p>Some of the providers above are based or process data outside the European Economic Area. A transfer takes place only if you allow the services, and only under Chapter V of the GDPR: a decision of the European Commission that the protection is adequate, standard contractual clauses or another lawful safeguard:</p>
{{{transfers_html}}}{% endif %}

<h2>How to block cookies in your browser</h2>
<p>You can also limit cookies in the browser itself, either altogether or only for third parties. The help of your browser explains how, usually under the privacy and security settings. If you block necessary cookies too, parts of the site (for example sign-in or the basket) may stop working.</p>

<h2>Changes to this policy</h2>
<p>We update this policy when the services on the site or the rules change. Each version shows from when it applies, and earlier versions can be read below. If you have a question, write to {{email}}.</p>
TPL;
