import sys
import json
import warnings
import os
from rembg import remove, new_session
from sentence_transformers import SentenceTransformer
from PIL import Image

# Cấu hình biến môi trường: Điều hướng AI tải và lưu Model vào ổ D (Thư mục dự án)
os.environ['SENTENCE_TRANSFORMERS_HOME'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'
os.environ['HUGGINGFACE_HUB_CACHE'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'

# Chặn các log rác của thư viện để Output in ra PHP hoàn toàn sạch (Chỉ chứa JSON)
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
warnings.filterwarnings("ignore")

try:
    # 1. Nhận đường dẫn ảnh truyền từ PHP
    image_path = sys.argv[1]

    # 2. Khởi tạo mô hình CLIP (clip-ViT-B-16) và rembg
    model = SentenceTransformer('clip-ViT-B-16')
    rembg_session = new_session("u2net")

    # 3. Xóa nền bằng rembg và ghép lên nền trắng
    raw_img = Image.open(image_path).convert("RGBA")
    nobg = remove(raw_img, session=rembg_session)
    white_bg = Image.new("RGB", nobg.size, (255, 255, 255))
    white_bg.paste(nobg, mask=nobg.split()[3])

    # Ghi đè ảnh sạch
    ext = os.path.splitext(image_path)[1].lower()
    if ext == '.png':
        white_bg.save(image_path, "PNG")
    else:
        white_bg.save(image_path, "JPEG", quality=95)

    # 4. Phân tích ảnh và trích xuất thành mảng số thực (Float Array)
    vector = model.encode(white_bg).tolist()

    # 5. Trả kết quả JSON về cho PHP xử lý
    print(json.dumps({"status": "success", "vector": vector}))

except Exception as e:
    # Trả về thông báo lỗi dạng JSON nếu AI sụp đổ
    print(json.dumps({"status": "error", "message": str(e)}))
