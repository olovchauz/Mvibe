# Mvibe — Poyga (Real-time Multiplayer Race)

`quvnoq.biz` uchun yengil, **toza PHP + SQLite** asosida ko'p o'yinchili real-time
poyga o'yini. Hech qanday tashqi composer paketi, WebSocket server yoki Node.js
talab qilinmaydi — oddiy umumiy PHP hostingda ham ishlaydi.

## O'yin haqida

- Bir o'yinchi **xona** ochadi va do'stlariga **4 harfli kod** beradi.
- Boshqalar kod orqali xonaga qo'shiladi (maks. 8 o'yinchi).
- Hamma **«Tayyorman»** tugmasini bosgach 3-2-1 sanaladi va poyga boshlanadi.
- **← va →** (yoki **A va D**) tugmalarini **navbatma-navbat** tez bosing —
  yuguruvchingiz oldinga harakatlanadi. Bir tugmani ikki marta bosish
  hisobga olinmaydi (chinakam yugurish kabi).
- Mobil qurilmada ekrandagi chap/o'ng tugmalar ishlatiladi.
- Birinchi finishga yetgan g'olib bo'ladi; barcha o'yinchilar tugatgach,
  natijalar ko'rsatiladi va istalgan o'yinchi qaytadan o'yin boshlay oladi.

## Texnik talablar

- PHP ≥ 7.4 (8.x tavsiya), `pdo_sqlite` extension yoqilgan bo'lishi kerak.
- Web server yozish huquqiga ega bo'lgan `data/` papkasi (DB shu yerda
  yaratiladi). Birinchi so'rovda avtomatik yaratiladi.

## Lokalda ishga tushirish

```bash
php -S 0.0.0.0:8080 -t .
```

Brauzerda <http://localhost:8080> ni oching. Boshqa qurilma/oyna ham
shu manzilga kirib xonaga qo'shilishi mumkin.

## Faylni quvnoq.biz ga joylash

Barcha fayllarni hosting'ning umumiy katalogiga (`public_html` va h.k.) ko'chiring.
Yagona shart — `data/` papkasi yozish huquqiga (`0775`) ega bo'lsin.

## Arxitektura

```
index.php       — Lobby (xona ochish / qo'shilish)
game.php        — Poyga UI
api.php         — REST endpointlar (create, join, state, ready, move, restart, leave)
lib/db.php      — SQLite ulanish + schema
assets/style.css
assets/game.js  — Canvas-siz DOM-render + polling (~700ms holat, ~real-time harakatlar)
data/poyga.sqlite — avto-yaratiladi (gitignore)
```

Real-time aloqa **qisqa polling** orqali amalga oshiriladi (har 700ms holat,
harakatlar bosilgan zahoti yuboriladi). Bu WebSocket'siz ham juda silliq
his beradi, lekin to'liq deklarativ holat **serverda** saqlanadi
(client-trust qilinmaydi).
