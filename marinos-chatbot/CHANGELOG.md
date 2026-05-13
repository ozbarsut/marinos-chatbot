# Marinos Chatbot — Değişiklik Geçmişi (CHANGELOG)

## 1.2.10 — Varsayılan sessizlik süresi 2 dakika

Kullanıcı kesin isteğini netleştirdi:
> "Konuşma 2 dakika devam etmediği zaman otomatik mail atsın, başka bir şeye gerek yok."

- **Debounce varsayılanı 1 dk → 2 dk**
- Diğer her şey aynı: rate-limit kapalı, watchdog throttle 3 oturum,
  içerik idempotency korunuyor.
- Admin paneldeki açıklama metni "2 dk" önerisi gösterecek şekilde
  güncellendi.

## 1.2.9 — Mail davranışı: epizot başına 1 mail (kullanıcı netleştirmesi)

Kullanıcının istediği davranışı net biçimde ifade etmesi: *"Sohbet ediyor, 1 dk
sessizleşince mail gelsin; tekrar konuşup yine 1 dk sessizleşirse yine yeni mail
gelsin."*

1.2.8'in agresif (5 dk debounce + 60 dk session-lock) varsayılanları bu
senaryoyu engelliyordu. Bu sürümde:

- **Debounce varsayılanı 5 dk → 1 dk** (kullanıcı isteği)
- **Aynı oturum için minimum mail aralığı (rate-limit) varsayılanı 60 dk → 0 dk (Kapalı)**
  - `0 dk` artık geçerli bir değer; mailer'da transient mantığı bu durumda
    tamamen atlanır.
  - Yani **her sessizlik penceresi yeni mail üretir.**
- Admin select kutusunda "Kapalı (sınırsız)" seçeneği var.

Aksiyon korunan iki idempotency:
1. **İçerik idempotency:** Aynı son-mesaj timestamp'iyle mail atılmışsa tekrar atılmaz.
2. **Watchdog throttle:** Tek tetikte max 3 oturum (eski oturum birikmesini önler).

Bu sayede istenen akış garanti:
- T+0..T+30: konuşma
- T+90: 1 dk sessizlik → mail #1
- T+120: kullanıcı tekrar yazıyor
- T+180: yine 1 dk sessizlik → mail #2 (tüm konuşmayı içerir)

## 1.2.8 — "6-7 ardışık mail" sorunu + kısa cümle stili + Gemini 3 Pro ön sıraya

**Belirti:** Sohbet bittikten sonra mail anında gelmiyor, **biriktirip
6-7 mail birden** atıyordu. Bir oturum için 1 mail bekleniyor.

**Kök sebep:** 1.2.6 mailer'da idempotency kilidi son mesaj
timestamp'ine bakıyordu. Kullanıcı sohbete devam ederse → her yeni
mesajdan sonra 60s sessizlik penceresi tetikleniyor → her sessizlik
penceresi YENİ bir mail üretiyordu. 5 dk içinde 6-7 sessizlik penceresi
oluşursa 6-7 mail gidiyordu.

**Düzeltme — 4 katman:**

1. **Debounce 60s → 5 dk (varsayılan):** Kullanıcı 5 dk konuşmadıysa
   gerçekten sohbet bitmiş sayılıyor. Aktif sohbet süresince mail
   tetiklenmiyor.
2. **Oturum başı mail kilidi:** Bir oturum için bir kez mail gittikten
   sonra **60 dk boyunca yeni mail YOK** (varsayılan). Kullanıcı sohbete
   devam etse de bir sonraki tetikte yeni mesajlar TOPLU olarak iletilir.
3. **Watchdog throttle:** Tek bir watchdog çalışmasında en fazla 3
   oturum işleniyor. Birikmiş geçmiş oturumlar bir çırpıda yağmıyor.
4. **DB tarama penceresi 24 saat → 6 saat:** Çok eski oturumlar bir daha
   gönderilmek üzere tetiklenmiyor.

**Yeni admin ayarları** (E-posta Tanılama bloğunun altında):
- Sohbet sonu bekleme süresi: 1/2/3/5/10/15 dk (varsayılan **5 dk**)
- Oturum başı mail kilidi: 15/30/60/120/240/720/1440 dk (varsayılan **60 dk**)

**Diğer iyileştirmeler bu sürümde:**

- **Token tavanı 2048 → 4096:** Daha geniş cevap alanı; yarım kalma
  riski daha da azalıyor.
- **Sistem prompt'a Stil Kuralı:**
  - KISA cümleler (tercihen 1-2, en fazla 3 cümle)
  - Birden fazla bilgi varsa madde işareti / numara, paragraf yazma
  - Reklam jargonundan kaçın
  - Her yanıt sonunda kısa bir takip sorusu (akışta tut)
- **Gemini 3 Pro listede ön sıraya çıktı,** label'ı netleşti:
  "Gemini 3 Pro (En güçlü — karmaşık akışlar, satış sohbeti)" — en üstte.
  Eski liste: Flash 3, 3 Pro, Flash 2.5, 2.5 Pro
  Yeni liste: **3 Pro, 3 Flash, 2.5 Pro, 2.5 Flash**

## 1.2.7 — "400 yazdım, 400.000 anladı" sayı genişletme hatası

**Belirti:** Kullanıcı "kaç ürün?" sorusuna `400` yazıyor; bot bunu
`400.000` (dört yüz bin) gibi yorumlayıp "oldukça büyük bir sayı" diyor.

**Sebep:** Gemini, Türkçe locale'de `.` karakterini binlik ayraç olarak
biliyor (`30.000 TL` paterninden öğrendiği şekilde). Önceki bağlamda
`30.000 TL` görünce, kullanıcının yazdığı çıplak `400`'ü kendisi
`400.000`'e *normalize* edip yorumluyor.

**Düzeltme — iki katman:**

1. **Mesaj zenginleştirme:** Sayı-adımındaysak ve kullanıcı SIRF bir
   tamsayı yazdıysa (örn. `400`), mesajı şu hale dönüştürüyoruz:
   `400 (ziyaretci tam olarak 400 yazdi; binlik veya milyonluk carpan UYGULAMA, sayiyi oldugu gibi kullan)`
   Gemini bu açık talimat sayesinde 400'ü 400 olarak kullanır.

2. **Sistem prompt'a kalın yazılı kural:**
   - "Kullanıcının yazdığı sayıyı OLDUĞU GİBİ KULLAN. ASLA binlik/milyonluk çarpanla genişletme."
   - "'400' yazıldıysa anlamı 400'dür (dört yüz). 400.000 DEĞİLDİR."
   - "Yanıtında sayıyı tekrar yazarken kullanıcının formatına saygı duy."

Bu kuralın 1.2.3'teki `11..99 → 1..9` kuralıyla aynı mekanizmadan
geldiğini (`is_count_step` + `prepare_user_message`) hatırlatmak gerek;
yalnız bu sefer "çift basma" değil "binlik çarpan ekleme" hatasını
hedefliyor.

## 1.2.6 — E-posta gönderimi sertleştirildi + tanılama paneli

**Belirti:** Konuşma bittikten 1 dk sonra mail gelmesi gerekirken yine
gecikme/eksik gönderim oluyordu.

**Sebepler (üç tane üst üste bindi):**

1. Mailer'da `wp_mail()` BAŞARISIZ olsa bile **transient kilit
   yine ayarlanıyordu** → 5 dakika boyunca tekrar denemeye izin vermiyordu.
   *(1.2.0'da benim açtığım bug, bu sürümde kapandı.)*
2. WP-Cron + 30 sn'lik watchdog ikisi de **site trafiğine bağlı**:
   düşük trafikli sitelerde ziyaretçi tek başına konuşup gittiyse mail
   saatlerce askıda kalabiliyordu.
3. SMTP sağlayıcı yapılandırılmamışsa (paylaşımlı hostlarda yaygın)
   `wp_mail()` sessizce false dönüyordu — kullanıcı sorunu fark edemiyordu.

**Düzeltmeler:**

- **Transient kilit yalnızca BAŞARILI gönderim sonrası** kuruluyor; başarısız
  gönderim bir sonraki tetikte tekrar denenir.
- `wp_mail_failed` action'ı dinleniyor; başarısızlık nedeni
  `marinos_chatbot_mail_failures` option'ına kaydediliyor (son 20 hata).
- **Yeni: Chat AJAX yanıtının ardından `register_shutdown`** ile bekleyenler
  taranıyor. Kullanıcı cevabını anında alır, mail işi response'tan sonra
  yapılır → her sohbet kendi watchdog tikini tetikler, cron'a bağımlı değil.
- **Watchdog ikinci katman:** Pending option'a ek olarak doğrudan veritabanı
  log tablosundan son aktivitesi 60 sn'den eski oturumlar bulunuyor.
  Pending kaydı kaybolsa bile mail gider.
- **Yeni admin paneli — E-posta Tanılama bölümü:**
  - Bekleyen e-posta sayısı (renk kodlu)
  - Son başarılı gönderim zamanı (kaç mesajlık olduğuyla)
  - Son 20 başarısızlığın tablosu (zaman / oturum / hata sebebi)
  - **"Bekleyen E-postaları Şimdi Gönder"** butonu (anlık flush)
  - WP Mail SMTP önerisi linki (eğer hata varsa)
- `marinos_chatbot_last_mail_sent` option'ında son başarılı gönderim
  bilgisi tutuluyor.
- Mail kilit süresi 5 dk → 30 dk (daha güvenli idempotency).

**Pratikte:** Bu sürümle birlikte konuşma bittikten ~60 sn içinde mail
gitmesi yüksek olasılıkla garanti. SMTP düzgün değilse panel artık
kullanıcıya "şurada hata var" gösterir; körü körüne susmaz.

## 1.2.5 — Yarım kalan yanıt (truncation) otomatik tamamlanıyor

**Belirti:** Bot cevapları cümle/kelime ortasında kesiliyordu. Örnek:
"Aylık 50.000 TL bütçe ile 3" (sayıdan sonra yarım kaldı) veya
"Harika, aylık 50.000 TL'lik bir bütç" (kelime ortasında kesildi).

**Kök sebep:** Gemini cevabı, modelin response token bütçesini bitirince yarım
kalır. Proxy de bu kesilmeyi olduğu gibi geri verir.

**Düzeltme — üç katman:**

1. **Geniş token tavanı.** Payload'a `max_tokens`, `max_output_tokens`,
   `output_tokens` (proxy hangi parametre adını okursa okusun) **2048**
   olarak gönderiliyor. Bu çoğu yarım kalmayı en başından engeller.

2. **Truncation detector + auto-continue.** Yanıt geldikten sonra
   `is_truncated_reply()`:
   - Noktalama (`.!?…؟。！？`) ile bitmiyor mu?
   - `ve / veya / or / and / oder / und / и / или` gibi bir bağlaçla mı bitiyor?
   
   Yarım algılanırsa **kullanıcı fark etmeden** Gemini'a ikinci bir istek
   atılıyor (`request_continuation`): geçmişe yarım cevap eklenip "yalnızca
   eksik kalan kısmı yaz, başa dönme" talimatı veriliyor. Dönen iki yanıt
   `stitch_reply()` ile akıllıca birleştiriliyor (kelime ortasında kesilmişse
   boşluksuz yapıştırılıyor: "bütç" + "e ile" → "bütçe ile").

3. **Lokal onarım.** Auto-continue de yanıt vermezse `repair_incomplete_reply()`
   kuyrukta kalan "veya / or / oder" gibi takıları siliyor ve gerekirse `.`
   ekliyor — kullanıcı yine de düzgün biten bir mesaj görür.

**Sistem prompt'una "Tamamlık Kuralı"** otomatik ekleniyor:
"Yanıtını ASLA yarıda bırakma; cümle ortasında, sayıdan veya bağlaçtan sonra
durma; her yanıt bir noktalama ile bitmelidir."

## 1.2.4 — Konuşma saklama süresi yapılandırılabilir oldu

**Belirti:** Ziyaretçi sayfayı yenilese de eski sohbet görünmeye devam ediyordu.
Eski sürümde sabit **24 saat** tutuluyordu (çok uzun).

**Düzeltme:**
- Yeni admin ayarı: **Karşılama Akışı → Konuşma Saklama Süresi (dakika)**.
  Seçenekler: 5 / 10 / 15 / 30 / 60 / 120 / 240 / 720 / 1440 dk.
  **Varsayılan: 30 dakika.**
- Yeni admin ayarı: **Pencere Kapatıldığında Sohbeti Sil** (Evet/Hayır).
  Evet seçilirse ziyaretçi widget'ı küçülttüğü anda `localStorage`
  temizlenir; bir sonraki açılışta sohbet sıfırdan başlar.
- Süre, **son aktivite zamanına** göre işliyor — yani 30 dk hareketsizlik
  geçerse temizlenir. Aktif sohbet süresince saklanır.
- `clearSession()` helper'ı geri eklendi (rewrite sırasında kaybolmuştu;
  şu anki `closeChat()` mantığı buna güveniyor).

## 1.2.3 — "7 yazdım, 77 anladı" sayı çiftlenme hatası

**Belirti:** Bot "Sitenizde kaç sayfa olmasını istersiniz?" diye soruyor,
kullanıcı `7` yazıyor ama bot `77 sayfa` olarak yorumluyor.

**Kök sebepler (ikisi de düzeltildi):**

1. **Frontend çift gönderim:** Mobil klavyede hızlı Enter, klavye `repeat`
   event'i, send-click + Enter yarışı durumlarında "7" iki defa
   gönderilebiliyordu — backend "7\\n7" görüp Gemini'a "77" gibi geçiriyordu.
   - `keydown` Enter'da `e.repeat` kontrolü eklendi.
   - `sendMessage()` başında 400 ms throttle eklendi.
   - `isWaiting` kilidi DOM güncellemelerinden ÖNCE set ediliyor (race kapatıldı).

2. **Sayı-adımı yorumlama:** Backend artık son bot mesajının "kaç sayfa",
   "kaç kişi", "how many", "wie viele", "сколько", "كم", "combien",
   "cuántos" vb. anahtar kelimelerden birini içerip içermediğini
   denetler. İçeriyorsa kullanıcının `11`, `22`, ... `99` şeklinde
   *tek token* cevabı `1, 2, ..., 9` olarak normalize ediliyor. "77 sayfa"
   gibi içinde kelime olan ifadelere dokunulmaz (gerçekten 77 demek isteyen
   kullanıcıyı bozmaz).
3. **Sistem prompt'una sayı-adımı guardrail'i:** "11, 22, ..., 99 gibi
   tekrarlı kısa cevapları 1..9 olarak yorumla" notu otomatik eklendi.

Bu daha önce `60248c2` commit'inde sadece "yolcu sayısı" için yapılmıştı;
şimdi her sayı-bekleyen adıma genelleştirildi.

## 1.2.2 — Magic-quotes / "TL\\'lik" görüntülenme hatası düzeltildi

**Belirti:** Bot bazı cevaplarında `10.000 TL\'lik`, `80.000 TL\'lik` gibi
backslash-escaped metin gösteriyordu.

**Kök sebep:** WordPress, gelen `$_POST` verisine otomatik olarak slash ekler
(magic quotes). `sanitize_*` fonksiyonları bu slash'ları temizlemez. Bu yüzden:

1. Kullanıcı normal `'` yazıyor.
2. Bot da normal `'` ile cevap veriyor.
3. Frontend bu cevabı **bir sonraki turun history'sine** koyup geri POST ediyor.
4. WP magic-quotes apostrofu `\'`ye çeviriyor.
5. Biz `wp_unslash()` çağırmadan Gemini'a yolluyoruz.
6. Gemini geçmişte `TL\'lik` stilini görüyor → bir sonraki cevabında o stili
   taklit ediyor.
7. Kullanıcı ekranda `TL\'lik` görüyor.

**Düzeltme:**
- `class-api.php` — `handle_chat()` ve `handle_flush()` içindeki tüm `$_POST`
  okumalarına `wp_unslash()` eklendi (message, session_id, page_url, lang,
  history dahil).
- `class-visitor.php` — `save()` içindeki tüm `$_POST` okumalarına `wp_unslash()`.
- Defansif önlem: proxy'den dönen yanıtta da `\\'` ve `\\"` kalıntıları
  temizleniyor (proxy başka bir hostta double-escape yaparsa diye).

Bu üçlü onarımdan sonra eski sohbet kayıtlarında `\'` olsa bile **yeni
mesajlar** temiz olarak gidip temiz dönecek. Geçmişteki kirli kayıtların
etkisi yeni oturum açılınca otomatik kaybolur.

## 1.2.1 — Model seçimi geri eklendi (kayıp özellik telafisi)

`a82b6a4` ve `fa1d15e` commit'leriyle eklenmiş olan **admin panel model seçici**
ve **geçmiş normalizasyonu** özellikleri, 1.2.0 baz alınan eski zip'te yoktu —
bu sürümde geri kazandırıldı ve genişletildi.

- **Admin → Ayarlar → Genel → Model:** select kutusu geri geldi. Seçenekler:
  - `gemini-3-flash` (önerilen)
  - `gemini-3-pro`
  - `gemini-2.5-flash`
  - `gemini-2.5-pro`
- **API isteğine `model` ve `model_name` alanları eklendi** — proxy bu değeri
  okuyarak hangi Gemini modeline yönlendireceğini bilir.
- **`Marinos_Chatbot_Api::allowed_models()`** statik yardımcı — proxy'nin desteklediği
  liste değişirse buradan tek noktada güncellenebilir.
- **`normalize_history()`:** ardışık ve aynı içerikteki mesajları birleştirir,
  son 20'yle sınırlar. "Aynı şeyi tekrar tekrar soruyor" şikayetinin sebebi
  buydu — çözüldü.

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
