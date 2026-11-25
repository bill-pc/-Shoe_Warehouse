"""
Script Test Doc Lap: Kiem tra do hieu qua cua rembg truoc khi ap dung vao he thong chinh.
Cach chay:
    python ai_services/test_rembg.py
hoac:
    python ai_services/test_rembg.py "duong_dan_anh_1.jpg"
hoac (so sanh 2 anh truoc va sau khi xoa nen):
    python ai_services/test_rembg.py "anh_goc_manager.jpg" "anh_nhan_vien_chup.jpg"
"""

import sys
import os
import time

print("=" * 60)
print("🚀 BAT DAU KIEM TRA TEST REMBG + CLIP VECTOR")
print("=" * 60)

# 1. Kiem tra thu vien rembg
try:
    from rembg import remove, new_session
    from PIL import Image
    import numpy as np
    print("✅ Thu vien rembg & Pillow da san sang!")
except ImportError as e:
    print("❌ CHUA CAI DAT REMBG!")
    print("👉 Vui long mo Terminal/CMD va chay lenh sau truoc:")
    print("   pip install rembg")
    sys.exit(1)

# 2. Kiem tra thu vien CLIP
try:
    from sentence_transformers import SentenceTransformer
    print("✅ Thu vien sentence_transformers da san sang!")
except ImportError:
    print("❌ CHUA CAI DAT SENTENCE_TRANSFORMERS!")
    print("👉 Vui long chay: pip install sentence-transformers")
    sys.exit(1)

# Thu muc luu ket qua test
OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "test_output")
os.makedirs(OUTPUT_DIR, exist_ok=True)

# Khoi tao session
print("\n⏳ Dang tai model rembg (u2net) va CLIP (clip-ViT-B-32)...")
start_init = time.time()
rembg_session = new_session("u2net")
clip_model = SentenceTransformer('clip-ViT-B-32')
print(f"✅ Tai model thanh cong trong {round(time.time() - start_init, 2)} giay!\n")

def process_image_with_rembg(image_path, prefix="test"):
    """
    Xoa phong nen anh va ghep vao nen trang chuan cho CLIP
    """
    print(f"📸 Dang xu ly anh: {image_path}")
    raw_img = Image.open(image_path).convert("RGBA")
    
    t0 = time.time()
    # 1. Xoa nen
    nobg = remove(raw_img, session=rembg_session)
    rembg_time = round(time.time() - t0, 3)
    
    # 2. Luu file PNG nen trong suot de xem
    base_name = os.path.splitext(os.path.basename(image_path))[0]
    transparent_path = os.path.join(OUTPUT_DIR, f"{prefix}_{base_name}_transparent.png")
    nobg.save(transparent_path, "PNG")
    
    # 3. Ghep vao nen trang tinh (#FFFFFF) cho CLIP
    white_bg = Image.new("RGB", nobg.size, (255, 255, 255))
    white_bg.paste(nobg, mask=nobg.split()[3])
    
    # 4. Luu file JPG nen trang de xem
    white_path = os.path.join(OUTPUT_DIR, f"{prefix}_{base_name}_white_bg.jpg")
    white_bg.save(white_path, "JPEG", quality=95)
    
    print(f"   ⏱️ Thoi gian xoa nen: {rembg_time}s")
    print(f"   💾 Da luu anh trong suot tai: {transparent_path}")
    print(f"   💾 Da luu anh nen trang tai : {white_path}")
    
    return raw_img.convert("RGB"), white_bg

def cosine_similarity(v1, v2):
    v1 = np.array(v1)
    v2 = np.array(v2)
    return float(np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2)))

# --- XU LY CAC DOI SO DAU VAO ---
args = sys.argv[1:]

if len(args) == 0:
    # Tu dong lay 1 anh mau trong project de test
    candidate_dirs = [
        os.path.join("public", "img_test"),
        os.path.join("public", "assets", "img_product")
    ]
    found_sample = None
    for cdir in candidate_dirs:
        if os.path.exists(cdir):
            for fname in os.listdir(cdir):
                if fname.lower().endswith(('.jpg', '.jpeg', '.png')):
                    found_sample = os.path.join(cdir, fname)
                    break
        if found_sample:
            break
            
    if not found_sample:
        print("❌ Khong tim thay anh mau tu dong. Vui long truyen duong dan anh vao lenh:")
        print("   python ai_services/test_rembg.py \"duong_dan_anh.jpg\"")
        sys.exit(0)
    args = [found_sample]

if len(args) == 1:
    img_path = args[0]
    if not os.path.exists(img_path):
        print(f"❌ File khong ton tai: {img_path}")
        sys.exit(1)
        
    orig_img, clean_img = process_image_with_rembg(img_path, prefix="single")
    
    # Trich xuat vector
    t1 = time.time()
    vector = clip_model.encode(clean_img).tolist()
    print(f"\n🔢 Trich xuat Vector thanh cong!")
    print(f"   - Do dai Vector: {len(vector)} chieu")
    print(f"   - Thoi gian trich xuat: {round(time.time() - t1, 3)}s")
    print(f"   - 5 phan tu dau: {vector[:5]}")
    print("\n🎉 TEST HOAN TAT! Ban hay mo thu muc 'ai_services/test_output' de kiem tra chat luong anh sau khi xoa nen.")

elif len(args) >= 2:
    img1_path = args[0]
    img2_path = args[1]
    
    print("\n--- SO SANH DO TUONG DONG 2 ANH (TRUOC VS SAU KHI XOA NEN) ---")
    orig1, clean1 = process_image_with_rembg(img1_path, prefix="img1")
    orig2, clean2 = process_image_with_rembg(img2_path, prefix="img2")
    
    # 1. Tinh do tuong dong KHI CHUA XOA NEN
    vec_orig1 = clip_model.encode(orig1)
    vec_orig2 = clip_model.encode(orig2)
    score_before = cosine_similarity(vec_orig1, vec_orig2) * 100
    
    # 2. Tinh do tuong dong KHI DA XOA NEN (NEN TRANG)
    vec_clean1 = clip_model.encode(clean1)
    vec_clean2 = clip_model.encode(clean2)
    score_after = cosine_similarity(vec_clean1, vec_clean2) * 100
    
    print("\n" + "=" * 60)
    print("📊 KET QUA SO SANH:")
    print(f"   🔴 Truoc khi xoa nen (Anh goc co phong nen tap): {round(score_before, 2)}%")
    print(f"   🟢 Sau khi xoa nen bang rembg (Chi giu doi giay): {round(score_after, 2)}%")
    diff = round(score_after - score_before, 2)
    sign = "+" if diff > 0 else ""
    print(f"   📈 Muc do cai thien: {sign}{diff}%")
    print("=" * 60)
    print("👉 Ban co the mo thu muc 'ai_services/test_output' de xem cac anh da qua xu ly!")
