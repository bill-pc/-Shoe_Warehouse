"""
Script Test Chuyen Biet:
So sanh do khop giua:
- ANH GOC : public/img_test/nike1.jpg
- ANH TEST: public/img_test/testrm.jpg
Tren ca 2 mo hinh: 'clip-ViT-B-32' (Hien tai) va 'clip-ViT-B-16' (Moi)
VOI DIEU KIEN: CA 2 ANH DEU DA DUOC TACH NEN REMBG 100%

Cach chay:
    python ai_services/test_compare_models.py
"""

import os
import sys
import time
import warnings

# Chan log rac
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
warnings.filterwarnings("ignore")

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(CURRENT_DIR, "models")
OUTPUT_DIR = os.path.join(CURRENT_DIR, "test_output")
os.makedirs(MODELS_DIR, exist_ok=True)
os.makedirs(OUTPUT_DIR, exist_ok=True)

os.environ['SENTENCE_TRANSFORMERS_HOME'] = MODELS_DIR
os.environ['HUGGINGFACE_HUB_CACHE'] = MODELS_DIR

print("=" * 80)
print("👟 SO SANH: clip-ViT-B-32  VS  clip-ViT-B-16")
print("   - Anh goc : public/img_test/nike1.jpg")
print("   - Anh test: public/img_test/testrm.jpg")
print("   ⭐ CHAC CHAN 100%: CA 2 ANH DEU DUOC TACH NEN REMBG TRUOC KHI TEST!")
print("=" * 80)

try:
    from sentence_transformers import SentenceTransformer
    from PIL import Image
    import numpy as np
except ImportError as e:
    print(f"❌ Thieu thu vien: {e}")
    print("👉 Vui long chay: pip install sentence-transformers pillow numpy")
    sys.exit(1)

# Thu vien rembg
try:
    from rembg import remove, new_session
    has_rembg = True
except ImportError:
    has_rembg = False
    print("❌ CHUA CO THU VIEN REMBG! Vui long chay: pip install rembg")
    sys.exit(1)

# 1. Duong dan 2 anh
IMG_GOC = os.path.abspath(os.path.join(CURRENT_DIR, "..", "public", "img_test", "jordan2.jpg"))
IMG_TEST = os.path.abspath(os.path.join(CURRENT_DIR, "..", "public", "img_test", "3a40cf4ac1ba1571562af7a761242f21813af1d5-1200x735.jpg"))

if len(sys.argv) >= 3:
    IMG_GOC = sys.argv[1]
    IMG_TEST = sys.argv[2]

for p in [IMG_GOC, IMG_TEST]:
    if not os.path.exists(p):
        print(f"❌ Khong tim thay file anh tai: {p}")
        sys.exit(1)

print(f"📌 [Anh goc ] : {IMG_GOC}")
print(f"📌 [Anh test]: {IMG_TEST}")
print("-" * 80)

# 2. Khoi tao model
print("⏳ [1/3] Dang nap phien rembg (u2net)...")
rembg_session = new_session("u2net")
print("   ✅ rembg san sang!")

print("⏳ [2/3] Dang nap Model: clip-ViT-B-32 ...")
t0 = time.time()
model_b32 = SentenceTransformer('clip-ViT-B-32')
print(f"   ✅ clip-ViT-B-32 san sang ({round(time.time() - t0, 2)}s)")

print("⏳ [3/3] Dang nap Model: clip-ViT-B-16 ...")
print("   (Neu chua co, he thong se tu dong tai model ~590MB vao thu muc models)")
t1 = time.time()
model_b16 = SentenceTransformer('clip-ViT-B-16')
print(f"   ✅ clip-ViT-B-16 san sang ({round(time.time() - t1, 2)}s)")
print("-" * 80)

def remove_bg_and_white_canvas(img_path, save_filename):
    """
    Tach nen bang rembg va ghep vao nen trang tinh #FFFFFF
    Luu anh vao thu muc test_output de nguoi dung xem tan mat
    """
    img = Image.open(img_path)
    if img.width > 1024 or img.height > 1024:
        img.thumbnail((1024, 1024), Image.Resampling.LANCZOS)
        
    raw_rgba = img.convert("RGBA")
    nobg = remove(raw_rgba, session=rembg_session)
    
    # Canvas trang tinh khiet #FFFFFF
    white_bg = Image.new("RGB", nobg.size, (255, 255, 255))
    white_bg.paste(nobg, mask=nobg.split()[3])
    
    # Luu file anh ra de kiem tra
    save_path = os.path.join(OUTPUT_DIR, save_filename)
    white_bg.save(save_path, "JPEG", quality=95)
    
    return white_bg, save_path

def cosine_similarity(v1, v2):
    v1 = np.array(v1)
    v2 = np.array(v2)
    return float(np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2))) * 100

# =========================================================================
# GIAI DOAN 1: TACH NEN 100% CHO CA 2 ANH TRUOC
# =========================================================================
print("\n🔥 GIAI DOAN 1: THUC HIEN TACH NEN REMBG CHO CA 2 ANH:")
print("   1. Dang tach nen anh goc (nike1.jpg)...")
clean_goc, path_goc_saved = remove_bg_and_white_canvas(IMG_GOC, "clean_nike1.jpg")
print(f"      👉 Da tach nen xong va luu tai: {path_goc_saved}")

print("   2. Dang tach nen anh test (testrm.jpg)...")
clean_test, path_test_saved = remove_bg_and_white_canvas(IMG_TEST, "clean_testrm.jpg")
print(f"      👉 Da tach nen xong va luu tai: {path_test_saved}")

print("\n" + "=" * 80)
print("🎯 GIAI DOAN 2: TEST SO SANH DO KHOP TREN ANH DA TACH NEN CUA CA 2 MODEL")
print("   (Dung chinh xac 2 anh da tach nen tren de truyen vao ca ViT-B-32 va ViT-B-16)")
print("=" * 80)

# A. Chay tren Model ViT-B-32
t_b32_start = time.time()
vec_goc_b32 = model_b32.encode(clean_goc)
vec_test_b32 = model_b32.encode(clean_test)
time_b32 = round((time.time() - t_b32_start) * 1000 / 2, 2)
score_b32 = cosine_similarity(vec_goc_b32, vec_test_b32)

# B. Chay tren Model ViT-B-16
t_b16_start = time.time()
vec_goc_b16 = model_b16.encode(clean_goc)
vec_test_b16 = model_b16.encode(clean_test)
time_b16 = round((time.time() - t_b16_start) * 1000 / 2, 2)
score_b16 = cosine_similarity(vec_goc_b16, vec_test_b16)

diff = score_b16 - score_b32
sign = "+" if diff > 0 else ""

print(f"\n📊 KET QUA KHI CA 2 ANH DEU DA TACH NEN:")
print(f"   🔸 Model ViT-B-32 (Hien tai) : {round(score_b32, 2)}%   (Vector: {len(vec_goc_b32)} chieu, ~{time_b32}ms/anh)")
print(f"   🔹 Model ViT-B-16 (Moi)      : {round(score_b16, 2)}%   (Vector: {len(vec_goc_b16)} chieu, ~{time_b16}ms/anh)")
print(f"   ------------------------------------------------------------------------")
print(f"   📈 MUC CHENH LECH (B-16 - B-32) : {sign}{round(diff, 2)}%")
print("=" * 80)

# =========================================================================
# GIAI DOAN 3: DOI CHIEU PHU (NEU KHONG TACH NEN)
# =========================================================================
print("\nℹ️  DOI CHIEU THAM KHAO (NEU DE ANH NGUYEN BAN KHONG TACH NEN):")
raw_goc = Image.open(IMG_GOC).convert("RGB")
raw_test = Image.open(IMG_TEST).convert("RGB")

raw_score_b32 = cosine_similarity(model_b32.encode(raw_goc), model_b32.encode(raw_test))
raw_score_b16 = cosine_similarity(model_b16.encode(raw_goc), model_b16.encode(raw_test))
print(f"   - ViT-B-32 chua tach nen: {round(raw_score_b32, 2)}%")
print(f"   - ViT-B-16 chua tach nen: {round(raw_score_b16, 2)}%")

# =========================================================================
# TONG KET
# =========================================================================
print("\n" + "=" * 80)
print("🏆 BANG TONG HOP SO SANH HOAN CHINH:")
print("=" * 80)
print(f"{'Trang thai anh':<32} | {'clip-ViT-B-32':<18} | {'clip-ViT-B-16':<18} | {'Chenh lech'}")
print(f"{'-'*32} | {'-'*18} | {'-'*18} | {'-'*12}")
print(f"{'⭐ DA TACH NEN REMBG (Chinh)':<32} | {f'{round(score_b32, 2)}%':<18} | {f'{round(score_b16, 2)}%':<18} | {f'{sign}{round(diff, 2)}%'}")
print(f"{'   CHUA TACH NEN (Tham khao)':<32} | {f'{round(raw_score_b32, 2)}%':<18} | {f'{round(raw_score_b16, 2)}%':<18} | {f'{"+" if (raw_score_b16 - raw_score_b32) > 0 else ""}{round(raw_score_b16 - raw_score_b32, 2)}%'}")
print("=" * 80)
print(f"\n📁 Ban co the mo thu muc sau de kiem tra 2 anh da tach nen:")
print(f"   {OUTPUT_DIR}\\clean_nike1.jpg")
print(f"   {OUTPUT_DIR}\\clean_testrm.jpg")
print("=" * 80)
