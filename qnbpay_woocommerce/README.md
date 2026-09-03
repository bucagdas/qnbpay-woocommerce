# QNBPay SanalPos (WooCommerce)

QNBPay ile WooCommerce odeme gecidi. Klasik kisa kod checkout ve Cart/Checkout Blocks checkout desteklenir. Odeme, QNB'nin barindirdigi (hosted) guvenli sayfada alinir; kart bilgileri sitenize hic ugramaz.

## Ozellikler

- Klasik checkout ve Blocks checkout, ayni sunucu akisini paylasir.
- Hosted odeme sayfasi: kart, taksit ve tutar QNB tarafinda; PCI kapsamini en aza indirir.
- Donus ve webhook, sunucu tarafinda `checkstatus` ile dogrulanmadan siparis tamamlanmaz.
- HPOS (High-Performance Order Storage) uyumlu.

## Gereksinimler

- WordPress 6.5 veya uzeri
- WooCommerce 8.0 veya uzeri
- PHP 7.4 veya uzeri

## Kurulum

1. Son surumun zip dosyasini indirin (Releases sayfasi).
2. WordPress yonetim panelinde Eklentiler, Yeni Ekle, Eklenti Yukle ile zip'i yukleyin.
3. Eklentiyi etkinlestirin.

## Yapilandirma

WooCommerce, Ayarlar, Odemeler, QNBPay Pos altinda:

- `merchant_key`, `app_key`, `app_secret`, `merchant_id` degerlerinizi girin.
- Test icin "Test Modu"nu acin (test.qnbpay.com.tr); canli icin kapatin (portal.qnbpay.com.tr).
- Odeme yontemini etkinlestirin.

## Guncelleme

Guncellemeler otomatiktir. Yeni bir surum yayinlandiginda WordPress yonetim panelindeki Guncellemeler ekraninda gorunur ve oradan uygulanir.

## Lisans

GPL-2.0-or-later. Ayrintilar icin https://www.gnu.org/licenses/gpl-2.0.html adresine bakin.
