from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer
from PIL import Image
import os
import warnings
import logging

# CHẶN TẤT CẢ CÁC DÒNG THÔNG BÁO RÁC ĐỂ TRẢ VỀ JSON SẠCH
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3' 
warnings.filterwarnings("ignore")
logging.getLogger("transformers").setLevel(logging.ERROR)
logging.getLogger("sentence_transformers").setLevel(logging.ERROR)

# Cấu hình đường dẫn (Giữ nguyên ổ D của bồ)
os.environ['SENTENCE_TRANSFORMERS_HOME'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'
os.environ['HUGGINGFACE_HUB_CACHE'] = 'D:/Application/xampp/htdocs/Shoe_Warehouse/ai_services/models'

app = Flask(__name__)

# Nạp model
print("--- AI ENGINE STARTING ---")
model = SentenceTransformer('clip-ViT-B-32')
print("--- AI ENGINE READY ---")

@app.route('/scan', methods=['POST'])
def scan_image():
    data = request.get_json()
    if not data or 'image_path' not in data:
        return jsonify({"status": "error", "message": "No image path"}), 400
        
    image_path = data['image_path']
    if not os.path.exists(image_path):
        return jsonify({"status": "error", "message": "File not found"}), 404

    try:
        # Xử lý ảnh
        img = Image.open(image_path)
        vector = model.encode(img).tolist()
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