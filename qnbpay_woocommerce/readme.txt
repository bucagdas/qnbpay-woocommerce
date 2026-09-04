=== QNBPay SanalPos ===
Contributors: bucagdas
Tags: woocommerce, payment gateway, qnbpay, sanalpos, kredi karti
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

QNBPay ile WooCommerce odeme gecidi. Odeme QNB'nin barindirdigi (hosted) guvenli sayfada alinir; kart bilgileri sitenize hic ugramaz.

== Description ==

Bu, QNB Finansbank'in resmi WooCommerce eklentisinden turetilmis bagimsiz bir surumdur. QNB tarafindan saglanmaz ya da desteklenmez.

Klasik kisa kod checkout ve Cart/Checkout Blocks checkout desteklenir. Donus ve webhook, sunucu tarafinda checkstatus ile dogrulanmadan siparis tamamlanmaz. HPOS (High-Performance Order Storage) uyumludur.

== Installation ==

1. Son surumun zip dosyasini indirin (Releases sayfasi).
2. Eklentiler > Yeni Ekle > Eklenti Yukle ile zip'i yukleyin.
3. Eklentiyi etkinlestirin.
4. WooCommerce > Ayarlar > Odemeler > QNBPay altinda anahtarlarinizi girin.

== Changelog ==

= 1.1.1 =
* Duzeltildi: Tekrarlayan (abonelik) urun kontrolunde WooCommerce 3.0'da kaldirilan $product->id erisimi giderildi; varyasyonlarda dogru urune bakilir. Store API'de her istekte olusan "id was called incorrectly" uyarilari sona erdi.
* Degistirildi: Abonelik sepet mesajlari; sepet temizlendiginde hata yerine bilgilendirme tonunda gosterilir. Iki musteri mesaji Turkcelestirildi.
* Eklendi: readme.txt (Tested up to: 7.1); guncelleme ekranindaki uyumluluk uyarisi giderildi.

= 1.1.0 =
* Iade (WooCommerce iade butonu, QNB /api/refund), WooCommerce log kanali (qnbpay), iki yeni tema (Modern, Kurumsal) ve panel ici tema onizlemesi, taksit (selected_installments) ve vade farki (is_comission_from_user) QNB'ye iletiliyor, ayar sayfasi bolumlere ayrildi.

= 1.0.4 =
* Secilebilir odeme satiri temalari (Sade, Kartli, Vurgulu) ve kart marka logolari.

= 1.0.3 =
* Ayar sayfasinda QNB baglanti/odeme testi bildirimi ve test anahtari butonu.

= 1.0.2 =
* GitHub release'lerinden otomatik guncelleme.

= 1.0.1 =
* wp-admin bos sayfa hatasi giderildi.

= 1.0.0 =
* Ilk genel surum: guvenlik sertlestirmesi, hosted /purchase/link akisi, Cart/Checkout Blocks destegi.
