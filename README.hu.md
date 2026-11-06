# Laravel Reverb Kit

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[English](README.md)

Élesítési eszközkészlet a [Laravel Reverb](https://reverb.laravel.com)-höz, ha saját magad
üzemelteted: Dockerben, Swarmon vagy compose-zal, saját reverse proxy mögött.

- **`reverb:doctor`:** statikus deploy-kapu. Kiszűri azokat a beállítási hibákat, amelyektől a
  valós idejű frissítés csendben leáll:
  - bind-cím a publish-célban;
  - hostnév a szerver bind-címében;
  - elveszett path-prefix;
  - replikák scaling nélkül;
  - közös scaling-csatorna;
  - egymásnak ellentmondó méretlimitek;
  - sebezhető Reverb-verzió.

  Hiba esetén nem nulla az exit kódja, és van `--json` kimenete. Ugyanezek a szabályok a
  [`laravel/doctor`](https://github.com/laravel/doctor)-ban is megjelennek, a `reverb` csoportban.
- **Generátorok** ahhoz a beállításhoz, amelyet különben projektről projektre másolnál:
  - `reverb:proxy nginx|caddy|traefik`: a WebSocket reverse proxy;
  - `reverb:stack swarm|compose`: a Reverb-service;
  - `reverb:env`: az env-blokk, amely szétválasztja a három Reverb-hostot.
- **Opt-in méretőr:** minden broadcastot küldés előtt megmér. Nélküle a Reverb a kéréslimit feletti
  publisht 413-mal utasítja el. Ez általános `BroadcastException`-ként jelenik meg, sokszor egy
  queue workerben, messze attól a kódtól, amelyik okozta.

Ha Laravel Forge-dzsal telepítesz, a Forge beállítja helyetted a proxyt és a daemont. A doctor
ilyenkor is hasznos, a generátorok nem.

## Követelmények

- PHP 8.3+
- Laravel 12 vagy 13

### Opcionális csomagok

| Csomag | Mit tesz lehetővé |
|---|---|
| `laravel/reverb` (^1.7) | A szerveroldali szabályokat (bind-cím, scaling, origins, payload-limitek, verzió), és a stack-sablonokban a `/up` health checket. Annak az appnak, amely csak publikál egy máshol futó Reverb-szerverre, nincs rá szüksége; ilyenkor ezek a szabályok kimaradnak. |
| `laravel/doctor` (^0.1) | A szabályokat `php artisan doctor`-diagnosztikaként. |
| `pusher/pusher-php-server` | A méretőr igényli. A Laravel `reverb` broadcast-drivere egyébként is használja. |

## Telepítés

```bash
composer require intragile/laravel-reverb-kit
```

A service provider automatikusan betöltődik. Az alapértékek módosításához publikáld a configot:

```bash
php artisan vendor:publish --tag=reverb-kit-config
```

## A három host

A Reverb élesítési hibáinak többsége abból fakad, hogy három különböző hostot összekevernek:

```
                      VITE_REVERB_HOST:443 (https)
  Böngésző ─────────────────────────────────▶ reverse proxy ──▶ Reverb-szerver
                                                 /app/{key}      bind: REVERB_SERVER_HOST:REVERB_SERVER_PORT
                                                                 (0.0.0.0:8080)
                                                                      ▲
  Laravel-alkalmazás ─────────────────────────────────────────────────┘
                      REVERB_HOST:REVERB_PORT (http, belső hálózat, pl. reverb:8080)
```

- **`REVERB_SERVER_HOST`:** ahol a szerver figyel. Ez egy IP-cím, általában `0.0.0.0`. Ide senki
  nem csatlakozik.
- **`REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME`:** ahová a Laravel a broadcastot küldi.
  Konténeres környezetben ez a service neve a belső hálón, például `reverb:8080`, `http`-n.
- **`VITE_REVERB_HOST` / `_PORT` / `_SCHEME`:** amihez a böngésző csatlakozik, vagyis a publikus
  hostnév a proxyn és a TLS-en át. Ezek build-időben égnek bele a frontend-bundle-be.

A `php artisan reverb:env` kommentekkel kiírja ezt a blokkot.

## Doctor

```bash
php artisan reverb:doctor
```

```
  OK    connection-credentials The connection credentials match the Reverb app 1.
 FAIL   publish-target         The reverb connection host is a bind address (0.0.0.0).
                                → REVERB_HOST is where Laravel sends broadcasts; the bind address belongs in REVERB_SERVER_HOST.
  OK    server-bind            Reverb binds to 0.0.0.0
  OK    scaling                scaling off, 1 replica
 WARN   allowed-origins        Reverb app(s) 1 accept connections from any origin
  ...
1 failed, 1 warning(s), 7 ok, 1 skipped.
```

| Opció | Jelentés |
|---|---|
| `--json` | Kiírja: `{findings: [{rule, status, message, hint}], worst}`. |
| `--strict` | Warningnál is 1-gyel lép ki. |
| `--replicas=N` | Hány Reverb-replika fut (alapértelmezés: `reverb-kit.topology.replicas`). |
| `--frontend-env=ÚTVONAL` | A renderelt frontend `.env`, benne a `VITE_REVERB_*` értékekkel (alapértelmezés: `reverb-kit.frontend_env`, utána az app saját `.env`-je). |
| `--skip=SZABÁLY` | Kihagy egy szabályt; ismételhető. Tartósan ugyanezt teszi a `reverb-kit.doctor.skip`. |

Exit kódok:
- `0`: nincs hiba (a warning is ide tartozik, hacsak nincs `--strict`);
- `1`: hiba;
- `2`: érvénytelen opció.

Ha az alapértelmezett broadcasting-connection nem a Reverb-connection (például `null` egy olyan
appban, amely nem broadcastol), minden szabály kimarad, és az exit kód `0`.

### Deploy-kapuként

Ott futtasd, ahol már elérhető a telepítendő config. Például egy lépésként azután, hogy a config
renderelődött, és mielőtt a forgalom átáll:

```bash
php artisan reverb:doctor --replicas="$REVERB_REPLICAS" --frontend-env=frontend/.env
```

### Szabályok

| Szabály | Mit ellenőriz | Mikor bukik |
|---|---|---|
| `connection-credentials` | A broadcasting-connection `key`, `secret` és `app_id` értéke egyezik egy Reverb-appéval. Ha nincs telepítve Reverb, csak azt nézi, hogy ki vannak-e töltve. Kimarad, ha a laravel/reverb maga hozza a megfelelő diagnosztikát. | Nincs egyező app, vagy eltér a secret vagy az app_id. |
| `publish-target` | A `REVERB_HOST`, mint a connection hostja. A belső service-név (`reverb`) rendben van. | Üres, `0.0.0.0` vagy `::`. Productionben a loopbackre warningot ad. |
| `scheme-port` | A connection és minden app scheme-je, `useTLS`-e és portja. | `https` TLS nélkül, vagy fordítva. A 443 `http`-vel, illetve a 80 `https`-sel warningot ad. |
| `server-path` | Egy `REVERB_SERVER_PATH` prefix a connection `path` opciójába is eljut. A Laravel 13 alapértelmezett connectionjében nincs `path`, így a prefix csendben elveszik. Az érték `/prefix/` alakú kell legyen, mert a Pusher SDK ehhez képest relatívan oldja fel a publish-URL-t. | A path hiányzik vagy eltér. |
| `server-bind` | A `REVERB_SERVER_HOST`. A szerver hostnévre nem tud bindolni. | Nem IP-cím. |
| `scaling` | A replikák száma, a `REVERB_SCALING_ENABLED` és a `REVERB_SCALING_CHANNEL`. A Reverb scaling-csatornája egy sima Redis pub/sub csatorna, amelyen minden azonos nevű deployment osztozik. | Egynél több replika scaling nélkül, vagy a default `reverb` csatorna `reverb-kit.topology.shared_redis` mellett. Egyébként a default csatornára warningot ad. |
| `allowed-origins` | Productionben az appok `allowed_origins` listája. A `config/reverb.php` fixen `['*']`-ot ad. | Soha; a `*`-ra warningot ad. |
| `payload-limit` | Kiírja a `max_request_size`-t (a szerveroldali publish limitje) és appanként a `max_message_size`-t. | Soha. Warningot ad, ha a `max_message_size` nagyobb a `max_request_size`-nál (a rossz változót emelték), vagy ha a méretőr többet enged át, mint amit a szerver elfogad. |
| `reverb-version` | A telepített laravel/reverb verzióját az ismert advisoryk alapján. | 1.4.0 alatt (CVE-2024-50347), vagy 1.7.0 alatt bekapcsolt scalinggel (CVE-2026-23524). Egyébként 1.7.0 alatt warningot ad. |
| `frontend-env` | A `VITE_REVERB_*` értékeket a szerveroldalhoz képest. A `${VAR}` és `$VAR` hivatkozásokat a buildre hagyja. | Eltér az app key, vagy a host bind-cím, productionben loopback, vagy pont nélküli belső név, amelyet a böngésző nem tud feloldani. |

A doctor statikus: configot olvas, hálózati hívást nem indít. Élő WebSocket oda-vissza próbához ott
az [intragile/laravel-observability](https://github.com/intragile/laravel-observability). Annak
`BroadcastCheck`-je egy eventet küld át a Reverben, és egy valódi kapcsolaton várja vissza.

### laravel/doctor

Ha a `laravel/doctor` telepítve van, minden szabály egy diagnosztika a `reverb` csoportban:

```bash
php artisan doctor --only=reverb
```

## Generátorok

Minden generátor stdoutra ír, vagy `--output=` mellett fájlba. Meglévő fájlt csak `--force`-szal
ír felül. Az értékek a configodból jönnek, az opciók felülírják őket.

### Reverse proxy

```bash
php artisan reverb:proxy nginx --public-host=ws.example.com
php artisan reverb:proxy caddy
php artisan reverb:proxy traefik
```

**nginx:**
- **DNS-feloldás:** az upstreamet a Docker DNS-én (`resolver 127.0.0.11`) és egy változós
  `proxy_pass`-on át oldja fel. Literál hostnévvel az nginx csak egyszer, induláskor oldja fel a
  nevet. Ha az orchestrator áthelyezi a Reverbet, az nginx továbbra is a régi címre proxyzna.
- **Read timeout:** a ping-intervallum kétszerese. Így a tétlen kapcsolatot nem vágja el az nginx
  60 s-os alapértéke.
- **Útvonal:** a location csak a `/app/`-ra illeszkedik. A `--with-api` a `/apps/` alatti HTTP
  API-t is kiteszi, de erre csak akkor van szükség, ha a publikáló a hálón kívül fut.

### Swarm- és compose-service

```bash
php artisan reverb:stack swarm --image=registry.example.com/app:1.4.0 --replicas=1
php artisan reverb:stack compose --image=registry.example.com/app:1.4.0
```

A service:
- az app image-éből futtatja a `reverb:start`-ot;
- portot nem publikál;
- a health checket a Reverb `/up` endpointján végzi (régebbi kiadásoknál, ahol ez nincs, a
  státuszkódot ellenőrzi);
- erőforrás-limitet állít be.

Egy replikánál `stop-first`-tel frissít, mert scaling nélkül két példány kettéosztaná a
klienseket. Egynél több replikánál:
- bekapcsolja a scalinget;
- `start-first`-tel frissít;
- az alkalmazás nevéből képez scaling-csatornát, hacsak nem adsz meg `--scaling-channel`-t.

### Env-blokk

```bash
php artisan reverb:env --public-host=ws.example.com --internal-host=reverb
```

### Saját sablonok

```bash
php artisan vendor:publish --tag=reverb-kit-stubs
```

A stubok a `stubs/reverb-kit/` mappába másolódnak. A publikált stub elsőbbséget élvez a csomag
sajátjával szemben.

## Méretőr

Bekapcsolás: `REVERB_KIT_GUARD_PAYLOADS=true`. Az őr becsomagolja a `reverb` broadcast-drivert, és
minden publish-kérést pontosan úgy mér meg, ahogy a Pusher-kliens elküldené, még mielőtt bármi
kimenne. A méretbe beleszámít a csatornalista, `private-encrypted-*` csatornáknál pedig a
ciphertext.

```
Broadcast "OrderShipped" is 10342 bytes, over the 10000-byte Reverb request limit (raise REVERB_MAX_REQUEST_SIZE or send less).
```

| Beállítás | Alapérték | Jelentés |
|---|---|---|
| `REVERB_KIT_ON_OVERSIZE` | `report` | `report`: a `PayloadTooLarge` kivételt a `report()`-nak adja, és a broadcastot eldobja. `throw`: a hívónak dobja. |
| `reverb-kit.publish.max_bytes` | `null` | A limit. Null esetén a `reverb.servers.reverb.max_request_size`, vagy 10 000, ha nincs telepítve Reverb. |
| `reverb-kit.publish.header_allowance` | `1024` | A kérés-sornak és a fejléceknek fenntartott bájtok, mert ezek is beleszámítanak a Reverb limitjébe. |

Ha egy broadcast több kérésre oszlik (100-nál több csatorna, vagy titkosított csatornák), akkor
vagy minden csatornájára kimegy, vagy egyikre sem. A Laravel `ShouldRescue` a méretőr mellett is
érvényes: a méretőr arról dönt, kimegy-e a payload, a `ShouldRescue` pedig arról, mi történjen a
broadcast bármely más hibájával.

## Csatorna-izoláció deploymentek között

A Reverb az appokat a kulcsuk alapján izolálja. Ha minden deploymentnek saját `REVERB_APP_*`
hitelesítő adatai vannak, nem látják egymás csatornáit. Amin osztozhatnak, az a scaling-csatorna:
- adj minden deploymentnek saját `REVERB_SCALING_CHANNEL`-t, például `reverb_{app}_{környezet}`;
- ahol több deployment egy Redisen fut, állítsd be a `REVERB_KIT_SHARED_REDIS=true`-t, hogy a
  doctor kikényszerítse.

## Mi történik, ha valami nincs telepítve

| Hiányzik | Hatás |
|---|---|
| laravel/reverb | A szerveroldali szabályok kimaradnak; a kapcsolatoldali szabályok és a generátorok működnek. A stack-sablonok a státuszkód-alapú health checket használják. |
| laravel/doctor | Nincsenek `php artisan doctor`-diagnosztikák; a `reverb:doctor`-t ez nem érinti. |
| Pusher-kliens | Ha a méretőr be van kapcsolva, a broadcasting első használatakor egyértelmű kivételt dob. Mást nem érint. |

## Konfiguráció

Lásd: [`config/reverb-kit.php`](config/reverb-kit.php). Környezeti változók:
`REVERB_KIT_CONNECTION`, `REVERB_KIT_SERVER`, `REVERB_KIT_REPLICAS`, `REVERB_KIT_SHARED_REDIS`,
`REVERB_KIT_FRONTEND_ENV`, `REVERB_KIT_GUARD_PAYLOADS`, `REVERB_KIT_ON_OVERSIZE`,
`REVERB_KIT_UPSTREAM_HOST`, `REVERB_KIT_PUBLIC_HOST`.

## Tesztelés

```bash
composer test
composer analyse
```

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
