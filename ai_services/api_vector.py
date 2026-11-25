from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer
from PIL import Image
import os
import warnings
import logging
from rembg import remove, new_session

# CHẶN TẤT CẢ CÁC DÒNG THÔNG BÁO RÁC ĐỂ TRẢ VỀ JSON SẠCH
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3' 
warnings.filterwarnings("ignore")
logging.getLogger("transformers").setLevel(logging.ERROR)
logging.getLogger("sentence_transformers").setLevel(logging.ERROR)

# Cấu hình đường dẫn 
os.environ['SENTENCE_TRANSFORMERS_HOME'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'
os.environ['HUGGINGFACE_HUB_CACHE'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'

app = Flask(__name__)

# 1. Nạp model CLIP (ViT-B-16 siêu nét, chia 196 patch)
print("--- AI ENGINE STARTING (clip-ViT-B-16) ---")
model = SentenceTransformer('clip-ViT-B-16')

# 2. Khởi tạo và WARM UP model rembg (u2net)
print("--- LOADING & WARMING UP REMBG SESSION ---")
rembg_session = new_session("u2net")
# Chạy thử 1 pixel ảo để model tải xong hoàn toàn vào RAM trước khi bật server
try:
    dummy_img = Image.new("RGB", (16, 16), (255, 255, 255))
    remove(dummy_img, session=rembg_session)
    print("✅ Rembg da khoi dong va nạp vao RAM thanh cong!")
except Exception as e:
    print(f"⚠️ Chu y rembg: {e}")

print("--- AI ENGINE READY (CLIP + REMBG) ---")

def clean_and_remove_background(image_path):
    """
    1. Mở ảnh gốc
    2. Tối ưu kích thước (nếu ảnh chụp quá to 3000-4000px)
    3. Tách nền bằng rembg
    4. Ghép vào nền trắng tinh (#FFFFFF)
    5. Ghi đè ảnh sạch lên đĩa và trả về đối tượng PIL Image
    """
    try:
        raw_img = Image.open(image_path)
        
        # Nếu ảnh chụp quá lớn, thu nhỏ về tối đa 1024px để rembg chạy siêu nhanh (1-2s thay vì 20s)
        MAX_DIM = 1024
        if raw_img.width > MAX_DIM or raw_img.height > MAX_DIM:
            raw_img.thumbnail((MAX_DIM, MAX_DIM), Image.Resampling.LANCZOS)

        raw_img = raw_img.convert("RGBA")
        
        # Tách nền bằng rembg
        nobg = remove(raw_img, session=rembg_session)
        
        # Ghép lên canvas trắng tinh khiết
        white_bg = Image.new("RGB", nobg.size, (255, 255, 255))
        white_bg.paste(nobg, mask=nobg.split()[3])
        
        # Ghi đè ảnh sạch lên đĩa để cả Web và Kho đều dùng ảnh đẹp
        ext = os.path.splitext(image_path)[1].lower()
        if ext == '.png':
            white_bg.save(image_path, "PNG")
        else:
            white_bg.save(image_path, "JPEG", quality=95)
            
        return white_bg
    except Exception as e:
        print(f"⚠️ Cảnh báo rembg lỗi ({str(e)}), dùng ảnh gốc thay thế.")
        return Image.open(image_path).convert("RGB")

@app.route('/scan', methods=['POST'])
def scan_image():
    data = request.get_json()
    if not data or 'image_path' not in data:
        return jsonify({"status": "error", "message": "No image path"}), 400
        
    image_path = data['image_path']
    if not os.path.exists(image_path):
        return jsonify({"status": "error", "message": "File not found"}), 404

    try:
        # Xóa nền bằng rembg và ghép nền trắng trước khi trích xuất vector
        clean_img = clean_and_remove_background(image_path)
        
        # Trích xuất vector từ ảnh đã sạch nền
        vector = model.encode(clean_img).tolist()
        file_name = os.path.basename(image_path)

        # CHỈ TRẢ VỀ JSON SẠCH 100%
        return jsonify({
            "status": "success", 
            "vector": vector,
            "temp_image": file_name
        })
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, debug=False)
