<?php
/**
 * Czech privacy policy template (syntax: LegalTemplate). Written for UX Studio, not a copy of any other generator.
 * Structure follows Articles 13 and 14 GDPR.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>Kdo vaše údaje zpracovává</h2>
<p>Správcem osobních údajů je <strong>{{controller}}</strong>{% if has_ico %}, IČO {{ico}}{% endif %}{% if has_dic %}, DIČ {{dic}}{% endif %}, se sídlem {{address}}{% if has_register %} ({{register_note}}){% endif %}. Webová stránka, na které tyto zásady čtete, je {{site}}.</p>
<p>S otázkami k osobním údajům se na nás můžete obrátit na e-mailu {{email}}{% if has_phone %} nebo na telefonu {{phone}}{% endif %}.</p>
{% if has_dpo %}<p>Dohled nad ochranou osobních údajů u nás vykonává pověřenec pro ochranu osobních údajů. Dosáhnete na něj zde: {{dpo_contact}}.</p>{% else %}<p>Pověřence pro ochranu osobních údajů nemáme, protože nám to zákon neukládá. Všechny dotazy vyřizujeme na výše uvedeném kontaktu.</p>{% endif %}

<h2>K čemu údaje potřebujeme a na jakém základě</h2>
<p>Údaje zpracováváme jen k účelům, které jsou popsány níže. U každého účelu uvádíme, jaké údaje se používají, proč, na jakém právním základě a jak dlouho je uchováváme.</p>
{{{purposes_html}}}

<h2>Odkud údaje máme</h2>
<p>Většinu údajů nám sdělíte sami, když vyplníte formulář, objednáte, napíšete nám nebo si založíte účet. Další údaje vznikají při návštěvě webu: server si zaznamenává technické údaje o přístupu a pomocí cookies a podobných technologií se zjišťuje, jak web používáte, ale jen v rozsahu, který jste v liště cookies povolili. Podrobný seznam najdete v {% if has_cookies_url %}<a href="{{cookies_url}}">zásadách používání cookies</a>{% else %}zásadách používání cookies{% endif %}.</p>

<h2>Komu údaje předáváme</h2>
<p>Vaše údaje nikomu neprodáváme. Pro některé činnosti využíváme další firmy, které údaje zpracovávají podle našich pokynů a na základě smlouvy, nebo které je dostávají proto, že to vyžaduje splnění objednávky či zákon.</p>
{% if has_recipients %}<p>Jsou to zejména:</p>
{{{recipients_html}}}{% else %}<p>V tuto chvíli neevidujeme žádné další příjemce kromě orgánů veřejné moci, kterým údaje poskytujeme, pokud to po nás vyžaduje zákon.</p>{% endif %}
<p>Státním orgánům (například finančnímu úřadu, soudům nebo policii) předáváme údaje jen tehdy, když nám to ukládá zákon.</p>

{% if has_transfers %}<h2>Předání mimo Evropský hospodářský prostor</h2>
<p>Někteří z příjemců výše zpracovávají údaje také mimo Evropský hospodářský prostor. Do takové země údaje odesíláme jen tehdy, když pro ni Evropská komise vydala rozhodnutí o odpovídající ochraně, nebo když je předání zajištěno standardními smluvními doložkami či jinou zárukou podle kapitoly V GDPR. Konkrétně jde o:</p>
{{{transfers_html}}}
<p>Kopii záruk vám na požádání poskytneme.</p>{% else %}<h2>Předání mimo Evropský hospodářský prostor</h2>
<p>Podle našich dostupných informací osobní údaje mimo Evropský hospodářský prostor nepředáváme. Kdyby se to změnilo, tyto zásady upravíme a uvedeme, na jakém základě k předání dochází.</p>{% endif %}

<h2>Jak dlouho údaje uchováváme</h2>
<p>Konkrétní lhůty jsou uvedeny u každého účelu výše. Obecně platí, že údaje mažeme nebo anonymizujeme, jakmile pomine důvod, pro který jsme je získali. Pokud údaje potřebujeme k určení, výkonu nebo obhajobě právních nároků, ponecháme je do konce promlčecí lhůty.</p>

<h2>Musíte nám údaje poskytnout?</h2>
<p>Poskytnutí údajů je dobrovolné. Bez některých údajů vám ale nemůžeme splnit, o co žádáte: bez adresy a kontaktu nedoručíme objednávku, bez e-mailu vám neodpovíme. U údajů, které potřebujeme kvůli zákonné povinnosti, například pro účetnictví, je jejich poskytnutí nutné, aby bylo možné smlouvu uzavřít. Údaje, ke kterým jsme požádali o souhlas, poskytovat nemusíte a nic se nestane, když souhlas neudělíte nebo ho odvoláte.</p>

<h2>Automatizované rozhodování</h2>
<p>O vás nerozhodujeme způsobem, který by se opíral výhradně o automatizované zpracování a měl pro vás právní účinky nebo se vás podobně významně dotýkal. {% if uses_consent %}Pokud povolíte marketingové cookies, mohou poskytovatelé reklamních služeb vytvářet na jejich základě profil vašich zájmů, abychom vám zobrazili vhodnější nabídky. To se děje jen s vaším souhlasem, který můžete odvolat v nastavení cookies.{% endif %}</p>

<h2>Vaše práva</h2>
<p>Podle GDPR máte tato práva:</p>
<ul>
<li><strong>Přístup</strong> (čl. 15): můžete se zeptat, zda vaše údaje zpracováváme, a získat jejich kopii spolu s informacemi o zpracování.</li>
<li><strong>Oprava</strong> (čl. 16): můžete chtít opravit nepřesné nebo doplnit neúplné údaje.</li>
<li><strong>Výmaz</strong> (čl. 17): můžete chtít, abychom údaje smazali, pokud je už nepotřebujeme, odvoláte souhlas nebo jsou zpracovávány protiprávně. Nelze ho uplatnit u údajů, které musíme uchovat podle zákona nebo potřebujeme k obhajobě svých nároků.</li>
<li><strong>Omezení zpracování</strong> (čl. 18): v určitých případech můžete chtít, abychom údaje jen uložili a dále s nimi nenakládali.</li>
<li><strong>Přenositelnost</strong> (čl. 20): údaje, které jste nám dali na základě smlouvy nebo souhlasu a které zpracováváme automatizovaně, můžete získat ve strukturovaném, běžně používaném formátu a předat jinému správci.</li>
<li><strong>Námitka</strong> (čl. 21): proti zpracování na základě oprávněného zájmu můžete podat námitku z důvodů, které se týkají vaší situace. Proti zpracování pro přímý marketing můžete namítat kdykoli a bez udání důvodu.</li>
<li><strong>Odvolání souhlasu</strong> (čl. 7 odst. 3): souhlas můžete kdykoli odvolat, stejně snadno, jako jste ho dali. Odvolání souhlasu se nedotýká zákonnosti zpracování, které proběhlo před ním.</li>
</ul>
<p>Svá práva uplatníte e-mailem na {{email}}. Odpovíme vám nejpozději do jednoho měsíce; ve složitých případech můžeme lhůtu prodloužit o další dva měsíce a včas vám to oznámíme. Aby nikdo nezískal cizí údaje, můžeme vás požádat o ověření totožnosti.</p>

<h2>Stížnost u dozorového úřadu</h2>
<p>Pokud se domníváte, že s osobními údaji nakládáme v rozporu s předpisy, máte právo podat stížnost u dozorového úřadu. V České republice je jím {{authority}}{% if authority_url %}, <a href="{{authority_url}}" rel="noopener noreferrer">{{authority_url}}</a>{% endif %}. Budeme rádi, když se nejdřív obrátíte na nás a pokusíme se věc vyřešit.</p>

<h2>Zabezpečení</h2>
<p>Údaje chráníme technickými a organizačními opatřeními přiměřenými riziku: přenos mezi vaším prohlížečem a webem je šifrovaný, přístup k údajům mají jen ti, kdo je potřebují k práci, hesla se ukládají jen v zašifrované podobě a systémy aktualizujeme.</p>

<h2>Změny těchto zásad</h2>
<p>Zásady měníme, když se změní způsob, jakým údaje zpracováváme, nebo předpisy. U každé verze je uvedeno, od kdy platí, a starší verze jsou dole k nahlédnutí.</p>
TPL;
