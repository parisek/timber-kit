# Plan: `DevMediaProxy` pro timber-kit

**Status:** draft k iteraci
**Cíl:** dodat dev-only media proxy pro chybějící soubory v `uploads`, bez lokální cache a bez závislosti na webserver rewrite pravidlech.

---

## 1. Shrnutí problému

V worktree / DDEV prostředí typicky importujeme produkční DB, ale ne `wp-content/uploads`. Výsledek: attachment URL v DB existují, ale lokální soubory ne, takže v adminu i na frontendu chybí obrázky.

Požadované chování:

- pokud lokální soubor existuje, URL zůstane beze změny
- pokud lokální soubor chybí, URL se přepíše na upstream
- feature je explicitně opt-in
- v produkci je defaultně vypnutá

---

## 2. Scope v1

### In scope

- rewrite chybějících upload URL na upstream
- integrace do `StarterBase`
- podpora standardních WordPress attachment hooků
- testy pro rewrite jádro i hook registraci

### Out of scope

- lokální cache souborů
- automatická detekce prostředí
- Apache / nginx rewrite řešení
- řešení protected/private uploads
- rozšíření o Timber-specific hooky v první iteraci, pokud je nepotvrdí smoke test

---

## 3. Návrh API

První verze má mít malé testovatelné jádro a tenkou WP integrační vrstvu.

### 3.1 Core API

Preferovaný tvar:

```php
DevMediaProxy::rewriteIfMissing(
    string $url,
    string $uploads_base_url,
    string $uploads_base_dir,
    string $upstream_uploads_base_url
): string
```

Vlastnosti:

- pure function
- bez statického config stavu
- snadné unit testy
- funguje i mimo `register()`

### 3.2 Registration API

```php
DevMediaProxy::register(string $upstream_uploads_base_url): void
```

`register()`:

- načte `wp_get_upload_dir()`
- zaregistruje WP filtry
- použije pure core metodu
- je idempotentní z pohledu hook registrace

Poznámka:

- idempotence se má týkat hooků, ne nutně blokovat interní reconfiguraci v testech
- pokud bude potřeba statický stav, přidáme explicitní interní reset pro testy

---

## 4. Konfigurace přes VPConfig

Primární požadavek:

- feature se má zapínat mimo projektový kód
- zdroj configu má být VPConfig / environment-level config
- theme ani child class nemá být nucená nastavovat property v projektu

### 4.1 Konvence inspirovaná Stage File Proxy

Drupal `stage_file_proxy` používá konfigurační klíč `origin` pro upstream zdroj souborů.
Relevantní reference:

- Drupal project page uvádí `stage_file_proxy_origin` pro Drupal 7 a `stage_file_proxy.settings origin` pro Drupal 8+.

Pro timber-kit tedy dává smysl použít stejnou mentální konvenci:

- `origin` = upstream, odkud se mají tahat / kam se mají přepisovat chybějící soubory

Preferovaný název constanty:

```php
TIMBERKIT_MEDIA_ORIGIN
```

Alternativy, pokud chceme být explicitnější:

- `TIMBERKIT_DEV_MEDIA_ORIGIN`
- `TIMBERKIT_MEDIA_PROXY_ORIGIN`

Moje preference:

- `TIMBERKIT_MEDIA_ORIGIN`

Důvod:

- je krátká
- významově odpovídá `stage_file_proxy` konvenci
- neprotahuje zbytečně název o `dev`, protože dev-only usage je daný tím, kde se constanta definuje

### 4.2 Tvar hodnoty

Preferovaný tvar není jen host, ale celé uploads base URL:

```php
https://wordpress-base.profi.ci/wp-content/uploads
```

Ne jen:

```php
https://wordpress-base.profi.ci
```

Důvod:

- odpovídá to přesněji reálnému resource origin
- nespoléhá to na shodnou strukturu path mezi lokálem a upstreamem
- je to robustnější vůči CDN / subdir instalacím

### 4.3 Integrace do `StarterBase`

Přidat novou property:

```php
protected ?string $dev_media_proxy_upstream = null;
```

Precedence:

1. `TIMBERKIT_MEDIA_ORIGIN` constant z VPConfig
2. explicitně nastavená property v child class jako low-level override
3. `null` = disabled

Aktivace:

- nový `setupDevMediaProxy(): void`
- volat z `StarterBase::__construct()`
- registrace jen pokud je origin non-empty string

Decision:

- primary config surface je constanta z VPConfig
- nullable string property může zůstat jako sekundární override pro flexibilitu knihovny
- nepřidávat boolean flag

---

## 5. Hook coverage pro v1

### Povinné hooky

- `wp_get_attachment_url`
- `wp_get_attachment_image_src`
- `wp_calculate_image_srcset`
- `wp_get_attachment_image_attributes`
- `wp_prepare_attachment_for_js`

### Podmíněné hooky

- `wp_content_img_tag`
- `the_content`

Tyto dva hooky zařadit jen pokud smoke test ukáže reálnou mezeru. Důvod:

- regex fallback je křehčí
- zvyšuje scope i maintenance cost
- první iterace má být co nejmenší a dobře testovatelná

### Důležitá poznámka k adminu

`wp_prepare_attachment_for_js` nesmí přepisovat jen top-level `url`.
Je potřeba pokrýt i:

- `response['sizes'][*]['url']`
- případně `response['icon']`, pokud bude používán v preview

Jinak nebude admin coverage úplná.

---

## 6. Implementační iterace

### Iterace 1: Core rewrite engine

Dodáme:

- nový soubor `src/DevMediaProxy.php`
- pure metodu `rewriteIfMissing(...)`
- helpery pro:
  - validaci, že URL spadá pod local uploads base URL
  - převod URL na relativní path
  - zachování query stringu
  - bezpečné složení upstream URL

Acceptance criteria:

- non-upload URL se nemění
- existující lokální upload URL se nemění
- chybějící upload URL se přepíše na upstream
- query string se zachová
- malformed input nespadne

### Iterace 2: WP hook registration

Dodáme:

- `register()`
- callbacky pro povinné WP hooky
- minimální interní state jen tam, kde je nutný pro callbacky

Acceptance criteria:

- hooky se zaregistrují jednou
- všechny povinné callbacky používají stejnou rewrite logiku
- srcset a attachment JS response se přepisují korektně

### Iterace 3: `StarterBase` integrace + VPConfig activation

Dodáme:

- property `dev_media_proxy_upstream`
- `setupDevMediaProxy()`
- volání z konstruktoru

Acceptance criteria:

- `TIMBERKIT_MEDIA_ORIGIN` z VPConfig funguje bez zásahu do child class
- property lze použít jako sekundární override
- prázdný string feature nespustí

### Iterace 4: Fallback hooky podle potřeby

Dodáme pouze pokud budou potřeba:

- `wp_content_img_tag`
- `the_content`

Acceptance criteria:

- existuje reprodukovatelný use-case, který bez nich nefunguje
- test pokrývá konkrétní mezeru

### Iterace 5: Release a consumer rollout

Dodáme:

- `CHANGELOG.md`
- release tag
- stručný consumer integration note pro `wordpress-base`

Poznámka:

- `composer.json` verzi bumpovat netřeba, release je přes git tag

---

## 7. Test strategie

### 7.1 Unit testy pro core

Soubor:

- `tests/Unit/DevMediaProxy/RewriteIfMissingTest.php`

Případy:

- non-uploads URL
- uploads URL s existujícím souborem
- uploads URL bez souboru
- query string
- nested path
- malformed URL
- uploads URL mimo očekávaný base prefix
- upstream s trailing slash

### 7.2 Unit testy pro hook callbacky

Soubor:

- `tests/Unit/DevMediaProxy/HooksTest.php`

Případy:

- `wp_get_attachment_url`
- `wp_get_attachment_image_src`
- `wp_calculate_image_srcset`
- `wp_get_attachment_image_attributes`
- `wp_prepare_attachment_for_js`

Speciálně:

- mixed srcset, kde se přepíší jen chybějící soubory
- attachment JS response s `sizes`

### 7.3 Unit testy pro `StarterBase`

Soubor:

- `tests/Unit/StarterBase/DevMediaProxySetupTest.php`

Případy:

- disabled by default
- activation přes `TIMBERKIT_MEDIA_ORIGIN`
- activation přes property override
- precedence mezi constant a property odpovídá finálnímu rozhodnutí
- prázdný string nic neregistruje

### 7.4 Smoke test mimo unit testy

Manuální ověření v projektu používajícím `StarterBase`:

1. nastavit `TIMBERKIT_MEDIA_UPSTREAM`
1. nastavit `TIMBERKIT_MEDIA_ORIGIN`
2. mít prázdné lokální `uploads`
3. otevřít frontend s attachment obrázky
4. otevřít media library
5. ověřit, že se načítají z upstream

### 7.5 Gate před mergem

- `phpunit`
- `phpstan analyse`

---

## 8. Rizika a rozhodovací body

### Riziko 1: statický stav v `register()`

Pokud callbacky budou držet config ve statických properties, musíme ohlídat:

- izolaci testů
- chování při opakované registraci

Preferovaný směr:

- co nejvíc logiky nechat v pure metodě
- statický stav minimalizovat na hook runtime

### Riziko 2: naming a formát configu

Potřebujeme uzamknout:

- název constanty
- jestli držíme property override
- jestli hodnota je celé uploads base URL

Aktuální preference:

- `TIMBERKIT_MEDIA_ORIGIN`
- VPConfig constant jako primární config surface
- property jen jako sekundární override
- hodnota = celé uploads base URL

### Riziko 3: Timber bypass

Pokud Timber renderuje image URL mimo WP attachment filtry, bude potřeba follow-up.
Tohle nechceme předčasně řešit bez reprodukce.

### Riziko 4: regex fallback v `the_content`

Ten nasadit až když bude důkaz, že structured hooky nestačí.

---

## 9. Doporučené pořadí práce

1. potvrdit finální API shape a název constanty
2. implementovat pure core rewrite
3. přidat hook registration
4. přidat `StarterBase` setup
5. dopsat unit testy
6. udělat smoke test v reálném WP projektu
7. rozhodnout, jestli jsou potřeba fallback hooky
8. release

---

## 10. Otázky k rozhodnutí před implementací

1. Potvrzujeme `TIMBERKIT_MEDIA_ORIGIN` jako finální název constanty?
2. Potvrzujeme hodnotu jako celé uploads base URL, ne jen host?
3. Chceme v první iteraci rovnou i `the_content` / `wp_content_img_tag`, nebo je necháme až po smoke testu?
4. Chceme interní test reset API, pokud zůstane nějaký statický state?

---

## 11. Definition of Done

- existuje `DevMediaProxy` s testovatelným rewrite jádrem
- `StarterBase` umí feature zapnout property i konstantou
- frontend attachment URL se přepisují korektně
- admin media preview používá upstream i pro nested sizes
- test suite prochází
- smoke test v reálném projektu potvrzuje funkčnost
