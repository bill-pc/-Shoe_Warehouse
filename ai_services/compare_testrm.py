"""
Script test so sanh truc tiep giua 2 anh:
- Anh goc : public/img_test/nike1.jpg
- Anh test: public/img_test/testrm.jpg

So sanh 2 truong hop:
- TH1: Ca 2 anh DEU KHONG TACH NEN (Nguyen ban co phong nen cu)
- TH2: Ca 2 anh DEU DA TACH NEN bang rembg (Dat tren nen trang tinh #FFFFFF)
"""

import os
import sys
import time

print("=" * 70)
print("👟 SO SANH: nike1.jpg (Anh goc) VS testrm.jpg (Anh quet)")
print("=" * 70)

try:
    from rembg import remove, new_session
    from PIL import Image
    import numpy as np
    from sentence_transformers import SentenceTransformer
except ImportError as e:
    print(f"❌ Thieu thu vien: {e}")
    print("👉 Vui long chay: pip install rembg sentence-transformers")
    sys.exit(1)

# Duong dan 2 anh
IMG_GOC_PATH = os.path.join("public", "img_test", "nike1.jpg")
IMG_TEST_PATH = os.path.join("public", "img_test", "testrm.jpg")
OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "test_output")
os.makedirs(OUTPUT_DIR, exist_ok=True)

# Kiem tra file
for p in [IMG_GOC_PATH, IMG_TEST_PATH]:
    if not os.path.exists(p):
        print(f"❌ Khong tim thay file: {p}")
        sys.exit(1)

print(f"📌 Anh goc : {IMG_GOC_PATH}")
print(f"📌 Anh test: {IMG_TEST_PATH}")

print("\n⏳ Dang khoi tao rembg (u2net) & CLIP (clip-ViT-B-32)...")
t0 = time.time()
rembg_session = new_session("u2net")
clip_model = SentenceTransformer('clip-ViT-B-32')
print(f"✅ San sang sau {round(time.time() - t0, 2)}s!\n")

def remove_bg_to_white(img_path, output_name):
    """Tach nen bang rembg va ghep vao nen trang #FFFFFF"""
    raw = Image.open(img_path).convert("RGBA")
    nobg = remove(raw, session=rembg_session)
    white_bg = Image.new("RGB", nobg.size, (255, 255, 255))
    white_bg.paste(nobg, mask=nobg.split()[3])
    
    save_path = os.path.join(OUTPUT_DIR, output_name)
    white_bg.save(save_path, "JPEG", quality=95)
    return white_bg, save_path

def cosine_similarity(v1, v2):
    v1 = np.array(v1)
    v2 = np.array(v2)
    return float(np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2))) * 100

# =========================================================================
# TH1: CA 2 ANH DEU KHONG TACH NEN
# =========================================================================
print("--- [TH1] DANG XU LY: CA 2 ANH DEU KHONG TACH NEN ---")
raw_goc = Image.open(IMG_GOC_PATH).convert("RGB")
raw_test = Image.open(IMG_TEST_PATH).convert("RGB")

vec_goc_raw = clip_model.encode(raw_goc)
vec_test_raw = clip_model.encode(raw_test)

score_th1 = cosine_similarity(vec_goc_raw, vec_test_raw)
print(f"👉 Ti le khop TH1 (Chua tach nen): {round(score_th1, 2)}%\n")

# =========================================================================
# TH2: CA 2 ANH DEU DA TACH NEN BANG REMBG
# =========================================================================
print("--- [TH2] DANG XU LY: CA 2 ANH DEU DA TACH NEN (REMBG + NEN TRANG) ---")
print("⏳ Dang tach nen nike1.jpg...")
clean_goc, path_clean_goc = remove_bg_to_white(IMG_GOC_PATH, "nike1_da_tach_nen.jpg")

print("⏳ Dang tach nen testrm.jpg...")
clean_test, path_clean_test = remove_bg_to_white(IMG_TEST_PATH, "testrm_da_tach_nen.jpg")

vec_goc_clean = clip_model.encode(clean_goc)
vec_test_clean = clip_model.encode(clean_test)

score_th2 = cosine_similarity(vec_goc_clean, vec_test_clean)
print(f"👉 Ti le khop TH2 (Da tach nen)  : {round(score_th2, 2)}%\n")

# =========================================================================
# TONG KET KET QUA
# =========================================================================
diff = score_th2 - score_th1
sign = "+" if diff > 0 else ""

print("=" * 70)
print("📊 BANG TONG KET SO SANH:")
print("=" * 70)
print(f"❌ TH1 - Ca 2 DEU KHONG TACH NEN : {round(score_th1, 2)}%")
print(f"✅ TH2 - Ca 2 DEU DA TACH NEN     : {round(score_th2, 2)}%")
print("-" * 70)
print(f"📈 DO CHENH LECH (% Cai thien)    : {sign}{round(diff, 2)}%")
print("=" * 70)

if diff > 0:
    print(f"🎉 KET LUAN: Sau khi tach nen bang rembg, do chinh xac tang them {round(diff, 2)}%!")
    print("   Nho do ma AI nhan dien vuot qua nguong de khop hoan toan / goi y chinh xac.")
else:
    print("ℹ️ KET LUAN: Do lech khong dang ke do 2 anh goc ban dau da it tap am.")

print(f"\n📁 Cac file anh sau khi tach da duoc luu tai: {OUTPUT_DIR}")
print(f"   1. {path_clean_goc}")
print(f"   2. {path_clean_test}")
print("=" * 70)
