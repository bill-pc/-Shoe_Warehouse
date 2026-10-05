import sys
import json
import warnings
import os

# Cấu hình biến môi trường: Điều hướng AI tải và lưu Model vào ổ D (Thư mục dự án)
# Tránh phình to ổ C và giúp project hoạt động Offline độc lập
os.environ['SENTENCE_TRANSFORMERS_HOME'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'
os.environ['HUGGINGFACE_HUB_CACHE'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'

# Chặn các log rác của thư viện để Output in ra PHP hoàn toàn sạch (Chỉ chứa JSON)
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
warnings.filterwarnings("ignore")

try:
    from sentence_transformers import SentenceTransformer
    from PIL import Image

    # 1. Nhận đường dẫn ảnh truyền từ PHP
    image_path = sys.argv[1]

    # 2. Khởi tạo mô hình CLIP (Nhận diện hình ảnh không gian vector)
    model = SentenceTransformer('clip-ViT-B-32')

    # 3. Phân tích ảnh và trích xuất thành mảng số thực (Float Array)
    img = Image.open(image_path)
    vector = model.encode(img).tolist()

    # 4. Trả kết quả JSON về cho PHP xử lý
    print(json.dumps({"status": "success", "vector": vector}))

except Exception as e:
    # Trả về thông báo lỗi dạng JSON nếu AI sụp đổ
    print(json.dumps({"status": "error", "message": str(e)}))