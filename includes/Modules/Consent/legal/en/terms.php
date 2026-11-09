<?php
/**
 * English terms and conditions template for a consumer online shop under Czech law (syntax: LegalTemplate). Written for
 * UX Studio, not a copy of any other generator.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>1. Introduction</h2>
<p>These terms set out how you buy in the online shop at {{site}}. The seller is <strong>{{controller}}</strong>, company number {{ico}}{% if has_dic %}, VAT number {{dic}}{% endif %}, with its seat at {{address}}{% if has_register %} ({{register_note}}){% endif %}. You can contact us by e-mail at {{email}}{% if has_phone %} or by telephone on {{phone}}{% endif %}.</p>
<p>The sales contract is governed by Czech law, in particular Act No. 89/2012 Coll. (the Civil Code) and Act No. 634/1992 Coll. (the Consumer Protection Act). A consumer is anyone who buys from us outside their business or other independent gainful activity. The provisions meant for consumers do not apply to businesses buying for their business unless it is said so expressly.</p>
<p>A different arrangement in the contract takes precedence over these terms.</p>

<h2>2. Order and conclusion of the contract</h2>
<p>The goods we offer are described with their main features. Photographs are illustrative unless stated otherwise. You put goods in the basket, enter delivery and billing details, choose delivery and payment and send the order with the button marked as a binding order. Before sending you can check the order and correct mistakes.</p>
<p>By sending the order you offer to conclude a sales contract. The contract comes into being only when we confirm receipt of the order by e-mail; the confirmation also contains a copy of these terms or a link to them. If we have a serious reason not to accept an order, for example an obvious price error or goods being unavailable, we will tell you without undue delay and refund anything you have paid.</p>
<p>You bear the cost of the means of distance communication (internet connection, telephone); it does not differ from the basic rate.</p>

<h2>3. Price and payment</h2>
<p>Prices on the site are in {{currency}} {% if prices_with_tax %}including value added tax{% else %}excluding value added tax; the tax is added in the basket{% endif %}. The cost of delivery and any other charges is shown in the basket before you send the order and is part of the total price you pay.</p>
{% if has_payment %}<p>You can pay in these ways:</p>
{{{payment_html}}}{% else %}<p>Payment methods are shown in the basket when you complete the order.</p>{% endif %}
{% if has_payment_note %}<p>{{payment_note}}</p>{% endif %}
<p>If you pay in advance, we dispatch the goods after the payment reaches our account or is confirmed by the payment gateway. When you pay by card or through a payment gateway, your card details do not reach us; the operator of the gateway processes them.</p>
<p>We issue the tax document electronically or enclose it in the parcel.</p>

<h2>4. Delivery</h2>
{% if has_shipping %}<p>We send goods in these ways:</p>
{{{shipping_html}}}{% else %}<p>The available delivery methods and their prices are shown in the basket.</p>{% endif %}
{% if has_delivery_note %}<p>{{delivery_note}}</p>{% endif %}
<p>The delivery time depends on the availability of the goods and the chosen delivery method, and is stated with the goods or in the order confirmation. If we do not deliver on time, you can ask us to deliver within an additional period; if we do not deliver then either, you can withdraw from the contract.</p>
<p>When you receive a parcel from the carrier, check whether the packaging is damaged. If it is, write a record with the carrier and let us know. This does not limit your rights for defects, it only makes them easier to prove.</p>

<h2>5. Withdrawal from the contract within 14 days</h2>
<p>If you are a consumer, you can withdraw from a distance contract <strong>without giving a reason within 14 days</strong> (section 1829 of the Civil Code). The period starts on the day you, or a person you named other than the carrier, take over the goods. If several kinds of goods or parts of a consignment are delivered, it runs from the delivery of the last one. It is enough to send the withdrawal before the period ends.</p>
<p>You can withdraw by any unambiguous statement, for example by e-mail to {{email}} or by letter to {{withdrawal_address}}. You may use the model form at the end of these terms, but you do not have to.</p>
<p>Send or hand the goods back to {{withdrawal_address}} within 14 days of withdrawing. Return them undamaged, if possible in the original packaging and with proof of purchase.{% if return_by_customer %} You bear the cost of returning the goods.{% endif %}{% if return_by_seller %} We bear the cost of returning the goods.{% endif %} You are liable for any reduction in the value of the goods caused by handling them in a way beyond what is needed to establish their nature and features.</p>
<p>We will refund the money you paid, including the cost of delivery to you (the cheapest method we offered), within 14 days of the withdrawal, in the same way you paid unless you agree to another. We may wait with the refund until we receive the goods or you prove that you sent them back.</p>
<p><strong>You cannot withdraw</strong>, among other cases (section 1837 of the Civil Code), from a contract for:</p>
<ul>
<li>goods made to your specification or personalised for you;</li>
<li>perishable goods and goods that were irreversibly mixed with other goods after delivery;</li>
<li>sealed goods that cannot be returned for health or hygiene reasons and whose seal you have broken;</li>
<li>sound or video recordings and computer programs whose original packaging you have opened;</li>
<li>digital content not supplied on a tangible medium, if we began supplying it with your prior express consent and you acknowledged that you lose the right to withdraw;</li>
<li>newspapers, periodicals and magazines, except subscriptions;</li>
<li>accommodation, transport, catering or leisure services provided on a specific date or period.</li>
</ul>
<p>The law contains the full list of exceptions. If you are not sure whether one applies to your order, write to us before buying.</p>

<h2>6. Defects and complaints</h2>
<p>We are responsible for the goods being free of defects when you take them over. In particular they must match the description, have the features we stated or you could reasonably expect, be fit for the purpose goods of that kind are usually used for, and come with accessories and instructions. The warranty period for goods sold to a consumer is 24 months from takeover (for used goods it may be shorter, but not less than 12 months). For a consumer the law presumes that a defect which appears within the period set by law after takeover was there at takeover, unless the opposite is shown or the nature of the goods rules it out.</p>
<p>If the goods have a defect, you can ask for repair or replacement. If that is not possible or we do not do it in time, you can ask for a reasonable discount or withdraw from the contract. You can withdraw at once if the breach of contract is material.</p>
<p>Make a complaint as soon as you notice the defect, at {{complaints_address}} or by e-mail to {{complaints_email}}. Send the goods, describe the defect, say what remedy you want and enclose proof of purchase. We will confirm when you made the complaint and what it contains. We decide on the complaint at once, in complex cases within three working days (time needed for an expert assessment of the defect is not counted). We will handle the complaint, including the repair, within 30 days of receiving it unless we agree on a longer period with you. When it is settled we give you a written confirmation of the date and manner. We bear the costs necessary to make the complaint.</p>
<p>Rights from defects do not apply if the defect was caused by incorrect use, mechanical damage or ordinary wear.</p>

<h2>7. Out-of-court dispute resolution</h2>
<p>If we cannot settle a dispute together, you can turn to the body for out-of-court resolution of consumer disputes, which is the <strong>Czech Trade Inspection Authority (Česká obchodní inspekce)</strong>, Štěpánská 567/15, 120 00 Prague 2, <a href="https://adr.coi.cz" rel="noopener noreferrer">adr.coi.cz</a>. A proposal must be submitted to it within one year of the day you first asserted with us the right that is the subject of the dispute. The Czech Trade Inspection Authority also supervises compliance with consumer protection rules.</p>

<h2>8. Personal data</h2>
<p>We process personal data to the extent and under the conditions described in the {% if has_privacy_url %}<a href="{{privacy_url}}">privacy policy</a>{% else %}privacy policy on this site{% endif %}.</p>

<h2>9. Final provisions</h2>
<p>We may change these terms. An order is governed by the terms as they stood on the day you sent it; on request we will send you the version stored with your order. Earlier versions can be read below.</p>
<p>If any provision of these terms is invalid, the others remain valid. An arrangement that would deprive a consumer of protection under mandatory provisions of the law does not apply to them.</p>

<h2>Annex: model withdrawal form</h2>
<p>(Complete and return this form only if you wish to withdraw from the contract.)</p>
<p>To: {{controller}}, {{withdrawal_address}}, {{email}}</p>
<p>I hereby give notice that I withdraw from my contract for the purchase of these goods: ……………………</p>
<p>Ordered on / received on: ……………………</p>
<p>Name of consumer: ……………………</p>
<p>Address of consumer: ……………………</p>
<p>Bank account number for the refund: ……………………</p>
<p>Date: ……………………  Signature (only if this form is notified on paper): ……………………</p>
TPL;
