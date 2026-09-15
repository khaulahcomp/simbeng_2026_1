
## Iterasi Juni 2026 — Simbeng_v1_3 (5 pengembangan)
Deploy target: cPanel shared hosting + MySQL existing (PHP native, konsep tidak diubah).
1. **Sparepart — Pencarian 2 sumber** (`ajax/lookup_hsc.php`, `includes/sync_hsc.php`, `pages/parts.php`)
   - Satu kolom pencarian, hasil GABUNGAN hargasukucadang.online (HTML scrape) + hondacengkareng.com (WooCommerce Store API JSON) di-interleave round-robin. Tiap hasil punya badge sumber + chip filter sumber (data-testid=hsc-source-chip).
   - Klik hasil mengisi Kode/Nama/Kategori; khusus HCG mengisi Harga Jual otomatis (harga_num). part_catalog kini punya kolom `sumber`.
   - "Sync Sekarang" kini menyapu KEDUA sumber (hcg_sync_keywords). Sparepart baru HCG langsung terisi harga_jual.
   - Diverifikasi: HCG live + harga asli, HSC live (~2s), interleave OK, import ke tabel parts OK.
2. **Faktur A4 Landscape** (`pages/receipt.php`)
   - Ganti struk kecil → faktur A4 landscape (@page landscape). Kop & identitas tetap, hanya di-landscape-kan.
   - Tabel item garis all-border: No, Kode, Nama, Jenis, Qty, Harga Satuan, Diskon, Jumlah; footer Total Jasa/Sparepart/Diskon/GRAND TOTAL.
3. **Special Price (Kasir/Servis)** (`pages/pos.php`, `includes/schema.php`)
   - Input manual "Special Price" per baris sparepart (di samping Garansi). Diisi = menggantikan harga normal per unit & terhitung otomatis; kosong = harga normal. Kolom baru transaction_items.special_price.
4. **Kirim Faktur via WA + Unduh JPG** (`pages/receipt.php`)
   - Pesan WA kini memuat RINCIAN item + harga (bukan hanya grand total). Tombol "Unduh Faktur JPG" (html2canvas) untuk dilampirkan manual ke chat WA.
5. **Hapus fitur "Sync Semua Katalog (semua halaman)"** — UI + ajax/sync_hsc_full.php + scripts/sync_hsc_full_cli.php dihapus (mengurangi beban sistem).

Schema version: 2026.06.20.1. Testing agent frontend: 95% (semua fitur berfungsi).

## Iterasi Juni 2026 — Revisi Simbeng_v1_3 (3 perbaikan)
1. **Auto-fill harga KEDUA sumber** (`ajax/lookup_hsc.php`, `pages/parts.php`)
   - Harga HSC (format "260.500") kini diparse numerik (harga_num) seperti HCG, baik hasil live maupun katalog lokal.
   - Klik hasil pencarian mengisi Harga Jual otomatis untuk kedua sumber. Diverifikasi: HSC 3.200.000→3200000, HCG 20500.
2. **Faktur A4 PORTRAIT** (`pages/receipt.php`)
   - @page diganti portrait, margin dirapatkan (7-9mm). Kolom Jenis & Kode dihapus; kode part dipindah inline (teks kecil) di kolom Nama Item.
   - Kolom kini: No, Nama Item, Qty, Harga Satuan, Diskon, Jumlah. Footer colspan disesuaikan (5).
3. **Stempel LUNAS/BELUM LUNAS anti-pemalsuan** (`pages/receipt.php`)
   - Stempel diposisikan absolute menimpa border bawah tabel faktur (top:-9mm), border double, rotasi -7°, opacity 0.88 — layaknya stempel basah. Warna hijau (LUNAS) / merah (BELUM LUNAS) + sub-teks jatuh tempo.
   - Diverifikasi visual via headless chromium.

## Iterasi Juni 2026 — Stempel/Tanda Tangan Gambar di Faktur
- **Upload gambar stempel** (`pages/settings.php`): kartu baru "Stempel / Tanda Tangan Faktur" (admin) — upload/hapus JPG/PNG/WEBP/GIF maks 2MB, tersimpan di setting `stempel` (uploads/stempel_*). Mengikuti pola upload logo.
- **Faktur** (`pages/receipt.php`): gambar stempel tampil menimpa border bawah tabel faktur di area tanda tangan (position absolute, rotasi -6°, opacity .85, object-fit:contain 24mm). Catatan teknis: img absolute wajib width+height eksplisit (width:auto pada img absolute memakai lebar intrinsik → melar).
- Terverifikasi visual: stempel bulat menimpa border GRAND TOTAL + area tanda tangan, bersanding dengan stempel LUNAS.
- User memilih upload stempel sendiri via menu Pengaturan (fitur sudah aktif & terverifikasi). Tidak ada perubahan kode tambahan.
