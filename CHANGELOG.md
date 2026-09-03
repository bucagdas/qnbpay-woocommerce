# Degisiklik Gunlugu

Bu dosya, projedeki belirgin degisiklikleri listeler.
Bicim [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/) esas alir ve
proje [Anlamsal Surumleme](https://semver.org/lang/tr/) kullanir.

## [1.1.0] - 2026-09-03

### Eklendi
- Iade: WooCommerce siparis ekranindaki "Iade Et" butonu artik QNB `/api/refund`
  ile calisiyor (kismi ve tam iade). Not: QNB sandbox iadeyi tam test etmez;
  canlida gercek bir islemle dogrulayin.
- WooCommerce log kanali `qnbpay` (WooCommerce > Durum > Gunlukler): tanilama
  kayitlari panelden okunabiliyor, sunucu erisimi gerekmiyor.
- Iki yeni odeme satiri temasi: Modern (golgeli kart) ve Kurumsal (banka
  gorunumu); toplam 5 tema. Ayar sayfasinda canli tema onizlemesi.
- "Gercek anahtarlara don": test anahtarlari yuklendikten sonra gercek
  anahtarlari geri yukleme; eklenti son gercek anahtarlari sunucu tarafinda
  yedekler.

### Degistirildi
- Secilen taksit sayilari odeme sayfasina gonderiliyor (`selected_installments`);
  taksit alani aranabilir secime cevrildi ve her acilista API cagrisi kaldirildi.
- "Vade Farkini Kart Sahibi Odesin" ayari QNB'ye `is_comission_from_user` olarak
  iletiliyor.
- Ayar sayfasi bolumlere ayrildi; "test anahtarlari" butonu Merchant Key alaninin
  yanina alindi.

### Kaldirildi
- Gereksiz Etkin/Pasif alani (yontem, Odemeler listesindeki dugmeden yonetilir).
- Kullanilmayan, kimlik dogrulamasiz taksit AJAX kancalari.

## [1.0.4] - 2026-09-03

### Eklendi
- Secilebilir odeme satiri temalari (Sade, Kartli, Vurgulu) ve kart marka
  logolari (Mastercard, Visa, Amex, troy); klasik ve Blocks checkout.

## [1.0.3] - 2026-09-03

### Eklendi
- Ayar sayfasinda QNB baglanti/odeme testi bildirimi.
- "QNB test anahtarlarini ekle" butonu.

### Degistirildi
- Odeme basarisiz olursa gercek sebep (QNB aciklamasi veya baglanti hatasi)
  checkout'ta ve logda gosteriliyor.

## [1.0.2] - 2026-09-03

### Eklendi
- GitHub release'lerinden otomatik guncelleme (vendored plugin-update-checker).

### Degistirildi
- Plugin URI, eklentinin GitHub deposuna cevrildi.

## [1.0.1] - 2026-09-03

### Duzeltildi
- Silinmis bir metoda baglanan `admin_notices` kancasi kaldirildi; wp-admin'de
  bos sayfa hatasi giderildi.

## [1.0.0] - 2026-09-03

Ilk genel surum. Guvenlik sertlestirmesi, hosted akisa gecis ve Blocks destegi
bir arada.

### Guvenlik
- Odeme yalnizca sunucu tarafinda `checkstatus` ile dogrulandiktan sonra
  tamamlaniyor; kimlik dogrulamasiz webhook ile siparis tamamlama acigi kapatildi.
- `unserialize` kaldirildi.
- Kayitli kart silmede sahiplik/yetki kontrolu (baska musterinin karti silinemez).
- TLS sertifika dogrulamasi zorunlu; gelen veri temizleme.

### Degistirildi
- Hosted `/purchase/link` akisina gecildi: kart bilgileri sunucuya hic ugramiyor
  (PCI DSS SAQ A).
- Kod uc katmana ayrildi: `QNBPay_Api` / `QNBPay_Webhook` / gateway.
- QNB bearer token sunucu tarafinda tutuluyor, tarayiciya (DOM) yazilmiyor.
- HPOS (High-Performance Order Storage) uyumu.
- Pre-Auth (on provizyon) `confirmPayment` ile capture.

### Eklendi
- Cart/Checkout Blocks destegi (hosted akis; sayfada kart alani yok).

### Kaldirildi
- Eski, site uzerinde kart alan `paySmart3D` yolu.

## [0.9.0] - 2026-09-03

- Temel surum: orijinal QNBpay eklentisinin geldigi haliyle paketlenmis hali
  (guvenlik calismasi oncesi geri donus noktasi).

[1.1.0]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.1.0
[1.0.4]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.0.4
[1.0.3]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.0.3
[1.0.2]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.0.2
[1.0.1]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.0.1
[1.0.0]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v1.0.0
[0.9.0]: https://github.com/bucagdas/qnbpay-woocommerce/releases/tag/v0.9.0
