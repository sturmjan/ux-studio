<?php
/**
 * English privacy policy template (syntax: LegalTemplate). Written for UX1 Studio, not a copy of any other generator.
 * Structure follows Articles 13 and 14 GDPR.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>Who processes your data</h2>
<p>The controller of personal data is <strong>{{controller}}</strong>{% if has_ico %}, company number {{ico}}{% endif %}{% if has_dic %}, VAT number {{dic}}{% endif %}, with its seat at {{address}}{% if has_register %} ({{register_note}}){% endif %}. The website on which you are reading this policy is {{site}}.</p>
<p>For questions about personal data you can write to {{email}}{% if has_phone %} or call {{phone}}{% endif %}.</p>
{% if has_dpo %}<p>Data protection is supervised here by a data protection officer, who can be reached at: {{dpo_contact}}.</p>{% else %}<p>We have not appointed a data protection officer because the law does not require it. We deal with all questions at the contact above.</p>{% endif %}

<h2>Why we need data and on what basis</h2>
<p>We process data only for the purposes described below. For each purpose we say which data is used, why, on what legal basis and how long we keep it.</p>
{{{purposes_html}}}

<h2>Where the data comes from</h2>
<p>Most of the data comes from you, when you fill in a form{% if has_eshop %}, order{% endif %}, write to us{% if has_eshop %} or create an account{% endif %}. Some arises when you visit the site: the server records technical details of each access, and cookies and similar technologies show how the site is used, but only to the extent you allowed in the cookie banner. The detailed list is in the {% if has_cookies_url %}<a href="{{cookies_url}}">cookie policy</a>{% else %}cookie policy{% endif %}.</p>

<h2>Who receives the data</h2>
<p>We do not sell your data. For some activities we use other companies, which process data on our instructions under a contract, or which receive it because fulfilling an order or the law requires it.</p>
{% if has_recipients %}<p>These are in particular:</p>
{{{recipients_html}}}{% else %}<p>At the moment we have no other recipients, except public authorities to which we give data when the law requires it.</p>{% endif %}
<p>We give data to public authorities (for example the tax office, courts or the police) only when the law obliges us to.</p>

{% if has_transfers %}<h2>Transfers outside the European Economic Area</h2>
<p>Some of the recipients above also process data outside the European Economic Area. We send data to such a country only if the European Commission has decided that it ensures adequate protection, or if the transfer is secured by standard contractual clauses or another safeguard under Chapter V of the GDPR. In particular:</p>
{{{transfers_html}}}
<p>We will give you a copy of the safeguards on request.</p>{% else %}<h2>Transfers outside the European Economic Area</h2>
<p>As far as we know, we do not transfer personal data outside the European Economic Area. If that changes, we will update this policy and state on what basis the transfer takes place.</p>{% endif %}

<h2>How long we keep the data</h2>
<p>The periods are given for each purpose above. As a rule we delete or anonymise data as soon as the reason for which we collected it ends. Where we need data to establish, exercise or defend legal claims, we keep it until the limitation period ends.</p>

<h2>Do you have to give us the data?</h2>
<p>Giving data is voluntary. Without some of it, however, we cannot do what you ask: {% if has_eshop %}without an address we cannot deliver an order, {% endif %}without an e-mail or phone number we cannot reply. For data we need because of a legal duty, such as accounting, giving it is necessary for a contract to be concluded. You do not have to give data we asked consent for, and nothing happens if you do not consent or if you withdraw consent.</p>

<h2>Automated decision-making</h2>
<p>We do not take decisions about you that rest solely on automated processing and have legal effects on you or similarly significantly affect you. {% if uses_consent %}If you allow marketing cookies, advertising providers may build a profile of your interests from them so that we can show you more suitable offers. This happens only with your consent, which you can withdraw in the cookie settings.{% endif %}</p>

<h2>Your rights</h2>
<p>Under the GDPR you have these rights:</p>
<ul>
<li><strong>Access</strong> (Art. 15): you can ask whether we process your data and get a copy with information about the processing.</li>
<li><strong>Rectification</strong> (Art. 16): you can have inaccurate data corrected and incomplete data completed.</li>
<li><strong>Erasure</strong> (Art. 17): you can ask us to delete data we no longer need, that you gave on consent you have withdrawn, or that is processed unlawfully. It does not apply to data we must keep by law or need to defend our claims.</li>
<li><strong>Restriction</strong> (Art. 18): in some cases you can ask us only to store the data and do nothing else with it.</li>
<li><strong>Portability</strong> (Art. 20): data you gave us under a contract or consent and that we process automatically you can get in a structured, commonly used format and pass to another controller.</li>
<li><strong>Objection</strong> (Art. 21): you can object to processing based on legitimate interest on grounds relating to your situation. You can object to processing for direct marketing at any time without giving a reason.</li>
<li><strong>Withdrawal of consent</strong> (Art. 7(3)): you can withdraw consent at any time, as easily as you gave it. Withdrawal does not affect the lawfulness of processing carried out before it.</li>
</ul>
<p>To use your rights, write to {{email}}. We will answer within one month; in complex cases we may extend that by two more months and will tell you in time. To make sure nobody obtains data about someone else, we may ask you to prove your identity.</p>

<h2>Complaint to a supervisory authority</h2>
<p>If you believe we handle personal data contrary to the rules, you have the right to lodge a complaint with a supervisory authority. In the Czech Republic it is {{authority}}{% if authority_url %}, <a href="{{authority_url}}" rel="noopener noreferrer">{{authority_url}}</a>{% endif %}. We would be glad if you contacted us first so that we can try to put it right.</p>

<h2>Security</h2>
<p>We protect data with technical and organisational measures proportionate to the risk: traffic between your browser and the site is encrypted, only people who need the data for their work can reach it, passwords are stored only in encrypted form and systems are kept up to date.</p>

<h2>Changes to this policy</h2>
<p>We change this policy when the way we process data or the rules change. Each version shows from when it applies, and earlier versions can be read below.</p>
TPL;
