<?php
/**
 * Czech cookie policy template (syntax: LegalTemplate). Written for UX Studio, not a copy of any other generator.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>O čem tento dokument je</h2>
<p>Web {{site}} si při návštěvě ukládá do vašeho zařízení malé množství dat a čte je zpět. Říkáme tomu cookies, i když stejně fungují například i položky v úložišti prohlížeče (localStorage). Na této stránce najdete přesný seznam toho, co a proč ukládáme, jak dlouho to vydrží a kdo za tím stojí. Najdete tu také návod, jak svou volbu změnit.</p>

<h2>Kdo za web odpovídá</h2>
<p>Provozovatelem webu a správcem údajů, které cookies shromažďují, je <strong>{{controller}}</strong>{% if has_ico %}, IČO {{ico}}{% endif %}{% if has_dic %}, DIČ {{dic}}{% endif %}, se sídlem {{address}}. Ptát se nás můžete na {{email}}{% if has_phone %} nebo telefonicky na {{phone}}{% endif %}.{% if has_privacy_url %} Jak zacházíme s osobními údaji obecně, popisují <a href="{{privacy_url}}">zásady ochrany osobních údajů</a>.{% endif %}</p>

<h2>Kdy se ptáme na souhlas a kdy ne</h2>
<p>Podle § 89 odst. 3 zákona č. 127/2005 Sb., o elektronických komunikacích, smíme do vašeho zařízení ukládat data jen tehdy, když s tím souhlasíte. Výjimkou je uložení, které je nezbytné pro to, abychom vám mohli poskytnout službu, kterou jste si na webu výslovně vyžádali, nebo pro přenos zprávy po síti. Proto:</p>
<ul>
<li>nezbytné cookies používáme bez souhlasu, protože bez nich by web nefungoval;</li>
<li>cookies pro předvolby, statistiku a marketing používáme až poté, co je v liště, která se zobrazí při první návštěvě, povolíte. Dokud nerozhodnete, zůstávají vypnuté;</li>
<li>souhlas není podmínkou pro to, abyste si web prohlédli. Tlačítko pro odmítnutí je v liště stejně vidět jako tlačítko pro přijetí.</li>
</ul>
<p>Pokud cookies zároveň zpracovávají osobní údaje, vztahuje se na ně i nařízení GDPR. Podrobnosti o právech a o tom, jak údaje chráníme, jsou v zásadách ochrany osobních údajů.</p>

<h2>Jak svou volbu změnit nebo odvolat</h2>
<p>Odvolat souhlas je stejně snadné jako ho udělit. Kdykoli můžete otevřít nastavení cookies a zvolit jinak:</p>
<p>{{{cookie_button}}}</p>
<p>Po odvolání souhlasu stránku obnovíme, aby služby, které jste vypnuli, přestaly běžet. Cookies, která už ve vašem prohlížeči leží, můžete smazat také v jeho nastavení (obvykle v části Soukromí nebo Historie). Mazání z prohlížeče samo o sobě nezabrání tomu, aby se cookies při další návštěvě uložila znovu, pokud jste je v liště povolili.</p>

<h2>Jak dlouho se vaše volba pamatuje</h2>
<p>Vaše rozhodnutí si pamatujeme nejdéle {{consent_days}} dní v cookie s názvem uxs_consent. Po této době, nebo když podstatně změníme použité služby, se vás zeptáme znovu.</p>
{% if log_on %}<p>Abychom v případě pochybností dokázali, že jste souhlas skutečně udělili, ukládáme si o každém rozhodnutí záznam: náhodné identifikační číslo, čas, verzi nastavení, povolené kategorie, druh odpovědi a adresu stránky bez parametrů. Neukládáme IP adresu ani údaje o prohlížeči. Záznamy mažeme po {{log_years}}. Číslo svého rozhodnutí najdete v nastavení cookies; když nám ho sdělíte, dokážeme záznamy dohledat.</p>{% else %}<p>Záznamy o jednotlivých rozhodnutích na serveru neukládáme.</p>{% endif %}

<h2>Cookies a služby, které tento web používá</h2>
<p>Seznam níže se sestavuje automaticky z toho, co jsme na webu zjistili a nastavili. Popisy cookies vycházejí z údajů poskytovatelů a z veřejné databáze cookies a popisují, k čemu služba obvykle slouží. U cookies třetích stran nemáme jejich nastavení pod kontrolou, proto je vždy uveden poskytovatel a odkaz na jeho zásady.</p>
{{{cookie_tables}}}

{% if has_transfers %}<h2>Předávání údajů mimo Evropu</h2>
<p>Někteří poskytovatelé služeb výše sídlí nebo zpracovávají údaje mimo Evropský hospodářský prostor. K předání dochází jen, pokud služby povolíte, a jen za podmínek kapitoly V GDPR, tedy na základě rozhodnutí Evropské komise o odpovídající ochraně, standardních smluvních doložek nebo jiné zákonné záruky:</p>
{{{transfers_html}}}{% endif %}

<h2>Jak cookies zakázat v prohlížeči</h2>
<p>Ukládání cookies lze omezit i v samotném prohlížeči, buď celkově, nebo jen pro třetí strany. Návody najdete v nápovědě svého prohlížeče, nejčastěji v části nastavení věnované soukromí a zabezpečení. Pokud zablokujete i nezbytné cookies, některé části webu (například přihlášení nebo košík) nemusí fungovat.</p>

<h2>Změny těchto zásad</h2>
<p>Zásady upravujeme, když se změní služby na webu nebo předpisy. U každé verze uvádíme, od kdy platí, a starší verze jsou dole k nahlédnutí. Máte-li dotaz, napište na {{email}}.</p>
TPL;
