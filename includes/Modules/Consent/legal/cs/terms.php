<?php
/**
 * Czech terms and conditions template for a consumer online shop (syntax: LegalTemplate). Written for UX Studio, not a
 * copy of any other generator. Czech Civil Code (zákon č. 89/2012 Sb.) and the Consumer Protection Act (zákon č. 634/1992 Sb.).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return <<<'TPL'
<h2>1. Úvodní ustanovení</h2>
<p>Tyto obchodní podmínky upravují, jak nakupujete v internetovém obchodě na adrese {{site}}. Prodávajícím je <strong>{{controller}}</strong>, IČO {{ico}}{% if has_dic %}, DIČ {{dic}}{% endif %}, se sídlem {{address}}{% if has_register %} ({{register_note}}){% endif %}. Kontaktovat nás můžete e-mailem na {{email}}{% if has_phone %} nebo telefonicky na {{phone}}{% endif %}.</p>
<p>Kupní smlouva se řídí českým právem, zejména zákonem č. 89/2012 Sb., občanským zákoníkem, a zákonem č. 634/1992 Sb., o ochraně spotřebitele. Spotřebitelem je každý, kdo u nás nakupuje mimo svou podnikatelskou nebo jinou samostatnou výdělečnou činnost. Ustanovení určená spotřebitelům se na podnikatele, kteří nakupují pro své podnikání, nepoužijí, pokud to není výslovně uvedeno.</p>
<p>Odchylná ujednání ve smlouvě mají přednost před těmito podmínkami. Smlouva a podmínky jsou v češtině.</p>

<h2>2. Objednávka a vznik smlouvy</h2>
<p>Zboží, které nabízíme, je popsáno u každého výrobku včetně hlavních vlastností. Fotografie jsou ilustrační, pokud není uvedeno jinak. Zboží vložíte do košíku, vyplníte dodací a fakturační údaje, zvolíte dopravu a platbu a objednávku odešlete tlačítkem, které je označeno jako závazná objednávka. Před odesláním ji můžete zkontrolovat a opravit případné chyby.</p>
<p>Odesláním objednávky nabízíte uzavření kupní smlouvy. Smlouva vzniká až tím, že vám potvrdíme přijetí objednávky e-mailem; potvrzení obsahuje také kopii těchto podmínek, případně odkaz na ně. Pokud objednávku z vážného důvodu, například při zjevné chybě v ceně nebo při nedostupnosti zboží, nepřijmeme, dáme vám to bez zbytečného odkladu vědět a již zaplacenou částku vrátíme.</p>
<p>Náklady na prostředky komunikace na dálku (připojení k internetu, telefon) hradíte sami, nijak se neliší od běžné sazby.</p>

<h2>3. Cena a platba</h2>
<p>Ceny na webu jsou uvedeny v měně {{currency}} {% if prices_with_tax %}včetně daně z přidané hodnoty{% else %}bez daně z přidané hodnoty; daň se připočítá v košíku{% endif %}. Cena dopravy a případných dalších poplatků se zobrazí v košíku před odesláním objednávky a je součástí celkové ceny, kterou platíte.</p>
{% if has_payment %}<p>Zaplatit můžete těmito způsoby:</p>
{{{payment_html}}}{% else %}<p>Způsoby platby se zobrazí v košíku při dokončení objednávky.</p>{% endif %}
{% if has_payment_note %}<p>{{payment_note}}</p>{% endif %}
<p>Platíte-li předem, odešleme zboží po připsání platby na náš účet nebo po jejím potvrzení platební bránou. Při platbě kartou nebo přes platební bránu se údaje o platební kartě k nám nedostanou, zpracovává je provozovatel brány.</p>
<p>Daňový doklad vám vystavíme a doručíme elektronicky, případně ho najdete v balíku.</p>

<h2>4. Dodání zboží</h2>
{% if has_shipping %}<p>Zboží posíláme těmito způsoby:</p>
{{{shipping_html}}}{% else %}<p>Dostupné způsoby dopravy a jejich ceny se zobrazí v košíku.</p>{% endif %}
{% if has_delivery_note %}<p>{{delivery_note}}</p>{% endif %}
<p>Lhůta pro dodání vychází z dostupnosti zboží a zvolené dopravy a je uvedena u zboží nebo v potvrzení objednávky. Nedodáme-li zboží v dohodnuté lhůtě, můžete nás vyzvat k dodání v dodatečné lhůtě; pokud ani tehdy zboží nedodáme, můžete od smlouvy odstoupit.</p>
<p>Při převzetí zásilky od dopravce zkontrolujte, zda není obal porušen. Zjistíte-li poškození, sepište s dopravcem záznam a dejte nám vědět. Nárok z vad to neomezuje, jen usnadňuje jejich prokázání.</p>

<h2>5. Odstoupení od smlouvy do 14 dnů</h2>
<p>Jste-li spotřebitel, můžete od smlouvy uzavřené na dálku odstoupit <strong>bez udání důvodu do 14 dnů</strong> (§ 1829 občanského zákoníku). Lhůta začíná běžet dnem, kdy vy nebo vámi určená osoba (jiná než dopravce) zboží převezmete. Při dodání několika druhů zboží nebo částí zásilky běží lhůta od převzetí poslední dodávky. Postačí, když odstoupení odešlete před uplynutím lhůty.</p>
<p>Odstoupit můžete jakýmkoli jednoznačným prohlášením, třeba e-mailem na {{email}} nebo dopisem na adresu {{withdrawal_address}}. Můžete použít vzorový formulář na konci těchto podmínek, není to ale povinné.</p>
<p>Zboží nám pošlete nebo předáte zpět nejpozději do 14 dnů od odstoupení na adresu {{withdrawal_address}}. Zboží vraťte nepoškozené, pokud možno v původním obalu a s dokladem o koupi.{% if return_by_customer %} Náklady na vrácení zboží nesete vy.{% endif %}{% if return_by_seller %} Náklady na vrácení zboží hradíme my.{% endif %} Odpovídáte za snížení hodnoty zboží, které vzniklo tím, že jste s ním nakládali jinak, než je nutné k seznámení se s jeho povahou a vlastnostmi.</p>
<p>Peníze, které jste nám zaplatili, včetně nákladů na dopravu k vám (nejlevnější nabízený způsob), vrátíme nejpozději do 14 dnů od odstoupení stejným způsobem, jakým jste platili, pokud nesouhlasíte s jiným. Se zaplacenou částkou můžeme počkat, dokud zboží neobdržíme nebo neprokážete, že jste ho odeslali. Na vrácení zboží si dohodnuté náklady dopravy můžeme započíst proti pohledávce.</p>
<p><strong>Od smlouvy nelze odstoupit</strong> mimo jiné (§ 1837 občanského zákoníku) u:</p>
<ul>
<li>zboží upraveného podle vašeho přání nebo pro vaši osobu;</li>
<li>zboží podléhajícího rychlé zkáze a zboží, které bylo po dodání nenávratně smíseno s jiným zbožím;</li>
<li>zboží v uzavřeném obalu, které z důvodu ochrany zdraví nebo z hygienických důvodů nelze vrátit a jehož obal jste porušili;</li>
<li>zvukové nebo obrazové nahrávky a počítačového programu, jejichž originální obal jste porušili;</li>
<li>digitálního obsahu, který nebyl dodán na hmotném nosiči, pokud jsme ho začali dodávat s vaším předchozím výslovným souhlasem a vy jste prohlásili, že jste byli poučeni o ztrátě práva na odstoupení;</li>
<li>novin, periodik a časopisů s výjimkou předplatného;</li>
<li>ubytování, přepravy, stravování nebo volnočasových služeb, jsou-li poskytovány v určeném dni nebo období.</li>
</ul>
<p>Úplný výčet výjimek je v zákoně. Pokud si nejste jisti, zda se výjimka týká vaší objednávky, napište nám před koupí.</p>

<h2>6. Vady zboží a reklamace</h2>
<p>Odpovídáme za to, že zboží při převzetí nemá vady. Zejména odpovídáme za to, že zboží odpovídá popisu, má vlastnosti, které jsme uvedli nebo které jste mohli rozumně očekávat, je vhodné k účelu, ke kterému se zboží obvykle používá, a je dodáno s příslušenstvím a návody. Záruční doba u zboží prodávaného spotřebiteli je 24 měsíců od převzetí (u použitého zboží může být kratší, nejméně však 12 měsíců). U spotřebitele platí zákonná domněnka, že vada, která se projeví v době stanovené zákonem po převzetí, existovala už při převzetí, ledaže se prokáže opak nebo to vylučuje povaha zboží.</p>
<p>Má-li zboží vadu, můžete požadovat jeho opravu nebo výměnu. Není-li náprava možná, nebo ji neprovedeme včas, můžete požadovat přiměřenou slevu z ceny nebo od smlouvy odstoupit. Odstoupit můžete rovnou, jde-li o podstatné porušení smlouvy.</p>
<p>Reklamaci uplatněte co nejdříve po zjištění vady, na adrese {{complaints_address}} nebo e-mailem na {{complaints_email}}. Pošlete nám zboží, popište vadu a uveďte, jakou nápravu požadujete; přiložte doklad o koupi. Potvrdíme vám, kdy jste reklamaci uplatnili a co je jejím obsahem. O reklamaci rozhodneme ihned, ve složitých případech do tří pracovních dnů (nezahrnuje se doba potřebná k odbornému posouzení vady). Reklamaci včetně odstranění vady vyřídíme nejpozději do 30 dnů od jejího uplatnění, pokud se s vámi nedohodneme na delší lhůtě. Po vyřízení reklamace vám předáme písemné potvrzení o datu a způsobu vyřízení. Nutné náklady na uplatnění reklamace nesete my.</p>
<p>Práva z vad se neuplatní, pokud vadu způsobilo nesprávné používání, mechanické poškození nebo běžné opotřebení.</p>

<h2>7. Mimosoudní řešení sporů</h2>
<p>Pokud se nám nepodaří spor vyřešit společně, můžete se obrátit na subjekt mimosoudního řešení spotřebitelských sporů, kterým je <strong>Česká obchodní inspekce</strong>, Štěpánská 567/15, 120 00 Praha 2, <a href="https://adr.coi.cz" rel="noopener noreferrer">adr.coi.cz</a>. Návrh na zahájení řízení podáte u ní, nejpozději do jednoho roku od chvíle, kdy jste u nás uplatnili své právo, které je předmětem sporu. Dozor nad dodržováním předpisů o ochraně spotřebitele vykonává také Česká obchodní inspekce.</p>

<h2>8. Ochrana osobních údajů</h2>
<p>Osobní údaje zpracováváme v rozsahu a za podmínek popsaných v {% if has_privacy_url %}<a href="{{privacy_url}}">zásadách ochrany osobních údajů</a>{% else %}zásadách ochrany osobních údajů na tomto webu{% endif %}.</p>

<h2>9. Závěrečná ustanovení</h2>
<p>Tyto podmínky můžeme změnit. Pro objednávku platí podmínky ve znění, které bylo účinné v den, kdy jste ji odeslali; verzi uloženou k vaší objednávce vám na požádání pošleme. Starší znění jsou dole k nahlédnutí.</p>
<p>Pokud je některé ustanovení těchto podmínek neplatné, ostatní zůstávají v platnosti. Pro spotřebitele neplatí ujednání, které by ho zbavilo ochrany podle kogentních ustanovení zákona.</p>

<h2>Příloha: vzorový formulář pro odstoupení od smlouvy</h2>
<p>(Vyplňte a odešlete tento formulář, pokud chcete od smlouvy odstoupit.)</p>
<p>Adresát: {{controller}}, {{withdrawal_address}}, {{email}}</p>
<p>Oznamuji, že odstupuji od smlouvy o nákupu tohoto zboží: ……………………</p>
<p>Datum objednání / datum obdržení: ……………………</p>
<p>Jméno a příjmení spotřebitele: ……………………</p>
<p>Adresa spotřebitele: ……………………</p>
<p>Číslo bankovního účtu pro vrácení peněz: ……………………</p>
<p>Datum: ……………………  Podpis (pouze pokud se formulář odesílá v listinné podobě): ……………………</p>
TPL;
