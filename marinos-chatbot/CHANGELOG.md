# Marinos Chatbot — Değişiklik Geçmişi (CHANGELOG)

## 1.2.0 — Kararlılık & Çok Dil Yeniden Düzenlemesi

Bu sürüm, "yarım kalan promptlar, geç gelen e-postalar, takılı kalan bot, çok dilli sorunlar"
şikayetlerini hedef alan kapsamlı bir bakım sürümüdür. **Mimari korundu**, sadece zayıf noktalar yenilendi.

### Düzeltilen Kritik Hatalar
- **PHP fatal:** `includes/class-mailer.php` dosyasının sonunda yapıştırılmış mükerrer (bozuk)
  kod blokları vardı (satır 90 sonrası). Bu, bazı PHP sürümlerinde fatal hataya, en azından
  her e-posta gönderiminde "uyarı yağmuruna" yol açıyordu. Dosya **temiz** baştan yazıldı.
- **Eksik sınıf:** `class-admin.php`, `new Marinos_Chatbot_Whatsapp()` çağırıyordu ama
  `class-whatsapp.php` paketin içinde yoktu. "Test WhatsApp Gönder" butonuna basıldığında
  fatal hata oluşuyordu. UltraMsg destekli yeni `class-whatsapp.php` eklendi.

### E-posta Güvenilirliği
- **Sayfa kapanışında anında flush:** Konuşma bittiğinde tarayıcı `navigator.sendBeacon`
  ile yeni eklenen `wp_ajax_marinos_flush` endpoint'ini çağırır. Cron'u beklemeden
  e-posta hemen gider.
- **Watchdog mekanizması:** WP-Cron her hostta düzenli tetiklenmediği için, her sayfa
  yüklemesinde 30 sn'de bir "bekleyen oturumlar" taranır; vadesi geçen kayıt anında
  e-posta olarak gönderilir.
- **Idempotent gönderim:** Aynı oturum için 5 dakikalık bir transient kilit eklendi
  (son mesajın timestamp'iyle eşleşirse tekrar yollamaz). Mükerrer e-posta yağmuru
  engellenir.
- **Pending kuyruğu:** Bekleyen e-postalar `marinos_chatbot_pending_emails` option'ında
  tutulur; cron başarısız olsa bile flush mekanizması bunlardan haberdar olur.

### "Yarım Kalan Yanıt" Sorunu (Bot takılması)
- **Yeniden deneme:** Proxy isteği 5xx veya geçici ağ hatası dönerse, sunucu tarafında
  **2 kez** üstel back-off ile yeniden denenir.
- **Zaman aşımı artırıldı:** 30 sn → 45 sn. Frontend timeout 60 sn.
- **Frontend retry:** AJAX hata olursa **1 sessiz retry** yapılır; ardından kullanıcıya
  "Tekrar dene" butonu sunulur — yarım kalmış konuşma kullanıcı tarafından zahmetsizce
  toparlanabilir.
- **Abort koruması:** Eski istek hala uçarken kullanıcı yeni mesaj yazarsa, eski XHR
  abort edilir ve `isWaiting` doğru sıfırlanır.

### Çok Dilli Destek
- **Otomatik dil tespiti:** Tarayıcı dili (`navigator.language`) otomatik tespit edilir
  (tr/en/de/ru/ar/fr/es destekli; mevcut değilse tr).
- **Sistem prompt'a dil hint'i:** API her istekte ziyaretçinin diline göre cevap
  vermesini bot'a hatırlatır.
- **Hazır arayüz çevirileri:** Placeholder, online etiketi, hata mesajları, retry butonu,
  WhatsApp soru/CTA metinleri çeviri tablosundan gelir.
- **Çok dilli hata fallback:** Provider hata verdiyse ziyaretçiye kendi dilinde
  kibar bir hata mesajı gösterilir.
- **Çok dilli varsayılan sistem prompt:** Türkçe sabit "Türkçe konuş" yerine,
  "ziyaretçinin diline göre cevap ver" şablonu eklendi.

### Güvenlik & Performans
- **XSS koruması:** Bot cevabı `.html()` ile basılıyordu — `escapeHtml()` ile önce
  kaçırılıyor, sonra `\n → <br>` dönüştürülüyor.
- **Mesaj uzunluk limiti:** Kullanıcı mesajı backend'de 4000 karakterle kesilir,
  textarea `maxlength="4000"`.
- **Geçmiş kotası:** API tarafında son 12 mesaj, localStorage tarafında son 50/80
  ile sınırlandırıldı (sonsuz büyüyüp tarayıcıyı yormaz).
- **DB index:** `created_at` sütununa index eklendi (oturum tarama hızlanır).

### Yan Etkiler / Plugin Yaşam Döngüsü
- Deaktivasyonda kuyruktaki tüm zamanlanmış e-posta cron'ları temizlenir.

### Bilinen "Mimariyi Baştan Mı Yapsak?" Sorusuna Cevap
Mimari fena değil — modüler PHP sınıfları + jQuery widget + Marinos proxy mantığı sağlam.
Asıl problem **eski mailer dosyasındaki bozuk kod**, **eksik whatsapp sınıfı**,
**WP-Cron bağımlılığı** ve **frontend retry yokluğuydu**. Bu üçü çözüldüğünde sistem
çok daha kararlı çalışacaktır. Yine de uzun vadede önerilebilecek geliştirmeler:

1. Marinos proxy'sine ek olarak doğrudan Gemini/OpenAI fallback'i (tek nokta arıza riski azalır).
2. Streaming yanıt (kullanıcı "yarım gibi" görmek yerine canlı yazımı izler).
3. REST API'ye (admin-ajax yerine `register_rest_route`) geçiş — daha hızlı, log'lanabilir.
4. Konuşma loglarına `language` ve `response_time_ms` kolonu (analitik için).
5. WP-Cron yerine action-scheduler kullanımı (büyük sitelerde).

Bu adımlar gelecek 1.3 / 2.0 sürümünde planlanabilir.
